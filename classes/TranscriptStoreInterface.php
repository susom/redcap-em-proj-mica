<?php

namespace Stanford\MICA;

/**
 * Everything TranscriptFinalizer needs from REDCap.
 *
 * Four operations, drawn so that the finalizer's decisions - what counts as this session, what to
 * hash, whether to enqueue, what to do when the session form cannot be written - are all testable
 * without a database.
 */
interface TranscriptStoreInterface
{
    /**
     * MICAQuery message rows for one participant, ascending by log_id, strictly after $afterLogId.
     *
     * @return list<array{log_id:int,timestamp:?string,message:?string}>
     */
    public function messageRows(string $projectId, string $participantId, int $afterLogId): array;

    /**
     * The highest-version finalized transcript for this session slot, or null if there is none.
     *
     * Used for two things: the session boundary (its `max_message_log_id` is where this session
     * starts) and the version number of the row about to be written.
     *
     * @return array{log_id:int,version:int,max_message_log_id:int,transcript_sha256:string,
     *               message_count:int,supersedes_boundary:int}|null
     */
    public function latestTranscript(
        string $projectId,
        string $record,
        string $sessionType,
        int $instance
    ): ?array;

    /**
     * Append a transcript row to the EM log.
     *
     * @param array<string,string|int|null> $params
     * @return int the new log_id
     */
    public function writeTranscript(array $params): int;

    /**
     * Which of the given fields actually exist on this project's data dictionary.
     *
     * Asked before writing, so a missing field produces a named, actionable warning instead of a
     * saveData error whose message does not say which field it means.
     *
     * @param string[] $fieldNames
     * @return string[] the subset that exists
     */
    public function existingFields(string $projectId, array $fieldNames): array;

    /**
     * Write session-form fields. Must inspect saveData's `errors` and throw on any.
     *
     * @param array<string,string> $fields
     */
    public function writeSessionFields(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        array $fields
    ): void;
}
