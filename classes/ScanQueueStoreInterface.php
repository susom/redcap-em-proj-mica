<?php

namespace Stanford\MICA;

/**
 * Everything ScanQueue needs from the mica_scan_job table.
 *
 * The interface exists for the usual reason (the logic must be testable without REDCap) and for one
 * specific one: `claim()` cannot go through the REDCap Entity framework at all. Entity's save path
 * is generic read-modify-write CRUD, so two workers would both read `status='queued'`, both write
 * `status='scanning'`, and both scan the same transcript. The claim has to be a single atomic
 * UPDATE, which means direct SQL - and that is precisely the kind of thing worth putting behind a
 * seam so the surrounding decisions can be tested without one.
 */
interface ScanQueueStoreInterface
{
    /**
     * Insert a job row.
     *
     * @param array<string,mixed> $data
     * @return int|null the new row id, or null if the idempotency_key is already taken
     *                  (the UNIQUE index did its job - that is success, not an error)
     */
    public function insertJob(array $data): ?int;

    /** @return array<string,mixed>|null */
    public function findByIdempotencyKey(string $key): ?array;

    /** @return array<string,mixed>|null */
    public function findJob(int $id): ?array;

    /**
     * Atomically take the oldest due job **of one project**: one UPDATE that both selects and marks,
     * then read back by claim token. Returns null when nothing of that project is due.
     *
     * The project is not optional. The scan cron runs one pass per MICA project and scans each job
     * with the pass's settings - model alias, prompt addendum, thresholds - and tells the pass's
     * reviewers. Unscoped, whichever project's pass ran first took every project's jobs: on prod
     * 35968 every scan ran on another project's blank alias (the built-in `gemini-2.5-flash`, no
     * longer registered), failed in milliseconds, and its notice and its SecureChatAI log row went to
     * that other project (docs 31).
     *
     * @return array<string,mixed>|null the claimed job row
     */
    public function claim(string $claimToken, int $now, int $projectId): ?array;

    /** @param array<string,mixed> $fields */
    public function updateJob(int $id, array $fields): void;

    /**
     * One project's jobs stuck in `scanning` whose claim predates $cutoff, plus any with no claim
     * timestamp. Scoped for the same reason as claim(): a pass only ever touches its own project.
     *
     * @return list<array<string,mixed>>
     */
    public function findStaleClaims(int $cutoff, int $limit, int $projectId): array;

    /** @return list<array<string,mixed>> for the dashboard and the launch-readiness gate */
    public function countByStatus(): array;
}
