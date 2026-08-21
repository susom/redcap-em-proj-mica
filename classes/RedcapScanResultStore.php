<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ProjectContext.php";
require_once __DIR__ . "/ScanResultStoreInterface.php";
require_once __DIR__ . "/RedcapEntityLoader.php";
require_once __DIR__ . "/TranscriptException.php";

/**
 * Real `mica_scan_run` rows and `mica_safety_finding` instances.
 *
 * The run row goes through the Entity framework (insert-only; a retry is a new row) and the finding
 * instances through `REDCap::saveData`, so the study team gets native change logging, exports and
 * user-rights control over the review workflow - which is the whole reason findings live in REDCap
 * fields rather than in an entity table (02-data-model.md §1).
 */
class RedcapScanResultStore implements ScanResultStoreInterface
{
    public const INSTRUMENT = 'mica_safety_finding';

    private const ENTITY = 'mica_scan_run';

    private MICA $module;

    public function __construct(MICA $module)
    {
        $this->module = $module;
    }

    public function insertRun(array $data): int
    {
        RedcapEntityLoader::ensureLoaded();

        $factory = new \REDCapEntity\EntityFactory();
        $entity = $factory->create(self::ENTITY, $data);

        if ($entity === false) {
            // No fallback and no swallow. Losing the run row means losing the authoritative copy of
            // the model output - the thing that makes every other failure here recoverable.
            throw new EntitySchemaException(
                'Could not record the scan run, so the verbatim model output would be lost: '
                . json_encode($factory->errors ?: 'no error detail from the Entity framework')
            );
        }

        return (int) $entity->getId();
    }

    public function findingInstrumentExists(string $projectId): bool
    {
        // The instrument exists iff it has fields. Checking redcap_metadata rather than
        // getInstrumentNames() because it is one parameterized query with no project object needed
        // - this runs from cron.
        $result = $this->module->query(
            'SELECT 1 FROM redcap_metadata WHERE project_id = ? AND form_name = ? LIMIT 1',
            [$projectId, self::INSTRUMENT]
        );

        return (bool) $result->fetch_assoc();
    }

    /**
     * MAX(instance) + 1, treating REDCap's NULL-for-the-first-instance as 1.
     *
     * MAX rather than COUNT: a deleted instance would make a count-based next collide with a live
     * one and overwrite a finding an RA had already dispositioned.
     *
     * MAX and COUNT in the same query, because MAX alone cannot answer this. REDCap stores the
     * first repeat instance as NULL, so a record holding only instance 1 has MAX(instance) IS NULL -
     * indistinguishable from an empty record by the aggregate alone, and COALESCE(MAX, 0) + 1 would
     * return 1 for both, colliding with the existing instance in the second case. The COUNT breaks
     * the tie. (An earlier version had two branches, one of which was dead: MAX over an empty set
     * returns a row with NULL, never no row, so its `$row === null` guard could not fire.)
     *
     * Residual race, stated rather than engineered around: two jobs for the *same record* claimed by
     * two concurrent cron passes would both compute the same next instance. Within one pass they are
     * processed sequentially, so the second read sees the first write; REDCap does not overlap its
     * own cron by default. If overlap ever becomes possible, this needs a row lock, not a bigger
     * comment.
     */
    public function nextFindingInstance(string $projectId, string $record, int $eventId): int
    {
        $result = $this->module->query(
            'SELECT MAX(instance) AS mx, COUNT(*) AS n FROM ' . \Records::getDataTable((int) $projectId)
            . ' WHERE project_id = ? AND record = ? AND event_id = ? AND field_name = ?',
            [$projectId, $record, $eventId, self::INSTRUMENT . '_complete']
        );

        $row = $result->fetch_assoc() ?: ['mx' => null, 'n' => 0];

        if ($row['mx'] !== null) {
            return ((int) $row['mx']) + 1;
        }

        // No numbered instances. Either the record has none at all (start at 1) or it holds exactly
        // the NULL-numbered first instance (start at 2).
        return ((int) $row['n']) > 0 ? 2 : 1;
    }

    public function writeFindingInstances(
        string $projectId,
        string $record,
        int $eventId,
        int $firstInstance,
        array $instances
    ): void {
        if ($instances === []) {
            return;
        }

        // Not REDCap::getRecordIdField(): that reads a global and throws from cron, which is
        // exactly where the scan worker writes findings. See ProjectContext.
        $primary = ProjectContext::for($projectId);
        $eventName = ProjectContext::uniqueEventName($projectId, (int) $eventId);
        $rows = [];

        foreach ($instances as $offset => $fields) {
            $rows[] = [
                $primary                          => $record,
                'redcap_event_name'               => $eventName,
                'redcap_repeat_instrument'        => self::INSTRUMENT,
                'redcap_repeat_instance'          => $firstInstance + $offset,
                self::INSTRUMENT . '_complete'    => '2',
            ] + $fields;
        }

        $response = \REDCap::saveData(
            (int) $projectId,
            'json',
            json_encode($rows),
            // Findings are created once and never re-written by this path, so `normal` is right:
            // `overwrite` would let a re-run blank a field an RA had filled in.
            'normal'
        );

        if (!empty($response['errors'])) {
            throw new TranscriptException(
                'REDCap::saveData refused the finding instances, so none of them are trusted and '
                . 'the scan is failed to manual review rather than partially released: '
                . (is_array($response['errors']) ? implode('; ', $response['errors']) : $response['errors'])
            );
        }

        // A partial write is the failure mode this exists to catch: three findings out of four is
        // worse than none, because the queue would show the session as reviewed.
        $written = (int) ($response['item_count'] ?? count($rows));
        if ($written < count($rows)) {
            throw new TranscriptException(sprintf(
                'Only %d of %d finding instances were saved. Nothing is released; the scan is failed '
                . 'to manual review so the session is not shown as reviewed with findings missing.',
                $written,
                count($rows)
            ));
        }
    }

    public function findingsReleasedForJob(string $projectId, string $record, int $jobId): bool
    {
        // Two queries rather than joining `d.value` (varchar) to `r.id` (int): the coercion works and
        // then defeats both indexes, on a table that is the biggest one in any REDCap project.
        $runs = [];
        $result = $this->module->query(
            'SELECT id FROM redcap_entity_mica_scan_run WHERE job_id = ?',
            [$jobId]
        );
        while ($row = $result->fetch_assoc()) {
            $runs[] = (string) $row['id'];
        }

        if ($runs === []) {
            return false;
        }

        $found = $this->module->query(
            'SELECT 1 FROM ' . \Records::getDataTable((int) $projectId)
            . ' WHERE project_id = ? AND record = ? AND field_name = ? AND value IN ('
            . implode(',', array_fill(0, count($runs), '?')) . ') LIMIT 1',
            array_merge([$projectId, $record, 'finding_scan_run'], $runs)
        );

        return (bool) $found->fetch_assoc();
    }

    public function existingFindingIds(string $projectId, string $record): array
    {
        $result = $this->module->query(
            'SELECT DISTINCT value AS v FROM ' . \Records::getDataTable((int) $projectId)
            . ' WHERE project_id = ? AND record = ? AND field_name = ?',
            [$projectId, $record, 'finding_id']
        );

        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (string) $row['v'];
        }

        return $ids;
    }
}
