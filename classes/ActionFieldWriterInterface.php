<?php

namespace Stanford\MICA;

/**
 * Writes the `action_*` bookkeeping fields on one finding instance.
 *
 * A separate interface from FindingReviewStoreInterface rather than a widening of it, because the two
 * write sets must not be reachable from each other's code path. `writeReviewFields()` uses
 * `overwrite` semantics and refuses any `action_*` key; this uses `normal` and refuses any `review_*`
 * or `finding_*` key. Keeping them apart is what makes each one's refusal absolute instead of
 * conventional.
 */
interface ActionFieldWriterInterface
{
    /**
     * @param array<string,string> $fields action_* only - the implementation enforces it
     * @throws TranscriptException when the write fails
     * @throws \LogicException     on a field outside the action set - a programming error
     */
    public function writeActionFields(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        array $fields
    ): void;
}
