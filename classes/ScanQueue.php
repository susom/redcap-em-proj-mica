<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ScanQueueStoreInterface.php";
require_once __DIR__ . "/ScanJobStateMachine.php";
require_once __DIR__ . "/TranscriptException.php";

/**
 * The scan queue: enqueue once per finalized transcript, hand jobs to one worker at a time, and
 * make sure nothing gets stuck.
 *
 * Two properties this class is responsible for, both of which are silent when broken:
 *
 *   **Enqueue is idempotent.** `completeSession` can be called twice - a double-click, a retried
 *   request, a participant reloading the page - and the second call must not produce a second scan
 *   of the same transcript. That would be two RA queue entries for one session, which reads as two
 *   sessions. The guarantee is the UNIQUE index on idempotency_key, not a check-then-insert: the
 *   check is a fast path, the index is the actual constraint, and a duplicate-key result is treated
 *   as success.
 *
 *   **A claim is exclusive.** One atomic UPDATE marks and selects in the same statement, so two
 *   concurrent workers cannot both take the same job.
 */
class ScanQueue
{
    private ScanQueueStoreInterface $store;
    private ScanJobStateMachine $states;
    /** @var callable(): int */
    private $clock;
    /** @var callable(string): void */
    private $logger;

    /**
     * @param callable(): int|null       $clock  epoch seconds; injected so backoff is testable
     * @param callable(string): void|null $logger
     */
    public function __construct(
        ScanQueueStoreInterface $store,
        ScanJobStateMachine $states,
        ?callable $clock = null,
        ?callable $logger = null
    ) {
        $this->store = $store;
        $this->states = $states;
        $this->clock = $clock ?? static fn(): int => time();
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    /**
     * The key that makes one finalized transcript scannable exactly once.
     *
     * `instance` and `version` are both included, beyond 02-data-model.md's original four parts:
     *
     *   instance - the session instruments are repeating (§3, note of 2026-08-17), so without it two
     *              sessions in the same window collide and the second is dropped as a duplicate.
     *   version  - a refinalize must produce a NEW job (stage-3 §3.3.6). If a correction happens to
     *              yield byte-identical content, the transcript hash is identical too, and without
     *              the version the "duplicate is success" rule would silently enqueue nothing -
     *              the admin would see the correction accepted and no rescan.
     */
    public static function idempotencyKey(
        string $projectId,
        string $record,
        string $sessionType,
        int $instance,
        int $version,
        string $transcriptSha256
    ): string {
        return hash('sha256', implode('|', [
            $projectId,
            $record,
            $sessionType,
            $instance,
            $version,
            $transcriptSha256,
        ]));
    }

    /**
     * Queue a scan for a finalized transcript. Safe to call repeatedly for the same transcript.
     *
     * @return array{jobId:int,created:bool} `created:false` means an identical job already existed
     */
    public function enqueue(
        string $projectId,
        string $record,
        int $instance,
        string $sessionType,
        int $transcriptLogId,
        string $transcriptSha256,
        int $version = 1
    ): array {
        $key = self::idempotencyKey(
            $projectId,
            $record,
            $sessionType,
            $instance,
            $version,
            $transcriptSha256
        );

        // Fast path. Not the guarantee - see below.
        $existing = $this->store->findByIdempotencyKey($key);
        if ($existing !== null) {
            $this->log("scan already queued for transcript T$transcriptLogId (job {$existing['id']})");
            return ['jobId' => (int) $existing['id'], 'created' => false];
        }

        $now = ($this->clock)();

        $id = $this->store->insertJob([
            'project_id'      => $projectId,
            'record'          => $record,
            'instance'        => $instance,
            'session_type'    => $sessionType,
            'transcript_ref'  => $transcriptLogId,
            'idempotency_key' => $key,
            'status'          => ScanJobStateMachine::QUEUED,
            'attempts'        => 0,
            // Due immediately. The worker's `next_attempt_at <= now` filter needs a real value, not
            // a null, or a brand-new job would never be picked up.
            'next_attempt_at' => $now,
        ]);

        if ($id === null) {
            // Lost the race between the check above and this insert. The UNIQUE index is what
            // actually enforces this, which is why the fast path is not trusted on its own.
            $raced = $this->store->findByIdempotencyKey($key);

            if ($raced === null) {
                // A duplicate-key rejection with no matching row means the insert failed for some
                // other reason. Reporting success here would lose the scan entirely and silently -
                // the participant's session would look finalized with nothing ever queued.
                throw new TranscriptException(
                    "Could not queue a scan for transcript T$transcriptLogId, and no existing job "
                    . 'matches its idempotency key. The transcript is finalized but unscanned; this '
                    . 'needs staff attention rather than a retry.'
                );
            }

            $this->log("lost the enqueue race for T$transcriptLogId; job {$raced['id']} stands");
            return ['jobId' => (int) $raced['id'], 'created' => false];
        }

        $this->log("queued scan job $id for transcript T$transcriptLogId ($sessionType)");

        return ['jobId' => $id, 'created' => true];
    }

    /**
     * Take the next due job, or null if there is nothing to do.
     *
     * @return array<string,mixed>|null
     */
    public function claimNext(string $claimToken): ?array
    {
        return $this->store->claim($claimToken, ($this->clock)());
    }

    /**
     * Record how a scan attempt ended and move the job accordingly.
     *
     * @param array<string,mixed> $job the claimed row
     * @return array{status:string,retryInSeconds:?int,reason:string}
     */
    public function finishAttempt(array $job, string $runStatus, ?string $error = null): array
    {
        $attempts = ((int) ($job['attempts'] ?? 0)) + 1;
        $outcome = $this->states->afterAttempt((string) $job['status'], $runStatus, $attempts);
        $now = ($this->clock)();

        $this->store->updateJob((int) $job['id'], [
            'status'          => $outcome['status'],
            'attempts'        => $attempts,
            'next_attempt_at' => $now + ($outcome['retryInSeconds'] ?? 0),
            // Cleared so a re-queued job is claimable again, and so a finished job does not look
            // like it is still being worked on.
            'claimed_by'      => null,
            'claimed_at'      => null,
            'last_error'      => $error,
        ]);

        $this->log(sprintf(
            'job %s attempt %d -> %s (%s)',
            $job['id'],
            $attempts,
            $outcome['status'],
            $outcome['reason']
        ));

        return $outcome;
    }

    /**
     * Return jobs abandoned by dead workers to the queue.
     *
     * Without this a killed worker leaves its job in `scanning` forever: no retry, no failure, no
     * review task - the one outcome nobody notices, because the dashboard shows work in progress.
     *
     * @return list<array{jobId:int,status:string,reason:string}>
     */
    public function reapStaleClaims(int $limit = 20): array
    {
        $now = ($this->clock)();
        $cutoff = $now - ScanJobStateMachine::STALE_CLAIM_SECONDS;
        $reaped = [];

        foreach ($this->store->findStaleClaims($cutoff, $limit) as $job) {
            $claimedAt = isset($job['claimed_at']) ? (int) $job['claimed_at'] : null;

            if (!$this->states->isClaimStale((string) $job['status'], $claimedAt, $now)) {
                continue;
            }

            $attempts = ((int) ($job['attempts'] ?? 0)) + 1;
            $outcome = $this->states->afterStaleClaim($attempts);

            $this->store->updateJob((int) $job['id'], [
                'status'          => $outcome['status'],
                'attempts'        => $attempts,
                'next_attempt_at' => $now + ($outcome['retryInSeconds'] ?? 0),
                'claimed_by'      => null,
                'claimed_at'      => null,
                'last_error'      => 'claim went stale: ' . $outcome['reason'],
            ]);

            $this->log("reaped stale claim on job {$job['id']} -> {$outcome['status']}");

            $reaped[] = [
                'jobId'  => (int) $job['id'],
                'status' => $outcome['status'],
                'reason' => $outcome['reason'],
            ];
        }

        return $reaped;
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
