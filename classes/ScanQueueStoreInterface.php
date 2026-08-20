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
     * Atomically take the oldest due job: one UPDATE that both selects and marks, then read back
     * by claim token. Returns null when nothing is due.
     *
     * @return array<string,mixed>|null the claimed job row
     */
    public function claim(string $claimToken, int $now): ?array;

    /** @param array<string,mixed> $fields */
    public function updateJob(int $id, array $fields): void;

    /**
     * Jobs stuck in `scanning` whose claim predates $cutoff, plus any with no claim timestamp.
     *
     * @return list<array<string,mixed>>
     */
    public function findStaleClaims(int $cutoff, int $limit): array;

    /** @return list<array<string,mixed>> for the dashboard and the launch-readiness gate */
    public function countByStatus(): array;
}
