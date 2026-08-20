<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\ReviewQueryStoreInterface;

/** In-memory queue, session and audit reads. */
final class FakeReviewQueryStore implements ReviewQueryStoreInterface
{
    /** @var list<array<string,mixed>> */
    public array $queue = [];
    /** @var array<string,mixed>|null */
    public ?array $session = null;
    /** @var list<array<string,mixed>> */
    public array $history = [];
    /** @var list<array<string,mixed>> */
    public array $audit = [];

    public function queueRows(string $projectId): array
    {
        return $this->queue;
    }

    public function session(string $projectId, string $record, int $eventId, int $instance): ?array
    {
        return $this->session;
    }

    public function historyRows(string $projectId): array
    {
        return $this->history;
    }

    public function auditEvents(string $projectId, int $limit): array
    {
        return array_slice($this->audit, 0, $limit);
    }
}
