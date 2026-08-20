<?php

namespace Stanford\MICA;

require_once __DIR__ . "/EntityTypes.php";

/**
 * The scan queue's legal moves, as a pure function of (status, outcome, attempts).
 *
 * Split out from the worker because this is where the handoff's one non-negotiable rule lives:
 * **a scan that did not happen must never be indistinguishable from a scan that found nothing.**
 * "A scan timeout, refusal, invalid JSON, unavailable service, or citation mismatch creates a
 * visible `manual_review_required` task and is never treated as a negative screen."
 *
 * Two structural consequences, both asserted in the tests:
 *
 *   - There is **no edge into `scan_failed`.** The status exists in the entity's choices for parity
 *     with 02-data-model.md, but nothing here can reach it, because a terminal state that means
 *     "the scan did not happen" *and* raises no review task is exactly the silent negative screen
 *     the handoff forbids. Every give-up lands in `manual_review_required`, which is a visible task
 *     an RA has to dispose of.
 *   - `ready_for_review` is reachable **only** from a successful scan. A failure cannot reach it by
 *     any path, at any attempt count.
 */
class ScanJobStateMachine
{
    public const QUEUED                 = 'queued';
    public const SCANNING               = 'scanning';
    public const READY_FOR_REVIEW       = 'ready_for_review';
    public const UNDER_REVIEW           = 'under_review';
    public const REVIEW_COMPLETE        = 'review_complete';
    public const MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    /**
     * Present in the entity choices, unreachable here. See the class comment - keeping it in the
     * enum means a value already in the database validates; refusing to transition into it means
     * this code never creates one.
     */
    public const UNREACHABLE = 'scan_failed';

    public const DEFAULT_MAX_ATTEMPTS = 3;

    /** First retry after a minute, then 4x each time: 60s, 240s, 960s. */
    public const BACKOFF_BASE_SECONDS = 60;
    public const BACKOFF_MULTIPLIER = 4;
    public const BACKOFF_CAP_SECONDS = 3600;

    /**
     * A claim older than this is assumed dead - the worker was killed, the request timed out, the
     * container restarted. The job goes back to `queued` and the attempt is counted, so a job that
     * kills its worker every time still exhausts rather than looping forever.
     */
    public const STALE_CLAIM_SECONDS = 900;

    /**
     * Failure classes worth another attempt: the fault is plausibly in the transport or in one
     * sampling of the model, so the same request may well succeed.
     */
    public const TRANSIENT = ['timeout', 'service_error', 'invalid_json', 'schema_invalid'];

    /**
     * Failure classes where a retry is not a fix, so it goes straight to a human:
     *
     *   refusal           the model declined; asking again the same way gets the same answer.
     *   content_filter    the provider blocked it. A retry loops against a policy, not a fault.
     *   citation_mismatch the model quoted something the transcript does not contain. Releasing
     *                     findings requires 100% quote traceability, so a scanner that fabricated
     *                     evidence does not get a second chance at automatic release - the whole
     *                     scan goes to manual review.
     */
    public const TERMINAL = ['refusal', 'content_filter', 'citation_mismatch'];

    private int $maxAttempts;

    public function __construct(int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS)
    {
        // 0 or negative would mean "never scan", which is not a configuration anyone means.
        $this->maxAttempts = max(1, $maxAttempts);
    }

    /** @return string[] every status a job can hold */
    public static function statuses(): array
    {
        return array_keys(EntityTypes::jobStatusChoices());
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /** A queued job whose time has come may be claimed. */
    public function canClaim(string $status): bool
    {
        return $status === self::QUEUED;
    }

    /**
     * Where a job goes when its scan attempt finishes.
     *
     * @param string $status     current status; must be SCANNING
     * @param string $runStatus  one of EntityTypes::runStatusChoices()
     * @param int    $attempts   attempts *including* the one that just finished
     * @param bool   $terminal   the runner says do not retry, whatever the taxonomy says
     * @return array{status:string,retryInSeconds:?int,reason:string}
     */
    public function afterAttempt(
        string $status,
        string $runStatus,
        int $attempts,
        bool $terminal = false
    ): array {
        $outcome = $this->classify($status, $runStatus, $attempts);

        /**
         * The runner can veto a retry for a failure the taxonomy classifies as transient.
         *
         * The case it exists for: the model answered and verified, and the findings could not be
         * stored because the review instrument does not exist. Retrying re-pays for the same model
         * call against a fault that cannot resolve between attempts.
         *
         * Applied here rather than at the call site because there is more than one call site - the
         * queue, which persists the transition, and the scan worker, which decides whether to write a
         * placeholder and whether to notify. When the override lived in the queue alone, those two
         * disagreed: a terminal-but-transient outcome was persisted as manual_review_required and got
         * its placeholder, but the notifier saw `queued` and told nobody. One derivation means they
         * agree by construction instead of by matching comments.
         *
         * It can only ever make a retryable outcome terminal, never the reverse - so it cannot be
         * used to suppress a review.
         */
        if ($terminal && $outcome['status'] === self::QUEUED) {
            return [
                'status'         => self::MANUAL_REVIEW_REQUIRED,
                'retryInSeconds' => null,
                'reason'         => $outcome['reason'] . ' - but the runner reported it as not '
                                  . 'retryable, so it goes to a human now instead of re-calling '
                                  . 'the model for a fault that cannot fix itself',
            ];
        }

        return $outcome;
    }

    /** @return array{status:string,retryInSeconds:?int,reason:string} */
    private function classify(string $status, string $runStatus, int $attempts): array
    {
        if ($status !== self::SCANNING) {
            throw new \LogicException(
                "A scan attempt finished for a job in status '$status'. Only '" . self::SCANNING
                . "' jobs can be running, so this is a lost-claim or double-run bug, not a "
                . 'condition to recover from.'
            );
        }

        if ($runStatus === 'ok') {
            return [
                'status'         => self::READY_FOR_REVIEW,
                'retryInSeconds' => null,
                'reason'         => 'scan completed and its findings were verified',
            ];
        }

        if (in_array($runStatus, self::TERMINAL, true)) {
            return [
                'status'         => self::MANUAL_REVIEW_REQUIRED,
                'retryInSeconds' => null,
                'reason'         => "$runStatus is not fixed by retrying; a human has to look at this",
            ];
        }

        if (!in_array($runStatus, self::TRANSIENT, true)) {
            // An unclassified failure is not assumed transient. Guessing "retry" for something we
            // do not recognise risks looping; guessing "fine" would be a negative screen.
            return [
                'status'         => self::MANUAL_REVIEW_REQUIRED,
                'retryInSeconds' => null,
                'reason'         => "unrecognised run status '$runStatus'; not assumed transient",
            ];
        }

        if ($attempts >= $this->maxAttempts) {
            return [
                'status'         => self::MANUAL_REVIEW_REQUIRED,
                'retryInSeconds' => null,
                'reason'         => "gave up after $attempts attempt(s); last failure was $runStatus",
            ];
        }

        return [
            'status'         => self::QUEUED,
            'retryInSeconds' => $this->backoffSeconds($attempts),
            'reason'         => "$runStatus is transient; attempt " . ($attempts + 1) . " queued",
        ];
    }

    /** Seconds to wait before attempt ($attempts + 1). */
    public function backoffSeconds(int $attempts): int
    {
        $exponent = max(0, $attempts - 1);
        $delay = self::BACKOFF_BASE_SECONDS * (self::BACKOFF_MULTIPLIER ** $exponent);

        return (int) min($delay, self::BACKOFF_CAP_SECONDS);
    }

    /**
     * Whether a claimed job has been abandoned by a dead worker.
     *
     * A null claimed_at with status=scanning counts as stale: it means the claim write did not
     * complete, and leaving the job stuck in `scanning` forever is the one outcome with no recovery.
     */
    public function isClaimStale(string $status, ?int $claimedAt, int $now): bool
    {
        if ($status !== self::SCANNING) {
            return false;
        }

        return $claimedAt === null || ($now - $claimedAt) >= self::STALE_CLAIM_SECONDS;
    }

    /**
     * Where a stale claim goes. Reaping counts as a failed attempt on purpose: a job that reliably
     * kills its worker would otherwise be reclaimed forever, and "forever" is not a state anyone
     * notices.
     *
     * @return array{status:string,retryInSeconds:?int,reason:string}
     */
    public function afterStaleClaim(int $attempts): array
    {
        if ($attempts >= $this->maxAttempts) {
            return [
                'status'         => self::MANUAL_REVIEW_REQUIRED,
                'retryInSeconds' => null,
                'reason'         => "claim went stale on attempt $attempts; no attempts left",
            ];
        }

        return [
            'status'         => self::QUEUED,
            'retryInSeconds' => $this->backoffSeconds($attempts),
            'reason'         => "claim went stale (worker died mid-scan); re-queued as attempt "
                              . ($attempts + 1),
        ];
    }

    /**
     * Review-side transitions. Kept here rather than in the dashboard so there is one place that
     * knows the job lifecycle.
     */
    public function canTransition(string $from, string $to): bool
    {
        $allowed = [
            self::QUEUED                 => [self::SCANNING],
            self::SCANNING               => [
                self::READY_FOR_REVIEW,
                self::QUEUED,
                self::MANUAL_REVIEW_REQUIRED,
            ],
            // An RA can pick a job up and put it back down without deciding.
            self::READY_FOR_REVIEW       => [self::UNDER_REVIEW],
            self::UNDER_REVIEW           => [self::REVIEW_COMPLETE, self::READY_FOR_REVIEW],
            // A failed scan still needs an RA disposition, so it is reviewable like any other.
            self::MANUAL_REVIEW_REQUIRED => [self::UNDER_REVIEW],
            // Terminal.
            self::REVIEW_COMPLETE        => [],
            self::UNREACHABLE            => [],
        ];

        return in_array($to, $allowed[$from] ?? [], true);
    }
}
