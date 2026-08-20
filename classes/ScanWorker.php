<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ScanQueue.php";
require_once __DIR__ . "/ScanJobStateMachine.php";

/**
 * The cron pass: reap what died, then claim and run a bounded batch of scans.
 *
 * Stage 3 ships the loop with no model call in it. `ScanRunner` (Stage 4) becomes the thing this
 * hands each job to; until then the runner is injected, so the loop's behaviour - claim, run, record
 * the outcome, back off, give up visibly - is complete and tested independently of the model.
 *
 * ## Bounded on purpose
 *
 * A batch limit and a wall-clock budget, both enforced, because a cron that runs every minute and
 * can overrun its own interval stacks workers, and two workers is the situation the atomic claim
 * exists to survive rather than one to create. The budget is checked *before* each claim rather than
 * after: abandoning a claimed job mid-scan is what produces stale claims.
 *
 * ## Reaping goes first
 *
 * A job abandoned by a dead worker sits in `scanning` forever - no retry, no failure, no review task
 * - and the dashboard shows it as work in progress, which is the one failure nobody notices. So
 * reaping happens first, unconditionally, before any of the pass's time budget is spent on new work.
 *
 * A reaped job is *not* re-claimed in the same pass: afterStaleClaim() gives it a backoff, so
 * something that just killed a worker is not immediately handed to another one. It becomes claimable
 * on a later pass, and if it keeps killing workers it exhausts its attempts and lands in
 * manual_review_required like any other failure.
 */
class ScanWorker
{
    /** Jobs per pass. Small: the cron runs every minute, and a long pass risks overlap. */
    public const DEFAULT_BATCH = 5;

    /** Leaves margin under a 300 s cron_max_run_time for the reap and for a slow final scan. */
    public const DEFAULT_BUDGET_SECONDS = 240;

    private ScanQueue $queue;
    /** @var callable(array): array{runStatus:string,error:?string} */
    private $runner;
    /** @var callable(): int */
    private $clock;
    /** @var callable(string): void */
    private $logger;
    /** @var callable(): string */
    private $tokenFactory;

    /**
     * @param callable(array): array{runStatus:string,error:?string} $runner
     *        Given a claimed job, performs one scan attempt and reports how it ended. Stage 4
     *        supplies ScanRunner; Stage 3's default reports `service_error`, which is honest: no
     *        scanner is configured, and the job must not look scanned.
     * @param callable(): string|null $tokenFactory injected so a test can pin the claim token
     */
    public function __construct(
        ScanQueue $queue,
        ?callable $runner = null,
        ?callable $clock = null,
        ?callable $logger = null,
        ?callable $tokenFactory = null
    ) {
        $this->queue = $queue;
        $this->runner = $runner ?? static fn(array $job): array => [
            'runStatus' => 'service_error',
            'error'     => 'No SafetyScan runner is configured on this installation (Stage 4 '
                         . 'supplies it). The transcript is queued and will be scanned once a '
                         . 'runner and model alias are configured; it is NOT a clean screen.',
        ];
        $this->clock = $clock ?? static fn(): int => time();
        $this->logger = $logger ?? static function (string $m): void {
        };
        $this->tokenFactory = $tokenFactory ?? static fn(): string => bin2hex(random_bytes(16));
    }

    /**
     * @return array{reaped:int,claimed:int,outcomes:array<string,int>,stoppedEarly:bool}
     */
    public function runPass(int $batch = self::DEFAULT_BATCH, int $budgetSeconds = self::DEFAULT_BUDGET_SECONDS): array
    {
        $startedAt = ($this->clock)();
        $reaped = $this->queue->reapStaleClaims();

        if ($reaped !== []) {
            $this->log(sprintf('reaped %d stale claim(s)', count($reaped)));
        }

        $claimed = 0;
        $outcomes = [];
        $stoppedEarly = false;

        while ($claimed < max(1, $batch)) {
            // Before the claim, not after. A worker that claims a job and then runs out of budget
            // leaves it in `scanning` for the reaper - manufacturing the exact problem the reaper
            // exists to clean up.
            if ((($this->clock)() - $startedAt) >= $budgetSeconds) {
                $stoppedEarly = true;
                $this->log('stopping this pass: out of time budget, claiming nothing further');
                break;
            }

            $job = $this->queue->claimNext(($this->tokenFactory)());
            if ($job === null) {
                break;
            }

            $claimed++;
            $outcome = $this->runOne($job);
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        if ($claimed > 0 || $reaped !== []) {
            $this->log(sprintf(
                'pass complete: %d reaped, %d claimed, outcomes %s%s',
                count($reaped),
                $claimed,
                json_encode($outcomes),
                $stoppedEarly ? ' (stopped on budget)' : ''
            ));
        }

        return [
            'reaped'       => count($reaped),
            'claimed'      => $claimed,
            'outcomes'     => $outcomes,
            'stoppedEarly' => $stoppedEarly,
        ];
    }

    /** @param array<string,mixed> $job */
    private function runOne(array $job): string
    {
        try {
            $result = ($this->runner)($job);
            $runStatus = (string) ($result['runStatus'] ?? 'service_error');
            $error = $result['error'] ?? null;
        } catch (\Throwable $e) {
            // A runner that throws must not leave the job claimed. `service_error` is the transient
            // class, so it retries and then gives up visibly - which is right for an unexpected
            // fault, and is emphatically not "nothing found".
            $runStatus = 'service_error';
            $error = get_class($e) . ': ' . $e->getMessage();
            $this->log("job {$job['id']} runner threw: $error");
        }

        try {
            $this->queue->finishAttempt($job, $runStatus, $error === null ? null : (string) $error);
        } catch (\Throwable $e) {
            // The bookkeeping itself failed, so the job is still marked `scanning`. Nothing to do
            // here but say so loudly; the reaper will pick it up, which is the correct recovery.
            $this->log(
                "job {$job['id']} finished as $runStatus but its status could not be recorded: "
                . $e->getMessage() . ' - the stale-claim reaper will requeue it'
            );
        }

        return $runStatus;
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
