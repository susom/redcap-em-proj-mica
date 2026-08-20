<?php

namespace Stanford\MICA;

/**
 * Read and write one finding instance's review fields.
 *
 * Narrow on purpose: the disposition path needs exactly one read and one write, and keeping the
 * queue's much larger read API out of this interface is what lets the locking logic be tested
 * against a fake that fits on a screen.
 */
interface FindingReviewStoreInterface
{
    /**
     * One finding instance's fields, or null if there is no such instance.
     *
     * @return array<string,string>|null
     */
    public function readFinding(string $projectId, string $record, int $eventId, int $instance): ?array;

    /**
     * @param array<string,string> $fields review_* only - the caller enforces that
     * @throws TranscriptException when saveData reports any error
     */
    public function writeReviewFields(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        array $fields
    ): void;
}
