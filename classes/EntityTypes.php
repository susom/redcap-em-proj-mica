<?php

namespace Stanford\MICA;

/**
 * The module's REDCap Entity type declarations, as data.
 *
 * `MICA::redcap_entity_types()` returns `EntityTypes::all()` and nothing else. Keeping the
 * definitions here rather than inline in the hook is what lets the suite assert them: the
 * declarations are validated against the vendored redcap_entity framework's *real* constraints
 * (see EntityTypesTest), several of which are only discoverable by reading its source.
 *
 * Four types, per 02-data-model.md §1.1:
 *
 *   mica_scan_job     mutable queue / state machine  (the only type with an UPDATE path, and only
 *                                                     through the atomic claim - see ScanQueue)
 *   mica_scan_run     insert-only; a retry is a new row, never an edit
 *   mica_turn         insert-only; counselor-turn telemetry, no message text
 *   mica_audit_event  append-only
 *
 * Constraints learned from ../redcap_entity_v9.9.9 that this file has to respect:
 *
 * 1. `required` must be present on every property. EntityDB::buildEntityDBTable() reads
 *    `$info['required']` with no isset guard, so an omission is a PHP warning raised in the middle
 *    of CREATE TABLE. EntityTypesTest asserts the key exists everywhere.
 * 2. `date` means "epoch seconds as a real PHP int". Entity::validateDate() is
 *    `(int)$date === $date`, which a numeric *string* fails. Every writer passes int.
 * 3. `choices` is enforced, and it is a value=>label map: Entity::validateProperty() ends with
 *    `isset($info['choices'][$value])`. A list would silently reject every value.
 * 4. Property types are limited to EntityFactory::getValidPropertyTypes(); anything else marks the
 *    whole type INVALID and it never gets a table.
 * 5. `record` and `user` types carry runtime validation that reaches into REDCap
 *    (Records::recordExists() needs PROJECT_ID defined; the user type needs the username to exist
 *    in redcap_user_information). That is why `mica_audit_event.actor` is `text` - see below.
 */
class EntityTypes
{
    /** Bumped whenever a type or index below changes; gates the migration. */
    public const SCHEMA_VERSION = '2';

    /**
     * Secondary indexes and UNIQUE constraints, which redcap_entity does not create at all
     * (02-data-model.md §1). Applied idempotently by EntitySchemaManager after buildSchema().
     *
     * @return array<string,array{table:string,columns:string[],unique:bool,why:string}>
     */
    public static function indexes(): array
    {
        return [
            'uq_idem' => [
                'table'   => 'redcap_entity_mica_scan_job',
                'columns' => ['idempotency_key'],
                'unique'  => true,
                'why'     => 'One scan per finalized transcript. This is the only thing that makes '
                           . 'enqueueing idempotent under a double-submit or a retried finalize.',
            ],
            'idx_due' => [
                'table'   => 'redcap_entity_mica_scan_job',
                'columns' => ['status', 'next_attempt_at'],
                'unique'  => false,
                'why'     => 'The cron claim query filters on exactly this pair, every run.',
            ],
            'idx_job' => [
                'table'   => 'redcap_entity_mica_scan_run',
                'columns' => ['job_id'],
                'unique'  => false,
                'why'     => 'Attempt history for one job; read on every dashboard session view.',
            ],
            'idx_record' => [
                'table'   => 'redcap_entity_mica_turn',
                'columns' => ['project_id', 'record'],
                'unique'  => false,
                'why'     => 'Phase resolution reads the latest turn for a participant.',
            ],
            'idx_target' => [
                'table'   => 'redcap_entity_mica_audit_event',
                'columns' => ['target_kind', 'target_id'],
                'unique'  => false,
                'why'     => 'Audit trail for one finding / one session.',
            ],
            'idx_actor' => [
                'table'   => 'redcap_entity_mica_audit_event',
                'columns' => ['actor'],
                'unique'  => false,
                'why'     => '"What did this user touch" - the question an audit is for.',
            ],
        ];
    }

    /**
     * redcap_entity's property-type to MySQL-column mapping, transcribed from
     * EntityDB::buildEntityDBTable().
     *
     * Needed because redcap_entity can only CREATE TABLE IF NOT EXISTS - it has no ALTER path at
     * all, so adding a property to a type that already has a table does *nothing*. Migrating the
     * column is this module's job, and to do that it has to know the same mapping.
     *
     * Kept as a transcription rather than a call into the framework because the framework builds the
     * DDL string inline in a switch with no reusable accessor. EntityTypesTest pins every type this
     * module actually declares, so a divergence fails there rather than in production.
     *
     * @return array<string,string>
     */
    public static function columnTypes(): array
    {
        return [
            'user'             => 'VARCHAR(255)',
            'email'            => 'VARCHAR(255)',
            'text'             => 'VARCHAR(255)',
            'record'           => 'VARCHAR(255)',
            'entity_reference' => 'INT UNSIGNED',
            'project'          => 'INT UNSIGNED',
            'date'             => 'INT',
            'integer'          => 'INT',
            'boolean'          => 'TINYINT',
            'json'             => 'TEXT',
            'long_text'        => 'TEXT',
            'data'             => 'MEDIUMTEXT',
        ];
    }

    /**
     * The column definition for one declared property, or null if its type is unmappable.
     *
     * NOT NULL is deliberately omitted even for required properties: this is used to ADD a column to
     * a table that already holds rows, and NOT NULL without a default would be rejected outright (or
     * silently backfilled with a zero). Required-ness is enforced by Entity::validateProperty() on
     * every write, which is where it belongs; the column constraint would only add a second, worse
     * error message.
     */
    public static function columnDefinition(array $property): ?string
    {
        $type = strtolower((string) ($property['type'] ?? ''));

        return self::columnTypes()[$type] ?? null;
    }

    /** @return string[] every entity table this module owns */
    public static function tables(): array
    {
        return array_map(static fn(string $t): string => 'redcap_entity_' . $t, array_keys(self::all()));
    }

    /** @return array<string,array<string,mixed>> the redcap_entity_types() payload */
    public static function all(): array
    {
        return [
            'mica_scan_job'    => self::scanJob(),
            'mica_scan_run'    => self::scanRun(),
            'mica_turn'        => self::turn(),
            'mica_audit_event' => self::auditEvent(),
        ];
    }

    public static function sessionTypeChoices(): array
    {
        return ['baseline' => 'Baseline (Day 1, ED)', 'booster' => 'Booster (Month 3)'];
    }

    public static function jobStatusChoices(): array
    {
        return [
            'queued'                 => 'Queued',
            'scanning'               => 'Scanning',
            'ready_for_review'       => 'Ready for review',
            'under_review'           => 'Under review',
            'review_complete'        => 'Review complete',
            'scan_failed'            => 'Scan failed',
            'manual_review_required' => 'Manual review required',
        ];
    }

    /**
     * The scan failure taxonomy (stage-4-safetyscan-runner.md §4.2.5), plus `ok`.
     *
     * `no_supported_concern` is deliberately absent: a clean scan is `ok` with zero findings, not
     * its own run status. Collapsing "the scanner found nothing" into "the scanner did not run"
     * is precisely the negative-screen failure the handoff forbids.
     */
    public static function runStatusChoices(): array
    {
        return [
            'ok'                 => 'OK',
            'timeout'            => 'Timed out',
            'refusal'            => 'Model refused',
            'invalid_json'       => 'Invalid JSON',
            'schema_invalid'     => 'Schema-invalid output',
            'citation_mismatch'  => 'Evidence quote did not match the transcript',
            'content_filter'     => 'Blocked by a content filter',
            'service_error'      => 'Service error',
        ];
    }

    public static function phaseChoices(): array
    {
        return [
            'engage' => 'Engage',
            'focus'  => 'Focus',
            'evoke'  => 'Evoke',
            'plan'   => 'Plan',
            'close'  => 'Close',
        ];
    }

    private static function scanJob(): array
    {
        return [
            'label'        => 'MICA scan job',
            'label_plural' => 'MICA scan jobs',
            'icon'         => 'gear',
            'properties'   => [
                'project_id' => [
                    'name'     => 'Project',
                    'type'     => 'project',
                    'required' => true,
                ],
                'record' => [
                    'name'     => 'Record',
                    'type'     => 'record',
                    'required' => true,
                ],
                // Included per 02-data-model.md §3's 2026-08-17 note: the session instruments are
                // repeating, so a session is keyed by (record, event, instance). Carrying the
                // instance is a superset that is correct whichever way the study answers the
                // "is multi-session-per-window intended" question, and it is what keeps two
                // sessions in one window from colliding on the idempotency key.
                'instance' => [
                    'name'     => 'Repeat instance',
                    'type'     => 'integer',
                    'required' => true,
                ],
                // The session's event. Findings are written to (record, event, instance), so without
                // this the writer has no event to target - and REDCap::getEventNames() for event 0
                // yields a name that is either wrong or rejected. Missed on the first pass exactly
                // as `instance` nearly was, and invisible because the review instrument does not
                // exist yet on PID 257, so the write never ran.
                'event_id' => [
                    'name'     => 'Event',
                    'type'     => 'integer',
                    'required' => true,
                ],
                'session_type' => [
                    'name'     => 'Session type',
                    'type'     => 'text',
                    'choices'  => self::sessionTypeChoices(),
                    'required' => true,
                ],
                'transcript_ref' => [
                    'name'     => 'Transcript log id',
                    'type'     => 'integer',
                    'required' => true,
                ],
                'idempotency_key' => [
                    'name'     => 'Idempotency key',
                    'type'     => 'text',
                    'required' => true,
                ],
                'status' => [
                    'name'     => 'Status',
                    'type'     => 'text',
                    'choices'  => self::jobStatusChoices(),
                    'required' => true,
                ],
                'attempts' => [
                    'name'     => 'Attempts',
                    'type'     => 'integer',
                    'required' => true,
                ],
                'next_attempt_at' => [
                    'name'     => 'Next attempt at',
                    'type'     => 'date',
                    'required' => true,
                ],
                'claimed_by' => [
                    'name'     => 'Claim token',
                    'type'     => 'text',
                    'required' => false,
                ],
                'claimed_at' => [
                    'name'     => 'Claimed at',
                    'type'     => 'date',
                    'required' => false,
                ],
                'last_error' => [
                    'name'     => 'Last error',
                    'type'     => 'long_text',
                    'required' => false,
                ],
            ],
            'special_keys' => [
                'label'   => 'idempotency_key',
                'project' => 'project_id',
            ],
        ];
    }

    private static function scanRun(): array
    {
        return [
            'label'        => 'MICA scan run',
            'label_plural' => 'MICA scan runs',
            'icon'         => 'report',
            'properties'   => [
                'job_id' => [
                    'name'        => 'Scan job',
                    'type'        => 'entity_reference',
                    'entity_type' => 'mica_scan_job',
                    'required'    => true,
                ],
                'attempt' => [
                    'name'     => 'Attempt',
                    'type'     => 'integer',
                    'required' => true,
                ],
                'model_alias' => [
                    'name'     => 'Requested model alias',
                    'type'     => 'text',
                    'required' => true,
                ],
                'resolved_model' => [
                    'name'     => 'Resolved deployment',
                    'type'     => 'text',
                    'required' => false,
                ],
                'prompt_sha256' => [
                    'name'     => 'Prompt SHA-256',
                    'type'     => 'text',
                    'required' => true,
                ],
                'input_schema_sha256' => [
                    'name'     => 'Input schema SHA-256',
                    'type'     => 'text',
                    'required' => true,
                ],
                'output_schema_sha256' => [
                    'name'     => 'Output schema SHA-256',
                    'type'     => 'text',
                    'required' => true,
                ],
                'app_version' => [
                    'name'     => 'App version',
                    'type'     => 'text',
                    'required' => true,
                ],
                'latency_ms' => [
                    'name'     => 'Latency (ms)',
                    'type'     => 'integer',
                    'required' => false,
                ],
                'prompt_tokens' => [
                    'name'     => 'Prompt tokens',
                    'type'     => 'integer',
                    'required' => false,
                ],
                'completion_tokens' => [
                    'name'     => 'Completion tokens',
                    'type'     => 'integer',
                    'required' => false,
                ],
                'run_status' => [
                    'name'     => 'Run status',
                    'type'     => 'text',
                    'choices'  => self::runStatusChoices(),
                    'required' => true,
                ],
                // `data` (MEDIUMTEXT), not `json`: this is the authoritative immutable findings
                // record and must survive byte-for-byte as the model emitted it. The `json` type
                // round-trips through json_decode/json_encode in Entity::getData()/setData(),
                // which would silently reformat it.
                'model_output_json' => [
                    'name'     => 'Verbatim model output',
                    'type'     => 'data',
                    'required' => false,
                ],
            ],
            'special_keys' => [
                'label' => 'run_status',
            ],
        ];
    }

    private static function turn(): array
    {
        return [
            'label'        => 'MICA counselor turn',
            'label_plural' => 'MICA counselor turns',
            'icon'         => 'balloons',
            'properties'   => [
                'project_id' => [
                    'name'     => 'Project',
                    'type'     => 'project',
                    'required' => true,
                ],
                'record' => [
                    'name'     => 'Record',
                    'type'     => 'record',
                    'required' => true,
                ],
                'instance' => [
                    'name'     => 'Repeat instance',
                    'type'     => 'integer',
                    'required' => true,
                ],
                // The session's event. Findings are written to (record, event, instance), so without
                // this the writer has no event to target - and REDCap::getEventNames() for event 0
                // yields a name that is either wrong or rejected. Missed on the first pass exactly
                // as `instance` nearly was, and invisible because the review instrument does not
                // exist yet on PID 257, so the write never ran.
                'event_id' => [
                    'name'     => 'Event',
                    'type'     => 'integer',
                    'required' => true,
                ],
                'session_type' => [
                    'name'     => 'Session type',
                    'type'     => 'text',
                    'choices'  => self::sessionTypeChoices(),
                    'required' => true,
                ],
                'turn_index' => [
                    'name'     => 'Turn index',
                    'type'     => 'integer',
                    'required' => true,
                ],
                // Pointers into the EM-log message store ("L123,L124"), never message text.
                'message_log_ids' => [
                    'name'     => 'Message log ids',
                    'type'     => 'text',
                    'required' => false,
                ],
                'model_alias' => [
                    'name'     => 'Requested model alias',
                    'type'     => 'text',
                    'required' => true,
                ],
                'resolved_model' => [
                    'name'     => 'Resolved deployment',
                    'type'     => 'text',
                    'required' => false,
                ],
                'prompt_sha256' => [
                    'name'     => 'Prompt SHA-256',
                    'type'     => 'text',
                    'required' => true,
                ],
                'wrapper_schema_sha256' => [
                    'name'     => 'Wrapper schema SHA-256',
                    'type'     => 'text',
                    'required' => true,
                ],
                'output_schema_sha256' => [
                    'name'     => 'Output schema SHA-256',
                    'type'     => 'text',
                    'required' => true,
                ],
                'app_version' => [
                    'name'     => 'App version',
                    'type'     => 'text',
                    'required' => true,
                ],
                'params_json' => [
                    'name'     => 'Accepted parameters',
                    'type'     => 'json',
                    'required' => false,
                ],
                'latency_ms' => [
                    'name'     => 'Latency (ms)',
                    'type'     => 'integer',
                    'required' => false,
                ],
                'prompt_tokens' => [
                    'name'     => 'Prompt tokens',
                    'type'     => 'integer',
                    'required' => false,
                ],
                'completion_tokens' => [
                    'name'     => 'Completion tokens',
                    'type'     => 'integer',
                    'required' => false,
                ],
                'turn_status' => [
                    'name'     => 'Turn status',
                    'type'     => 'text',
                    'choices'  => [
                        'ok'               => 'OK',
                        'retried_ok'       => 'OK after corrective retry',
                        'fallback_ok'      => 'OK on the fallback model',
                        'failed_technical' => 'Technical failure (static fallback shown)',
                        'refused'          => 'Model refused',
                        'timeout'          => 'Timed out',
                    ],
                    'required' => true,
                ],
                'gate_failures' => [
                    'name'     => 'Gate failures',
                    'type'     => 'text',
                    'required' => false,
                ],
                'phase_from' => [
                    'name'     => 'Phase from',
                    'type'     => 'text',
                    'choices'  => self::phaseChoices(),
                    'required' => false,
                ],
                'phase_to' => [
                    'name'     => 'Phase to',
                    'type'     => 'text',
                    'choices'  => self::phaseChoices(),
                    'required' => false,
                ],
                'response_strategy' => [
                    'name'     => 'Response strategy',
                    'type'     => 'text',
                    'required' => false,
                ],
                'end_session' => [
                    'name'     => 'End session',
                    'type'     => 'boolean',
                    'required' => false,
                ],
            ],
            'special_keys' => [
                'label'   => 'turn_status',
                'project' => 'project_id',
            ],
        ];
    }

    private static function auditEvent(): array
    {
        return [
            'label'        => 'MICA audit event',
            'label_plural' => 'MICA audit events',
            'icon'         => 'application_view_list',
            'properties'   => [
                // `text`, deliberately, where 02-data-model.md §1.1 says `user`. The `user` type
                // validates through RedCapDB::usernameExists(), and the scan worker's audit events
                // are written from cron with no logged-in user at all - so a `user` column would
                // make exactly the events that most need recording (a scan giving up and landing
                // in manual_review_required) impossible to write. Writers always pass an actor
                // explicitly: a real username, or an explicit system sentinel. An audit row with
                // an implicit actor is worse than one with an honest sentinel.
                'actor' => [
                    'name'     => 'Actor',
                    'type'     => 'text',
                    'required' => true,
                ],
                'actor_role' => [
                    'name'     => 'Actor role',
                    'type'     => 'text',
                    'required' => false,
                ],
                'event_type' => [
                    'name'     => 'Event type',
                    'type'     => 'text',
                    'required' => true,
                ],
                'target_kind' => [
                    'name'     => 'Target kind',
                    'type'     => 'text',
                    'required' => false,
                ],
                'target_id' => [
                    'name'     => 'Target id',
                    'type'     => 'text',
                    'required' => false,
                ],
                // Minimum necessary only. Never transcript text - asserted in AuditLogger's tests.
                'details' => [
                    'name'     => 'Details',
                    'type'     => 'json',
                    'required' => false,
                ],
            ],
            'special_keys' => [
                'label'  => 'event_type',
                'author' => 'actor',
            ],
        ];
    }
}
