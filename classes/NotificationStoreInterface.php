<?php

namespace Stanford\MICA;

/**
 * The notification trail, and the aggregate reads a digest is allowed to make.
 *
 * ## Why the digest query lives here and not in the caller
 *
 * `digestCounts()` returns counts per enum bucket and nothing else. It could have been a parameter -
 * "pass me the numbers" - but then the class that promises a digest carries no participant-level
 * detail would not be the class that computes it, and the promise would be unkeepable by
 * construction: nothing would stop a caller passing `['record_12' => 1]`. Making the aggregate a
 * *capability of the store* means the narrowest possible thing is the only thing available.
 *
 * NotificationService still validates the shape on the way back, because this is an interface and an
 * implementation is free to misbehave.
 */
interface NotificationStoreInterface
{
    /**
     * Whether a notice with this idempotency key has already been recorded as sent.
     *
     * Re-entry is a live path, not a theoretical one: a scan job that fails transiently retries with
     * backoff and can reach `ready_for_review` more than once. Without this, every retry is another
     * "findings ready" email to the same people about the same session, which is how a reviewer
     * learns to filter the alerts.
     */
    public function alreadySent(string $idempotencyKey): bool;

    /**
     * Append one attempt - sent or failed. Returns the log id.
     *
     * Failed attempts are recorded too. A notification trail that holds only successes cannot answer
     * "was the PI ever told", which is the question that actually gets asked.
     *
     * @param array<string,mixed> $row
     */
    public function recordNotification(array $row): int;

    /**
     * Counts per enum bucket for a digest window. Never rows, never identifiers.
     *
     * @return array<string,int> keys drawn from NotificationService::DIGEST_BUCKETS
     */
    public function digestCounts(string $projectId, int $sinceTs, int $untilTs): array;

    /**
     * Findings that are still awaiting acknowledgment past their window.
     *
     * @return list<array<string,mixed>> record/event/instance/urgency/notified_at - no free text
     */
    public function unacknowledged(string $projectId, int $notifiedBeforeTs): array;
}
