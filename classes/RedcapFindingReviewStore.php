<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ProjectContext.php";
require_once __DIR__ . "/FindingReviewStoreInterface.php";
require_once __DIR__ . "/RedcapScanResultStore.php";
require_once __DIR__ . "/TranscriptException.php";

/**
 * Reads and writes one `mica_safety_finding` instance's review fields.
 *
 * ## `overwrite`, not `normal`, and this is not a detail
 *
 * `REDCap::saveData`'s `normal` behaviour **skips empty values**. That is usually what you want and
 * here it is a bug: a reviewer who sets `review_corrected_urgency`, then decides on reflection that
 * the model was right, cannot clear it. The empty value is ignored, the stale correction survives,
 * and a confirmed finding carries a correction its reviewer explicitly withdrew.
 *
 * So this writes with `overwrite`, which means an empty value in the payload really does blank the
 * field - and that in turn means the payload has to be exact. `DispositionService` builds it from an
 * allowlist and this method refuses anything outside it, so `overwrite` can only ever reach the
 * review fields the caller named. It cannot touch a `finding_*` field, an `action_*` field, or a
 * review field the caller did not mention.
 */
class RedcapFindingReviewStore implements FindingReviewStoreInterface
{
    private const INSTRUMENT = RedcapScanResultStore::INSTRUMENT;

    private MICA $module;

    public function __construct(MICA $module)
    {
        $this->module = $module;
    }

    public function readFinding(string $projectId, string $record, int $eventId, int $instance): ?array
    {
        // Straight at the data table rather than through getData(): this needs one instance of one
        // repeating form, and it runs on every disposition. `instance` is NULL for REDCap's first
        // repeat instance, which is why the comparison is not a plain equality.
        $result = $this->module->query(
            'SELECT d.field_name AS f, d.value AS v FROM ' . \Records::getDataTable((int) $projectId) . ' d '
            . 'JOIN redcap_metadata m ON m.project_id = d.project_id AND m.field_name = d.field_name '
            . 'WHERE d.project_id = ? AND d.record = ? AND d.event_id = ? AND m.form_name = ? '
            . 'AND ' . ($instance <= 1 ? '(d.instance IS NULL OR d.instance = 1)' : 'd.instance = ?'),
            $instance <= 1
                ? [$projectId, $record, $eventId, self::INSTRUMENT]
                : [$projectId, $record, $eventId, self::INSTRUMENT, $instance]
        );

        $fields = [];
        while ($row = $result->fetch_assoc()) {
            $fields[(string) $row['f']] = (string) $row['v'];
        }

        if ($fields === []) {
            return null;
        }

        // Absent rather than zero when the instance predates the column: an unset lock version must
        // not read as "version 0 and current", because that would let a stale write through.
        $fields['review_lock_version'] ??= '0';

        return $fields;
    }

    public function writeReviewFields(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        array $fields
    ): void {
        if ($fields === []) {
            return;
        }

        // Belt to DispositionService's braces. `overwrite` blanks whatever it is given, so the one
        // thing that must never be wrong is which fields are in the payload - and this is the last
        // point before REDCap. A finding_* key here would silently rewrite the model's own output.
        foreach (array_keys($fields) as $field) {
            if (!in_array($field, DispositionService::WRITABLE, true)) {
                throw new \LogicException(
                    "Refusing to write \"$field\" to a finding instance: only review fields may "
                    . 'change after creation, and this write uses overwrite semantics.'
                );
            }
        }

        $row = [
            ProjectContext::for($projectId)   => $record,
            'redcap_event_name'              => ProjectContext::uniqueEventName($projectId, (int) $eventId),
            'redcap_repeat_instrument'       => self::INSTRUMENT,
            'redcap_repeat_instance'         => $instance,
        ] + $fields;

        $response = \REDCap::saveData(
            (int) $projectId,
            'json',
            json_encode([$row]),
            // See the class comment: `normal` would make a withdrawn correction unclearable.
            'overwrite'
        );

        if (!empty($response['errors'])) {
            throw new TranscriptException(
                'REDCap::saveData refused the disposition, so the decision was not recorded: '
                . (is_array($response['errors']) ? implode('; ', $response['errors']) : $response['errors'])
            );
        }
    }
}
