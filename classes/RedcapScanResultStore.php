<?php

namespace Stanford\MICA;

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

    public function nextFindingInstance(string $projectId, string $record, int $eventId): int
    {
        // MAX + 1 over what exists, rather than a count: a deleted instance would otherwise make
        // the next write collide with a live one and overwrite a finding an RA had already
        // dispositioned.
        $result = $this->module->query(
            'SELECT MAX(instance) AS mx FROM ' . \Records::getDataTable((int) $projectId)
            . ' WHERE project_id = ? AND record = ? AND event_id = ? AND field_name = ?',
            [$projectId, $record, $eventId, self::INSTRUMENT . '_complete']
        );

        $row = $result->fetch_assoc();

        // REDCap stores the first repeat instance as NULL, not 1, so an existing-but-null max still
        // means "instance 1 is taken".
        if ($row === null || !array_key_exists('mx', $row)) {
            return 1;
        }

        return $row['mx'] === null ? $this->firstInstanceFor($projectId, $record, $eventId) : ((int) $row['mx']) + 1;
    }

    private function firstInstanceFor(string $projectId, string $record, int $eventId): int
    {
        $result = $this->module->query(
            'SELECT 1 FROM ' . \Records::getDataTable((int) $projectId)
            . ' WHERE project_id = ? AND record = ? AND event_id = ? AND field_name = ? LIMIT 1',
            [$projectId, $record, $eventId, self::INSTRUMENT . '_complete']
        );

        return $result->fetch_assoc() ? 2 : 1;
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

        $primary = \REDCap::getRecordIdField();
        $eventName = \REDCap::getEventNames(true, false, $eventId);
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
