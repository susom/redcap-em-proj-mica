<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ActionFieldWriterInterface.php";
require_once __DIR__ . "/RedcapScanResultStore.php";
require_once __DIR__ . "/TranscriptException.php";

/**
 * The `action_*` fields on one finding instance.
 *
 * ## `normal`, not `overwrite`, and for the opposite reason to the review store
 *
 * Action fields accumulate. A reviewer who alerts the care team on Monday and opens a privacy review
 * on Wednesday must end up with both ticked - `overwrite` would blank Monday's checkbox because
 * Wednesday's payload does not mention it, erasing the record of a notification that actually went
 * out. `normal` skips empty values and applies non-empty ones, which is exactly the accumulate-then-
 * update behaviour this needs, and every value written here is non-empty by construction.
 *
 * ## What it refuses
 *
 * Anything that is not an action field. In particular `review_status`: a delivery path that could
 * write the review status would be able to confirm a finding on its way to notifying about it, which
 * is precisely the loop the RA-first rule exists to prevent.
 */
class RedcapActionFieldWriter implements ActionFieldWriterInterface
{
    private const INSTRUMENT = RedcapScanResultStore::INSTRUMENT;

    /** Scalar action fields this class may write. Checkbox choices are matched separately. */
    public const WRITABLE = [
        'action_initiated_by',
        'action_payload_min',
        'action_delivery_status',
        'action_ack_by',
        'action_ack_at',
        'action_completed_at',
        'action_notes',
    ];

    private MICA $module;

    public function __construct(MICA $module)
    {
        $this->module = $module;
    }

    public function writeActionFields(
        string $projectId,
        string $record,
        int $eventId,
        int $instance,
        array $fields
    ): void {
        if ($fields === []) {
            return;
        }

        foreach (array_keys($fields) as $field) {
            if (!self::isWritable($field)) {
                throw new \LogicException(
                    "Refusing to write \"$field\" through the action writer: it may only touch "
                    . 'action_* fields. Model output and review decisions are written elsewhere, on '
                    . 'purpose - see the class comment.'
                );
            }
        }

        $row = [
            \REDCap::getRecordIdField()  => $record,
            'redcap_event_name'          => \REDCap::getEventNames(true, false, $eventId),
            'redcap_repeat_instrument'   => self::INSTRUMENT,
            'redcap_repeat_instance'     => $instance,
        ] + $fields;

        $response = \REDCap::saveData(
            (int) $projectId,
            'json',
            json_encode([$row]),
            // See the class comment: overwrite would erase a previously delivered action.
            'normal'
        );

        if (!empty($response['errors'])) {
            throw new TranscriptException(
                'REDCap::saveData refused the action record, so what was sent is not documented on '
                . 'the finding: '
                . (is_array($response['errors']) ? implode('; ', $response['errors']) : $response['errors'])
            );
        }
    }

    /** A scalar action field, or one `action_types` checkbox choice. */
    public static function isWritable(string $field): bool
    {
        return in_array($field, self::WRITABLE, true)
            || preg_match('/^action_types___[a-z_]+$/', $field) === 1;
    }
}
