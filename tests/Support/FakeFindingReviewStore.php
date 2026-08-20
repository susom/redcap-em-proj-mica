<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\FindingReviewStoreInterface;
use Stanford\MICA\TranscriptException;

/**
 * One in-memory finding instance.
 *
 * Stateful, and it APPLIES what it is asked to write - so a test can submit twice and see the second
 * attempt hit a version that has actually moved. A fake that only logged writes would make every
 * locking test pass while the guarantee lived in a comment (the same mistake FakeEntityPlatform
 * made, once).
 */
final class FakeFindingReviewStore implements FindingReviewStoreInterface
{
    /** @var array<string,string> */
    public array $finding;
    /** @var list<array<string,string>> every write, in order */
    public array $writes = [];

    public ?string $writeError = null;
    public bool $missing = false;

    public function __construct(array $overrides = [])
    {
        $this->finding = $overrides + [
            'finding_id'             => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'finding_concern_type'   => 'self_harm',
            'finding_urgency'        => 'critical',
            'finding_summary'        => 'passive ideation',
            'review_status'          => 'pending',
            'review_lock_version'    => '0',
        ];
    }

    public function readFinding(string $projectId, string $record, int $eventId, int $instance): ?array
    {
        return $this->missing ? null : $this->finding;
    }

    public function writeReviewFields(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        array $fields
    ): void {
        if ($this->writeError !== null) {
            throw new TranscriptException($this->writeError);
        }

        $this->writes[] = $fields;
        // Applied, so a second submit sees the moved version.
        $this->finding = array_merge($this->finding, $fields);
    }

    /** @return array<string,string> the most recent write */
    public function lastWrite(): array
    {
        return $this->writes[count($this->writes) - 1];
    }
}
