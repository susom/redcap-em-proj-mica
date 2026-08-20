<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\TranscriptStoreInterface;

/** An in-memory message log, transcript log and data dictionary. */
final class FakeTranscriptStore implements TranscriptStoreInterface
{
    /** @var list<array{log_id:int,timestamp:?string,message:?string}> */
    public array $rows = [];
    /** @var list<array<string,mixed>> transcript rows, in write order */
    public array $transcripts = [];
    /** @var list<array<string,mixed>> session-form writes */
    public array $sessionWrites = [];
    /** Which fields the project's dictionary has. Empty models today's PID 257 (audit G4). */
    public array $dictionary = [
        'mica_session_status',
        'mica_session_end_ts',
        'mica_transcript_ref',
        'mica_transcript_hash',
    ];

    public ?string $sessionWriteError = null;
    public bool $transcriptWriteFails = false;

    private int $nextLogId = 5000;

    public function addParticipantMessage(string $content, string $ts = '2026-08-19 17:00:00'): int
    {
        $id = $this->nextLogId++;
        $this->rows[] = [
            'log_id'    => $id,
            'timestamp' => $ts,
            'message'   => json_encode(['role' => 'user', 'content' => $content]),
        ];

        return $id;
    }

    public function addTurn(string $query, ?string $response, string $ts = '2026-08-19 17:00:05'): int
    {
        $id = $this->nextLogId++;
        $this->rows[] = [
            'log_id'    => $id,
            'timestamp' => $ts,
            'message'   => json_encode([
                'response' => ['role' => 'assistant', 'content' => $response],
                'query'    => ['role' => 'user', 'content' => $query],
                'model'    => 'claude-opus-4-7',
            ]),
        ];

        return $id;
    }

    public function messageRows(string $projectId, string $participantId, int $afterLogId): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => (int) $row['log_id'] > $afterLogId
        ));
    }

    public function latestTranscript(
        string $projectId,
        string $record,
        string $sessionType,
        int $instance
    ): ?array {
        $matching = array_filter(
            $this->transcripts,
            static fn(array $t): bool => $t['record'] === $record
                && $t['session_type'] === $sessionType
                && (int) $t['instance'] === $instance
        );

        if ($matching === []) {
            return null;
        }

        usort($matching, static fn(array $a, array $b): int => $a['version'] <=> $b['version']);

        return end($matching);
    }

    public function writeTranscript(array $params): int
    {
        if ($this->transcriptWriteFails) {
            return 0;
        }

        $logId = $this->nextLogId++;
        $this->transcripts[] = $params + ['log_id' => $logId];

        return $logId;
    }

    public function existingFields(string $projectId, array $fieldNames): array
    {
        return array_values(array_intersect($fieldNames, $this->dictionary));
    }

    public function writeSessionFields(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        array $fields
    ): void {
        if ($this->sessionWriteError !== null) {
            throw new \RuntimeException($this->sessionWriteError);
        }

        $this->sessionWrites[] = [
            'record'   => $record,
            'event_id' => $eventId,
            'instance' => $instance,
            'fields'   => $fields,
        ];
    }
}
