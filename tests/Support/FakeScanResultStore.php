<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\ScanResultStoreInterface;
use Stanford\MICA\TranscriptException;

/** In-memory mica_scan_run rows and mica_safety_finding instances. */
final class FakeScanResultStore implements ScanResultStoreInterface
{
    /** @var list<array<string,mixed>> every run row, in insert order */
    public array $runs = [];
    /** @var list<array<string,mixed>> every finding-instance write */
    public array $findingWrites = [];

    /** Defaults to true; set false to model PID 257 today, where the instrument does not exist. */
    public bool $instrumentExists = true;
    public ?string $saveError = null;
    public int $nextInstance = 1;
    /** @var string[] */
    public array $existingIds = [];

    private int $nextRunId = 100;

    public function insertRun(array $data): int
    {
        $id = $this->nextRunId++;
        $this->runs[] = $data + ['id' => $id];

        return $id;
    }

    public function findingInstrumentExists(string $projectId): bool
    {
        return $this->instrumentExists;
    }

    public function nextFindingInstance(string $projectId, string $record, int $eventId): int
    {
        return $this->nextInstance;
    }

    public function writeFindingInstances(
        string $projectId,
        string $record,
        int $eventId,
        int $firstInstance,
        array $instances
    ): void {
        if ($this->saveError !== null) {
            throw new TranscriptException($this->saveError);
        }

        foreach ($instances as $offset => $fields) {
            $this->findingWrites[] = [
                'record'   => $record,
                'event_id' => $eventId,
                'instance' => $firstInstance + $offset,
                'fields'   => $fields,
            ];
        }
    }

    public function existingFindingIds(string $projectId, string $record): array
    {
        return $this->existingIds;
    }

    /** @return array<string,mixed> the most recent run row */
    public function lastRun(): array
    {
        return $this->runs[count($this->runs) - 1];
    }
}
