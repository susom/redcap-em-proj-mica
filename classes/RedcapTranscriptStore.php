<?php

namespace Stanford\MICA;

require_once __DIR__ . "/TranscriptStoreInterface.php";
require_once __DIR__ . "/TranscriptException.php";

/**
 * The real transcript store: MICA's EM message log, the transcript log rows, and the session form.
 *
 * No decisions here - see TranscriptFinalizer for those. Two REDCap details worth knowing before
 * reading:
 *
 *   `queryLogs()` is not SQL over the log tables. Its select list is a list of *names*, each of
 *   which comes back as a column on one row; real columns (log_id, timestamp, message, record) and
 *   EAV parameters are both addressable the same way. So every name that is wanted has to be named,
 *   and a `select *` does not exist.
 *
 *   MICAQuery rows are distinguished by `record = 'MICAQuery'` (ASEMLO uses the record column as its
 *   object-name discriminator, which is why the arm-materialization row does not appear in a
 *   transcript). Transcript rows carry the real record id in `record` and are found by the
 *   `log_type` PARAMETER - not by the `message` column, which is not addressable in a queryLogs
 *   where clause even though it holds the same string. See latestTranscript().
 */
class RedcapTranscriptStore implements TranscriptStoreInterface
{
    /** ASEMLO's object name for MICAQuery, which lands in the log's `record` column. */
    private const MESSAGE_OBJECT = 'MICAQuery';

    private MICA $module;

    public function __construct(MICA $module)
    {
        $this->module = $module;
    }

    public function messageRows(string $projectId, string $participantId, int $afterLogId): array
    {
        // Ascending by log_id, which is both the conversation order and the session boundary's unit.
        $result = $this->module->queryLogs(
            'select log_id, timestamp, message where record = ? and project_id = ? and mica_id = ? '
            . 'and log_id > ? order by log_id',
            [self::MESSAGE_OBJECT, $projectId, $participantId, $afterLogId]
        );

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'log_id'    => (int) $row['log_id'],
                'timestamp' => $row['timestamp'] ?? null,
                'message'   => $row['message'] ?? null,
            ];
        }

        return $rows;
    }

    public function latestTranscript(
        string $projectId,
        string $record,
        string $sessionType,
        int $instance
    ): ?array {
        // Filtered on the `log_type` PARAMETER, not on the `message` column. The column holds the
        // same string and reads nicely in the log viewer, but it is not addressable in a queryLogs
        // where clause - `where message = ?` matches nothing at all, silently, which would make
        // every session look like the first one and re-scan the entire history every time. Verified
        // against the live instance; see docs/phase-3-handoff/scripts/verify-transcript-store.php.
        $result = $this->module->queryLogs(
            'select log_id, version, max_message_log_id, transcript_sha256, supersedes_boundary, '
            . 'message_count '
            . 'where log_type = ? and project_id = ? and record = ? and session_type = ? '
            . 'and instance = ? order by log_id desc',
            [
                TranscriptFinalizer::LOG_TYPE,
                $projectId,
                $record,
                $sessionType,
                (string) $instance,
            ]
        );

        // Highest version wins, not highest log_id: they agree today, but a manual correction row
        // inserted out of order would make them disagree, and "current" is defined by version
        // (02-data-model.md §1.2).
        $best = null;
        while ($row = $result->fetch_assoc()) {
            if ($best === null || (int) $row['version'] > (int) $best['version']) {
                $best = $row;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'log_id'              => (int) $best['log_id'],
            'version'             => (int) $best['version'],
            'max_message_log_id'  => (int) $best['max_message_log_id'],
            'transcript_sha256'   => (string) $best['transcript_sha256'],
            'supersedes_boundary' => (int) ($best['supersedes_boundary'] ?? 0),
            'message_count'       => (int) ($best['message_count'] ?? 0),
        ];
    }

    public function readTranscript(string $projectId, int $logId): ?array
    {
        // Straight at the parameters table rather than through queryLogs(): the chunk count is not
        // known in advance, and queryLogs() has no way to say "every parameter on this row" - its
        // select list is a list of names. project_id is in the where clause so a log_id from
        // another project cannot be read.
        $result = $this->module->query(
            'SELECT p.name AS n, p.value AS v FROM redcap_external_modules_log_parameters p '
            . 'JOIN redcap_external_modules_log l ON l.log_id = p.log_id '
            . 'WHERE p.log_id = ? AND l.project_id = ?',
            [$logId, $projectId]
        );

        $params = [];
        while ($row = $result->fetch_assoc()) {
            $params[(string) $row['n']] = (string) $row['v'];
        }

        return $params === [] ? null : $params;
    }

    public function writeTranscript(array $params): int
    {
        // Nulls are dropped rather than stored: the EAV parameters table has no null semantics, and
        // an absent supersedes_log_id reads more honestly than an empty string.
        $clean = [];
        foreach ($params as $key => $value) {
            if ($value !== null) {
                $clean[$key] = is_int($value) ? (string) $value : $value;
            }
        }

        // `log_type` duplicates the message text as a queryable parameter - see latestTranscript().
        $clean['log_type'] = TranscriptFinalizer::LOG_TYPE;

        // project_id explicitly rather than by context detection. log() infers it from the request,
        // and a cron or CLI caller has no project context, so the row would land with a NULL
        // project_id - unfindable by every read in this class, and unremovable by removeLogs(),
        // which refuses to run without a project_id in its where clause. Observed both.
        $clean['project_id'] = (int) ($params['project_id'] ?? 0) ?: $this->currentProjectId();

        $logId = $this->module->log(TranscriptFinalizer::LOG_TYPE, $clean);

        return is_numeric($logId) ? (int) $logId : 0;
    }

    private function currentProjectId(): int
    {
        // PROJECT_ID is defined on a project or survey request; the finalizer's callers all have
        // one. If it is somehow absent, 0 is better than NULL: it is visibly wrong rather than
        // invisibly unqueryable, and the caller's own project_id should have been passed anyway.
        return defined('PROJECT_ID') ? (int) PROJECT_ID : 0;
    }

    public function existingFields(string $projectId, array $fieldNames): array
    {
        if ($fieldNames === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($fieldNames), '?'));

        $result = $this->module->query(
            'SELECT field_name AS f FROM redcap_metadata WHERE project_id = ? '
            . "AND field_name IN ($placeholders)",
            array_merge([$projectId], $fieldNames)
        );

        $found = [];
        while ($row = $result->fetch_assoc()) {
            $found[] = (string) $row['f'];
        }

        return $found;
    }

    public function writeSessionFields(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        array $fields
    ): void {
        $recordData = [
            'record_id'                  => $record,
            'redcap_event_name'          => \REDCap::getEventNames(true, false, $eventId),
            'redcap_repeat_instance'     => $instance,
        ] + $fields;

        // The primary field is whatever this project calls it, not necessarily record_id.
        $primary = \REDCap::getRecordIdField();
        if ($primary !== 'record_id') {
            $recordData[$primary] = $record;
            unset($recordData['record_id']);
        }

        $response = \REDCap::saveData(
            (int) $projectId,
            'json',
            json_encode([$recordData]),
            'overwrite'
        );

        // Inspected, never assumed. saveData reports failures in the return value rather than by
        // throwing, so an unchecked call is how a session silently fails to record that it closed
        // (the same class of bug as docs 14 D11/D16).
        if (!empty($response['errors'])) {
            throw new TranscriptException(
                'REDCap::saveData refused the session-form write: '
                . (is_array($response['errors']) ? implode('; ', $response['errors']) : $response['errors'])
            );
        }
    }
}
