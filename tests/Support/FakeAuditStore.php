<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\AuditStoreInterface;
use Stanford\MICA\EntitySchemaException;

/** In-memory mica_audit_event rows. */
final class FakeAuditStore implements AuditStoreInterface
{
    /** @var list<array<string,mixed>> */
    public array $events = [];

    public bool $failWrites = false;

    private int $nextId = 500;

    public function insertAuditEvent(array $data): int
    {
        if ($this->failWrites) {
            throw new EntitySchemaException('audit table unavailable');
        }

        $id = $this->nextId++;
        $this->events[] = $data + ['id' => $id];

        return $id;
    }

    /** @return array<string,mixed> the most recent event */
    public function last(): array
    {
        return $this->events[count($this->events) - 1];
    }
}
