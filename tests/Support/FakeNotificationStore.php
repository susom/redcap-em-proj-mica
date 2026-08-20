<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\NotificationStoreInterface;

/**
 * In-memory notification trail.
 *
 * `alreadySent()` is answered from the rows this store actually holds, and only counts rows whose
 * status is `sent` - so a test that sends twice sees the second attempt suppressed for the real
 * reason, and a test whose first attempt *failed* sees the retry allowed. Hardcoding the answer would
 * make every idempotency test pass without the mechanism existing.
 */
final class FakeNotificationStore implements NotificationStoreInterface
{
    /** @var list<array<string,mixed>> */
    public array $rows = [];

    /** @var array<string,mixed> what digestCounts() returns; set a bad key to test the shape guard */
    public array $counts = [];

    /** @var list<array<string,mixed>> */
    public array $overdue = [];

    public function alreadySent(string $idempotencyKey): bool
    {
        foreach ($this->rows as $row) {
            if (($row['idempotency_key'] ?? '') === $idempotencyKey && ($row['status'] ?? '') === 'sent') {
                return true;
            }
        }

        return false;
    }

    public function recordNotification(array $row): int
    {
        $this->rows[] = $row;

        return count($this->rows);
    }

    public function digestCounts(string $projectId, int $sinceTs, int $untilTs): array
    {
        return $this->counts;
    }

    public function unacknowledged(string $projectId, int $notifiedBeforeTs): array
    {
        return $this->overdue;
    }

    /** @return list<array<string,mixed>> */
    public function withStatus(string $status): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn(array $r): bool => ($r['status'] ?? '') === $status
        ));
    }

    /** @return array<string,mixed> */
    public function last(): array
    {
        if ($this->rows === []) {
            throw new \RuntimeException('No notification row was recorded.');
        }

        return $this->rows[count($this->rows) - 1];
    }
}
