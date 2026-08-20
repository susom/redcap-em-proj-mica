<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\ActionFieldWriterInterface;
use Stanford\MICA\RedcapActionFieldWriter;
use Stanford\MICA\TranscriptException;

/**
 * Records action-field writes, and applies the same allowlist the real writer does.
 *
 * Enforcing the allowlist here rather than accepting anything is the point: a fake that took every
 * key would let a `review_status` leak into the action payload pass green, and the whole reason the
 * action writer is a separate seam is that a delivery path must not be able to confirm a finding on
 * its way to notifying about it.
 */
final class FakeActionFieldWriter implements ActionFieldWriterInterface
{
    /** @var list<array<string,mixed>> */
    public array $writes = [];

    public ?string $writeError = null;

    public function writeActionFields(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        array $fields
    ): void {
        foreach (array_keys($fields) as $field) {
            if (!RedcapActionFieldWriter::isWritable($field)) {
                throw new \LogicException("Refusing to write \"$field\" through the action writer.");
            }
        }

        if ($this->writeError !== null) {
            throw new TranscriptException($this->writeError);
        }

        $this->writes[] = [
            'record'   => $record,
            'event_id' => $eventId,
            'instance' => $instance,
            'fields'   => $fields,
        ];
    }

    /** @return array<string,string> */
    public function lastFields(): array
    {
        if ($this->writes === []) {
            throw new \RuntimeException('No action fields were written.');
        }

        return $this->writes[count($this->writes) - 1]['fields'];
    }
}
