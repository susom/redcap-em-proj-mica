<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\ScanJobStateMachine;
use Stanford\MICA\ScanQueueStoreInterface;

/**
 * An in-memory mica_scan_job table.
 *
 * It **enforces the UNIQUE index on idempotency_key**, because that constraint is the thing under
 * test: a fake that quietly accepted duplicates would let every idempotency test pass while the
 * real guarantee lived only in a comment.
 *
 * `$failNextInsert` models losing the check-then-insert race - the insert is rejected even though
 * the caller's earlier lookup found nothing - so the race handling can be exercised without
 * threads.
 */
final class FakeScanQueueStore implements ScanQueueStoreInterface
{
    /** @var array<int,array<string,mixed>> */
    public array $jobs = [];
    /** @var list<string> */
    public array $calls = [];

    /** Reject the next insert as a duplicate-key rejection, without a row existing. */
    public bool $failNextInsertWithNoRow = false;

    /** Reject the next insert, but insert the racing row first (a genuine lost race). */
    public ?array $raceInsertsRow = null;

    private int $nextId = 1;

    public function insertJob(array $data): ?int
    {
        $this->calls[] = 'insertJob';

        if ($this->failNextInsertWithNoRow) {
            $this->failNextInsertWithNoRow = false;
            return null;
        }

        if ($this->raceInsertsRow !== null) {
            // Somebody else got there first, between the caller's lookup and this insert.
            $winner = $this->raceInsertsRow + ['id' => $this->nextId++];
            $this->jobs[$winner['id']] = $winner;
            $this->raceInsertsRow = null;
            return null;
        }

        // The UNIQUE index, modelled.
        if ($this->findByIdempotencyKey((string) $data['idempotency_key']) !== null) {
            return null;
        }

        $id = $this->nextId++;
        $this->jobs[$id] = $data + ['id' => $id, 'claimed_by' => null, 'claimed_at' => null];

        return $id;
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        foreach ($this->jobs as $job) {
            if (($job['idempotency_key'] ?? null) === $key) {
                return $job;
            }
        }

        return null;
    }

    public function findJob(int $id): ?array
    {
        return $this->jobs[$id] ?? null;
    }

    public function claim(string $claimToken, int $now): ?array
    {
        $this->calls[] = 'claim';

        // FIFO by id, and only jobs whose backoff has elapsed - the same predicate as the real
        // UPDATE, so a test that passes here means the same thing there.
        foreach ($this->jobs as $id => $job) {
            if ($job['status'] !== ScanJobStateMachine::QUEUED) {
                continue;
            }
            if ((int) $job['next_attempt_at'] > $now) {
                continue;
            }

            $this->jobs[$id]['status'] = ScanJobStateMachine::SCANNING;
            $this->jobs[$id]['claimed_by'] = $claimToken;
            $this->jobs[$id]['claimed_at'] = $now;

            return $this->jobs[$id];
        }

        return null;
    }

    public function updateJob(int $id, array $fields): void
    {
        $this->calls[] = 'updateJob';
        $this->jobs[$id] = array_merge($this->jobs[$id] ?? [], $fields);
    }

    public function findStaleClaims(int $cutoff, int $limit): array
    {
        $stale = [];
        foreach ($this->jobs as $job) {
            if ($job['status'] !== ScanJobStateMachine::SCANNING) {
                continue;
            }
            $claimedAt = $job['claimed_at'] ?? null;
            if ($claimedAt === null || (int) $claimedAt <= $cutoff) {
                $stale[] = $job;
            }
            if (count($stale) >= $limit) {
                break;
            }
        }

        return $stale;
    }

    public function countByStatus(): array
    {
        $counts = [];
        foreach ($this->jobs as $job) {
            $counts[$job['status']] = ($counts[$job['status']] ?? 0) + 1;
        }

        return $counts;
    }
}
