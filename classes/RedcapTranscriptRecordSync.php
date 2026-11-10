<?php

namespace Stanford\MICA;

require_once __DIR__ . "/CanonicalJson.php";
require_once __DIR__ . "/ProjectContext.php";
require_once __DIR__ . "/RedcapTranscriptStore.php";
require_once __DIR__ . "/TranscriptRecordBuilder.php";
require_once __DIR__ . "/TranscriptRecordDiff.php";

/**
 * Copies every finalized session into the repeating `mica_transcript` instrument, one instance per
 * statement, with the Safety Scan's highlighted statements marked. Transcripts live in the EM log,
 * which neither reports nor the API can read; this puts them where both can
 * (docs/topics/safetyscan/33-transcript-export-field.md).
 *
 * Runs from the `mica_transcript_records` cron and is idempotent: each pass rebuilds every slot
 * from the log and the findings, and writes only the instances whose values changed. That one pass
 * covers the sessions finalized before the instrument existed, a continuation, a re-finalize, a
 * finding written after the transcript, and a reviewer's decision, with nothing hooked into those
 * paths.
 *
 * Does nothing on a project without the instrument, so uploading the instrument is the switch.
 *
 * ## Never fatal to anything else
 *
 * Nothing here is on the safety path: the transcript is already finalized and scanned before it can
 * be copied. A slot that cannot be copied is skipped and reported, and the other slots carry on.
 */
final class RedcapTranscriptRecordSync
{
    private const JOBS = 'redcap_entity_mica_scan_job';
    private const RUNS = 'redcap_entity_mica_scan_run';

    private const FINDING_FIELDS = [
        'finding_id',
        'finding_scan_run',
        'finding_concern_type',
        'finding_urgency',
        'finding_evidence_json',
        'review_status',
        'review_corrected_concern_type',
        'review_corrected_urgency',
    ];

    private MICA $module;
    private TranscriptRecordBuilder $builder;

    public function __construct(MICA $module, ?TranscriptRecordBuilder $builder = null)
    {
        $this->module = $module;
        $this->builder = $builder ?? new TranscriptRecordBuilder();
    }

    /**
     * @return array{status:string,slots:int,instances_written:int,instances_cleared:int,
     *               records_written:int,skipped:array<string,int>,errors:list<string>}
     */
    public function syncProject(int $projectId, ?string $onlyRecord = null): array
    {
        $summary = [
            'status'            => 'ok',
            'slots'             => 0,
            'instances_written' => 0,
            'instances_cleared' => 0,
            'records_written'   => 0,
            'skipped'           => [],
            'errors'            => [],
        ];

        $missing = $this->missingFields($projectId);
        if (count($missing) === count(TranscriptRecordBuilder::FIELDS)) {
            $summary['status'] = 'no_instrument';
            return $summary;
        }
        if ($missing !== []) {
            // Half an instrument would write half a transcript. Refused, and named.
            $summary['status'] = 'incomplete_instrument';
            $summary['errors'][] = 'The mica_transcript instrument is missing these fields: '
                . implode(', ', $missing) . '. Upload the whole instrument ZIP again.';
            return $summary;
        }

        $slots = $this->slots($projectId, $onlyRecord, $summary);
        if ($slots === []) {
            return $summary;
        }

        $records = array_values(array_unique(array_column($slots, 'record')));
        $findingsByRun = $this->findingsByRun($projectId, $records);
        $existing = $this->existingInstances($projectId, $records);
        $primary = ProjectContext::for($projectId);

        $writesByRecord = [];

        foreach ($slots as $key => $slot) {
            $summary['slots']++;

            $transcripts = [];
            $findingsByTranscript = [];
            $broken = false;

            foreach ($slot['transcripts'] as $logId => $runId) {
                $t = $this->verifiedTranscript($projectId, $logId);
                if ($t === null) {
                    // A slot is copied whole or not at all: a coder must never get a conversation
                    // with a hole in it that looks complete.
                    $summary['errors'][] = "record {$slot['record']} event {$slot['event_id']}: transcript "
                        . "T$logId is missing or fails its hash check, so this session was not copied";
                    $broken = true;
                    break;
                }
                $transcripts[] = $t;
                $findingsByTranscript[$logId] = $runId === null ? [] : ($findingsByRun[(string) $runId] ?? []);
            }

            if ($broken) {
                $this->bump($summary, 'transcript_unreadable');
                continue;
            }

            $desired = $this->builder->build($slot['session_type'], $transcripts, $findingsByTranscript);
            $changes = TranscriptRecordDiff::changes($desired, $existing[$key] ?? []);

            if ($changes === []) {
                continue;
            }

            $eventName = ProjectContext::uniqueEventName($projectId, $slot['event_id']);

            foreach ($changes as $instance => $row) {
                $cleared = trim(implode('', $row)) === '';
                $summary[$cleared ? 'instances_cleared' : 'instances_written']++;

                $writesByRecord[$slot['record']][] = [
                    $primary                   => $slot['record'],
                    'redcap_event_name'        => $eventName,
                    'redcap_repeat_instrument' => TranscriptRecordBuilder::FORM,
                    'redcap_repeat_instance'   => $instance,
                ] + $row;
            }
        }

        // One save per record, so a record REDCap refuses does not take the others down with it.
        foreach ($writesByRecord as $record => $rows) {
            $response = \REDCap::saveData([
                'project_id'            => $projectId,
                'dataFormat'            => 'json',
                'data'                  => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'overwriteBehavior'     => 'overwrite',
                // A statement copied into a Day 1 or Month 3 instance must not fire that event's
                // alerts or ASIs, nor re-run its calculations: this is bookkeeping, not data entry.
                'performAutoCalc'       => false,
                'bypassAlertAsiTrigger' => true,
            ]);

            $errors = $response['errors'] ?? [];
            if (!empty($errors)) {
                $summary['errors'][] = "record $record: REDCap refused the write: "
                    . (is_array($errors) ? implode('; ', $errors) : (string) $errors);
                continue;
            }

            $summary['records_written']++;
        }

        if ($summary['errors'] !== []) {
            $summary['status'] = 'partial';
        }

        return $summary;
    }

    /** @return list<string> */
    private function missingFields(int $projectId): array
    {
        $fields = TranscriptRecordBuilder::FIELDS;
        $result = $this->module->query(
            'SELECT field_name AS f FROM redcap_metadata WHERE project_id = ? AND form_name = ? AND field_name IN ('
            . implode(',', array_fill(0, count($fields), '?')) . ')',
            array_merge([$projectId, TranscriptRecordBuilder::FORM], $fields)
        );

        $present = [];
        while ($row = $result->fetch_assoc()) {
            $present[] = (string) $row['f'];
        }

        return array_values(array_diff($fields, $present));
    }

    /**
     * Session slots, from the scan jobs: a job is the only place a transcript's event is recorded.
     * Each slot maps its transcript log ids to the latest scan run of that transcript (null if none).
     *
     * @param array<string,mixed> $summary
     * @return array<string,array{record:string,event_id:int,session_type:string,transcripts:array<int,?int>}>
     */
    private function slots(int $projectId, ?string $onlyRecord, array &$summary): array
    {
        $sql = 'SELECT j.record, j.event_id, j.instance, j.session_type, j.transcript_ref, '
            . '(SELECT MAX(r.id) FROM ' . self::RUNS . ' r WHERE r.job_id = j.id) AS run_id '
            . 'FROM ' . self::JOBS . ' j WHERE j.project_id = ?';
        $params = [$projectId];

        if ($onlyRecord !== null) {
            $sql .= ' AND j.record = ?';
            $params[] = $onlyRecord;
        }

        $result = $this->module->query($sql . ' ORDER BY j.transcript_ref', $params);

        $slots = [];
        $instances = [];
        $exists = [];

        while ($row = $result->fetch_assoc()) {
            $record = (string) $row['record'];
            $eventId = (int) $row['event_id'];

            if ($eventId <= 0) {
                $this->bump($summary, 'job_without_event');
                continue;
            }

            // A deleted record keeps its jobs and log rows (they are not record data). Writing to it
            // would bring the record back to life, holding nothing but its transcript.
            $exists[$record] ??= \Records::recordExists($projectId, $record);
            if (!$exists[$record]) {
                $this->bump($summary, 'record_deleted');
                continue;
            }

            $key = $record . '|' . $eventId;
            $instances[$key][(int) $row['instance']] = true;

            $slots[$key] ??= [
                'record'       => $record,
                'event_id'     => $eventId,
                'session_type' => (string) $row['session_type'],
                'transcripts'  => [],
            ];

            $logId = (int) $row['transcript_ref'];
            $runId = $row['run_id'] === null ? null : (int) $row['run_id'];
            // Two jobs for one transcript (a re-queue): the newest run decides.
            $previous = $slots[$key]['transcripts'][$logId] ?? null;
            $slots[$key]['transcripts'][$logId] = max($previous ?? 0, $runId ?? 0) ?: null;
        }

        foreach ($instances as $key => $seen) {
            if (count($seen) > 1) {
                // The session forms are non-repeating, so one event holds one session. A repeating
                // host would put two conversations into one event's statements; refused, not merged.
                $summary['errors'][] = "record {$slots[$key]['record']} event {$slots[$key]['event_id']}: "
                    . 'more than one session instance in one event is not supported; not copied';
                unset($slots[$key]);
            }
        }

        return $slots;
    }

    /**
     * @return array{log_id:int,version:int,supersedes_boundary:int,messages:list<array<string,mixed>>}|null
     */
    private function verifiedTranscript(int $projectId, int $logId): ?array
    {
        $params = (new RedcapTranscriptStore($this->module))->readTranscript((string) $projectId, $logId);
        if ($params === null) {
            return null;
        }

        $canonical = CanonicalJson::fromLogParameters($params);
        if (!hash_equals((string) ($params['transcript_sha256'] ?? ''), CanonicalJson::hash($canonical))) {
            return null;
        }

        $payload = json_decode($canonical, true);
        if (!is_array($payload) || !is_array($payload['messages'] ?? null)) {
            return null;
        }

        return [
            'log_id'              => $logId,
            'version'             => (int) ($params['version'] ?? 1),
            'supersedes_boundary' => (int) ($params['supersedes_boundary'] ?? 0),
            'messages'            => $payload['messages'],
        ];
    }

    /**
     * Findings keyed by the scan run that produced them, in the shape TranscriptRecordBuilder reads.
     *
     * @param list<string> $records
     * @return array<string,list<array<string,mixed>>>
     */
    private function findingsByRun(int $projectId, array $records): array
    {
        $byInstance = $this->instanceValues($projectId, $records, self::FINDING_FIELDS);

        // Finding order on a statement follows the REDCap instance order, which is finding order.
        ksort($byInstance, SORT_NATURAL);

        $byRun = [];
        foreach ($byInstance as $values) {
            $runId = trim((string) ($values['finding_scan_run'] ?? ''));
            if ($runId === '') {
                continue;
            }

            $evidence = json_decode((string) ($values['finding_evidence_json'] ?? ''), true);

            $byRun[$runId][] = [
                'finding_id'             => (string) ($values['finding_id'] ?? ''),
                'concern_type'           => (string) ($values['finding_concern_type'] ?? ''),
                'urgency'                => (string) ($values['finding_urgency'] ?? ''),
                'review_status'          => (string) ($values['review_status'] ?? ''),
                'corrected_concern_type' => (string) ($values['review_corrected_concern_type'] ?? ''),
                'corrected_urgency'      => (string) ($values['review_corrected_urgency'] ?? ''),
                'evidence'               => is_array($evidence) ? $evidence : [],
            ];
        }

        return $byRun;
    }

    /**
     * The instrument's stored instances, keyed "record|event_id" then instance.
     *
     * @param list<string> $records
     * @return array<string,array<int,array<string,string>>>
     */
    private function existingInstances(int $projectId, array $records): array
    {
        $existing = [];

        foreach ($this->instanceValues($projectId, $records, TranscriptRecordBuilder::FIELDS) as $key => $values) {
            [$record, $eventId, $instance] = explode('|', $key);
            $existing[$record . '|' . $eventId][(int) $instance] = $values;
        }

        return $existing;
    }

    /**
     * Raw EAV values for some fields, assembled per "record|event_id|instance". Straight at the data
     * table rather than getData(), which would also return every non-repeating field of the record.
     *
     * @param list<string> $records
     * @param list<string> $fields
     * @return array<string,array<string,string>>
     */
    private function instanceValues(int $projectId, array $records, array $fields): array
    {
        if ($records === []) {
            return [];
        }

        $result = $this->module->query(
            'SELECT record, event_id, instance, field_name AS f, value AS v FROM '
            . \Records::getDataTable($projectId)
            . ' WHERE project_id = ? AND record IN (' . implode(',', array_fill(0, count($records), '?')) . ')'
            . ' AND field_name IN (' . implode(',', array_fill(0, count($fields), '?')) . ')',
            array_merge([$projectId], $records, $fields)
        );

        $values = [];
        while ($row = $result->fetch_assoc()) {
            // REDCap stores the first repeat instance as NULL.
            $instance = $row['instance'] === null ? 1 : (int) $row['instance'];
            $values[$row['record'] . '|' . (int) $row['event_id'] . '|' . $instance][(string) $row['f']]
                = (string) $row['v'];
        }

        return $values;
    }

    /** @param array<string,mixed> $summary */
    private function bump(array &$summary, string $reason): void
    {
        $summary['skipped'][$reason] = ($summary['skipped'][$reason] ?? 0) + 1;
    }
}
