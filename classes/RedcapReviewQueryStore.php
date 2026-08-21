<?php

namespace Stanford\MICA;

require_once __DIR__ . "/CanonicalJson.php";
require_once __DIR__ . "/DispositionService.php";
require_once __DIR__ . "/RedcapScanResultStore.php";
require_once __DIR__ . "/ReviewQueryStoreInterface.php";
require_once __DIR__ . "/ScanJobStateMachine.php";
require_once __DIR__ . "/RedcapTranscriptStore.php";

/**
 * The dashboard's reads: scan jobs joined to their findings, and one session in full.
 *
 * ## A session with no findings still appears
 *
 * The queue is built from **jobs**, not from findings, with findings left-joined on. A clean screen
 * has no finding instances at all, and a job whose placeholder write failed has none either - and
 * both still have to be visible. Building the queue from findings would make "nothing was found"
 * and "nobody looked" both render as an empty list, which is the exact confusion this whole
 * pipeline is designed to prevent.
 *
 * ## Reads are scoped to the project, always
 *
 * Every statement here carries `project_id`. The entity tables are global - `mica_scan_job` holds
 * every project's queue - so a query that forgot it would show one study's findings to another's
 * reviewers.
 */
class RedcapReviewQueryStore implements ReviewQueryStoreInterface
{
    private const JOBS = 'redcap_entity_mica_scan_job';
    private const RUNS = 'redcap_entity_mica_scan_run';
    private const AUDIT = 'redcap_entity_mica_audit_event';

    /** Fields read off a finding instance for the queue. Summary and evidence are session-view only. */
    private const QUEUE_FIELDS = [
        'finding_id',
        'finding_index',
        'finding_concern_type',
        'finding_urgency',
        'review_status',
        'review_reviewer',
        'review_reviewed_at',
        'review_corrected_urgency',
        'review_lock_version',
    ];

    private MICA $module;

    public function __construct(MICA $module)
    {
        $this->module = $module;
    }

    public function queueRows(string $projectId): array
    {
        return $this->rowsFor($projectId, null);
    }

    public function historyRows(string $projectId): array
    {
        // Same shape as the queue: history is every session including the zero-finding and failed
        // ones, so there is nothing to add - the difference is what the caller does with it.
        return $this->rowsFor($projectId, null);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rowsFor(string $projectId, ?string $record): array
    {
        $sql = 'SELECT j.id AS job_id, j.record, j.instance, j.event_id, j.session_type, '
            . 'j.status AS job_status, j.attempts, j.last_error AS job_last_error, '
            . 'j.transcript_ref, j.created, r.run_status, r.resolved_model, r.id AS scan_run_id '
            . 'FROM ' . self::JOBS . ' j '
            // The latest run for the job. A correlated subquery rather than a join on MAX(id),
            // because retries mean several runs per job and the newest is the one that decided the
            // job's current state.
            . 'LEFT JOIN ' . self::RUNS . ' r ON r.id = ('
            . '  SELECT MAX(r2.id) FROM ' . self::RUNS . ' r2 WHERE r2.job_id = j.id'
            . ') '
            . 'WHERE j.project_id = ?';

        $params = [$projectId];

        if ($record !== null) {
            $sql .= ' AND j.record = ?';
            $params[] = $record;
        }

        $sql .= ' ORDER BY j.id DESC';

        $result = $this->module->query($sql, $params);

        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
        }

        if ($jobs === []) {
            return [];
        }

        $findings = $this->findingsFor($projectId, array_unique(array_column($jobs, 'record')));
        $rows = [];

        foreach ($jobs as $job) {
            $key = $job['record'] . '|' . (int) $job['event_id'];
            $forSession = $findings[$key] ?? [];

            if ($forSession === []) {
                // A job with no findings: a clean screen, or one whose placeholder could not be
                // written. Either way it is a row - see the class comment.
                $rows[] = $this->shape($job, []);
                continue;
            }

            foreach ($forSession as $finding) {
                $rows[] = $this->shape($job, $finding);
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,string> $finding
     * @return array<string,mixed>
     */
    private function shape(array $job, array $finding): array
    {
        return [
            'job_id'          => (int) $job['job_id'],
            'scan_run_id'     => $job['scan_run_id'] === null ? null : (int) $job['scan_run_id'],
            'record'          => (string) $job['record'],
            'instance'        => (int) $job['instance'],
            'event_id'        => (int) $job['event_id'],
            'session_type'    => (string) $job['session_type'],
            'job_status'      => (string) $job['job_status'],
            'attempts'        => (int) $job['attempts'],
            // The pair that answers different questions - see ScanRunner's class comment.
            'run_status'      => $job['run_status'] === null ? null : (string) $job['run_status'],
            'job_last_error'  => $job['job_last_error'] === null ? '' : (string) $job['job_last_error'],
            'resolved_model'  => $job['resolved_model'] === null ? '' : (string) $job['resolved_model'],
            'transcript_ref'  => 'T' . (int) $job['transcript_ref'],
            'created'         => (int) $job['created'],

            // Null-ish rather than absent when there is no finding, so the client never has to
            // branch on key existence.
            'finding_id'               => $finding['finding_id'] ?? null,
            'finding_instance'         => isset($finding['__instance']) ? (int) $finding['__instance'] : null,
            'finding_concern_type'     => $finding['finding_concern_type'] ?? null,
            'finding_urgency'          => $finding['finding_urgency'] ?? null,
            'review_status'            => $finding['review_status'] ?? null,
            'review_reviewer'          => $finding['review_reviewer'] ?? '',
            'review_reviewed_at'       => $finding['review_reviewed_at'] ?? '',
            'review_corrected_urgency' => $finding['review_corrected_urgency'] ?? '',
            'review_lock_version'      => (int) ($finding['review_lock_version'] ?? 0),
        ];
    }

    /**
     * Finding instances for a set of records, keyed by "record|event_id".
     *
     * @param string[] $records
     * @return array<string,list<array<string,string>>>
     */
    private function findingsFor(string $projectId, array $records): array
    {
        if ($records === []) {
            return [];
        }

        $result = $this->module->query(
            'SELECT record, event_id, instance, field_name AS f, value AS v FROM '
            . \Records::getDataTable((int) $projectId)
            . ' WHERE project_id = ? AND record IN (' . implode(',', array_fill(0, count($records), '?')) . ')'
            . ' AND field_name IN (' . implode(',', array_fill(0, count(self::QUEUE_FIELDS), '?')) . ')',
            array_merge([$projectId], array_values($records), self::QUEUE_FIELDS)
        );

        // Rebuilt per (record, event, instance) - the EAV shape means one row per field.
        $byInstance = [];
        while ($row = $result->fetch_assoc()) {
            // REDCap stores the first repeat instance as NULL.
            $instance = $row['instance'] === null ? 1 : (int) $row['instance'];
            $key = $row['record'] . '|' . (int) $row['event_id'];

            $byInstance[$key][$instance]['__instance'] = (string) $instance;
            $byInstance[$key][$instance][(string) $row['f']] = (string) $row['v'];
        }

        $out = [];
        foreach ($byInstance as $key => $instances) {
            ksort($instances);
            $out[$key] = array_values($instances);
        }

        return $out;
    }

    public function session(string $projectId, string $record, int $eventId, int $instance): ?array
    {
        $rows = array_values(array_filter(
            $this->rowsFor($projectId, $record),
            static fn(array $r): bool => $r['event_id'] === $eventId && $r['instance'] === $instance
        ));

        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $transcript = $this->transcript($projectId, (int) substr($first['transcript_ref'], 1));

        // Findings for the session view carry the fields the queue omits - the summary, the evidence
        // the dashboard highlights, and the model's recommendations.
        $detail = $this->findingDetail($projectId, $record, $eventId);

        return [
            'record'          => $record,
            'event_id'        => $eventId,
            'instance'        => $instance,
            'session_type'    => $first['session_type'],
            'transcript_ref'  => $first['transcript_ref'],
            // Both, deliberately. An `ok` run under a job in manual_review_required is a scan that
            // worked and a release that did not; showing only the run would read as a completed
            // review.
            'run_status'      => $first['run_status'],
            'job_status'      => $first['job_status'],
            'job_last_error'  => $first['job_last_error'],
            'resolved_model'  => $first['resolved_model'],
            'attempts'        => $first['attempts'],
            'scan_run_id'     => $first['scan_run_id'],
            'messages'        => $transcript['messages'] ?? [],
            'started_at'      => $transcript['session_started_at'] ?? null,
            'ended_at'        => $transcript['session_ended_at'] ?? null,
            'findings'        => $detail,
        ];
    }

    /**
     * The finalized transcript, re-joined and hash-verified.
     *
     * Verified here as well as in ScanRunner: a reviewer reading a transcript to check a quote is
     * relying on it being the bytes that were scanned, and a corrupt one would make them think the
     * model fabricated evidence.
     *
     * @return array<string,mixed>
     */
    private function transcript(string $projectId, int $logId): array
    {
        $params = (new RedcapTranscriptStore($this->module))->readTranscript($projectId, $logId);

        if ($params === null) {
            return ['messages' => [], 'integrity' => 'missing'];
        }

        $canonical = CanonicalJson::fromLogParameters($params);

        if (!hash_equals((string) ($params['transcript_sha256'] ?? ''), CanonicalJson::hash($canonical))) {
            // No messages returned at all. Showing a transcript that does not match its own hash
            // would let a reviewer verify a quote against text nobody vouched for.
            return ['messages' => [], 'integrity' => 'hash_mismatch'];
        }

        $decoded = json_decode($canonical, true);

        return is_array($decoded)
            ? $decoded + ['integrity' => 'verified']
            : ['messages' => [], 'integrity' => 'undecodable'];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function findingDetail(string $projectId, string $record, int $eventId): array
    {
        $fields = array_merge(self::QUEUE_FIELDS, [
            'finding_source_role',
            'finding_summary',
            'finding_evidence_json',
            'finding_rec_actions_json',
            'finding_rec_targets_json',
            'finding_confidence',
            'finding_scan_run',
            'review_corrected_concern_type',
            'review_rationale',
            'review_notes',
        ]);

        $result = $this->module->query(
            'SELECT instance, field_name AS f, value AS v FROM ' . \Records::getDataTable((int) $projectId)
            . ' WHERE project_id = ? AND record = ? AND event_id = ?'
            . ' AND field_name IN (' . implode(',', array_fill(0, count($fields), '?')) . ')',
            array_merge([$projectId, $record, $eventId], $fields)
        );

        $byInstance = [];
        while ($row = $result->fetch_assoc()) {
            $instance = $row['instance'] === null ? 1 : (int) $row['instance'];
            $byInstance[$instance]['instance'] = $instance;
            $byInstance[$instance][(string) $row['f']] = (string) $row['v'];
        }

        ksort($byInstance);

        // The JSON columns are decoded here rather than in the client: the dashboard computes
        // evidence highlight offsets from `exact_quote`, and a client that had to parse a string
        // out of a string would be one `JSON.parse` away from a silent blank panel.
        return array_values(array_map(static function (array $finding): array {
            foreach (['finding_evidence_json', 'finding_rec_actions_json', 'finding_rec_targets_json'] as $key) {
                $decoded = json_decode($finding[$key] ?? '[]', true);
                $finding[str_replace('_json', '', $key)] = is_array($decoded) ? $decoded : [];
                unset($finding[$key]);
            }

            $finding['review_status'] ??= DispositionService::PENDING;
            $finding['review_lock_version'] = (int) ($finding['review_lock_version'] ?? 0);

            return $finding;
        }, $byInstance));
    }

    public function auditEvents(string $projectId, int $limit): array
    {
        /**
         * Scoped to this project.
         *
         * This used to return the module-wide trail, reasoning that "filtering by project would need
         * a join per target kind, so the endpoint's role check is what gates it". That was a cost
         * argument wearing a safety argument's clothes: the role check gates WHO may read a trail,
         * not WHICH project's. On a shared REDCap a PI on one study could read another study's audit
         * rows - record ids, usernames, concern types, review statuses. `mica_audit_event` carries a
         * `project_id` now, so there is nothing to join.
         *
         * Rows written before that column existed have no project and are therefore excluded. That
         * direction is deliberate: an audit trail missing its own history is a visible gap somebody
         * asks about, and one showing another study's rows is a disclosure nobody notices.
         */
        $result = $this->module->query(
            'SELECT id, actor, actor_role, event_type, target_kind, target_id, details, created FROM '
            . self::AUDIT . ' WHERE project_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            [(int) $projectId]
        );

        $events = [];
        while ($row = $result->fetch_assoc()) {
            $row['details'] = json_decode((string) $row['details'], true) ?: [];
            $events[] = $row;
        }

        return $events;
    }
}
