<?php

namespace Stanford\MICA;

/**
 * Every participant-data read and every mutation in the review dashboard, recorded.
 *
 * ## "Never transcript text" is enforced, not requested
 *
 * `02-data-model.md §1.1` says `details` carries "minimum necessary; never transcript text". A
 * comment cannot enforce that, and an audit trail that accumulates quoted participant speech is a
 * second uncontrolled copy of the most sensitive data in the study - in a table with no
 * user-rights model of its own, retained forever, and exportable by anyone who can read it.
 *
 * So `details` accepts **only declared keys**, and anything else is rejected as a programming
 * error. The allowlist is small and boring by design: ids, counts, statuses, enum values. If a
 * future event genuinely needs a new key, adding it is a deliberate one-line decision that shows up
 * in review, which is exactly the friction that belongs here.
 *
 * ## Append-only, and it must not be able to break the thing it is auditing
 *
 * A failed audit write is logged and swallowed rather than thrown. That is the opposite of this
 * module's usual fail-closed stance, and it is deliberate for `read` events: refusing to *show* an
 * RA a critical finding because the audit row could not be written would trade a real safety risk
 * for a bookkeeping one. Mutations are different - `strict()` exists for those, and the disposition
 * path uses it, because an undocumented change to a clinical decision is not acceptable.
 */
class AuditLogger
{
    // Event types. Enumerated so the dashboard's filters and this writer cannot drift.
    public const QUEUE_VIEW    = 'queue_view';
    public const SESSION_VIEW  = 'session_view';
    public const DISPOSITION   = 'disposition';
    public const ACTION_SENT   = 'action_sent';
    public const POLICY_CHANGE = 'policy_change';
    public const HISTORY_VIEW  = 'history_view';
    public const AUDIT_VIEW    = 'audit_view';
    public const ACCESS_DENIED = 'access_denied';
    public const SCAN_GAVE_UP  = 'scan_gave_up';

    /** The actor recorded when there is no logged-in user - a cron, not a person. */
    public const SYSTEM_ACTOR = '[cron]';

    /**
     * Keys permitted in `details`. Nothing here can hold free text a participant wrote.
     *
     * `reason` and `note` are absent on purpose: both are exactly where a well-meaning caller would
     * paste a quote. The rationale an RA types is on the finding instance, under REDCap's own
     * user-rights and change history, which is where it belongs.
     */
    public const ALLOWED_DETAIL_KEYS = [
        'finding_id',          // uuid
        'job_id',              // int
        'scan_run_id',         // int
        'transcript_ref',      // T<log_id>
        'record',              // the record id - already in target_id for most events
        'instance',            // int
        'event_id',            // int
        'session_type',        // enum
        'review_status',       // enum
        'previous_status',     // enum
        'urgency',             // enum
        'corrected_urgency',   // enum
        'concern_type',        // enum
        'corrected_concern',   // enum
        'action_types',        // list of enum values
        'recipient_count',     // int - who, not what
        'finding_count',       // int
        'result_count',        // int
        'filters',             // the queue filters applied, which are enum values and dates
        'lock_version',        // int
        'run_status',          // enum
        'attempts',            // int
        'denied_action',       // the action name, for ACCESS_DENIED
        'rationale_length',    // int - that a rationale was given, never what it said
        'deidentified',        // bool - whether the view was aggregate-only
    ];

    /** Length ceiling for any single detail value, as a second line of defence. */
    private const MAX_VALUE_LENGTH = 200;

    private AuditStoreInterface $store;
    private RoleService $roles;
    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void|null $logger */
    public function __construct(AuditStoreInterface $store, RoleService $roles, ?callable $logger = null)
    {
        $this->store = $store;
        $this->roles = $roles;
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    /**
     * Record an event. Never throws: see the class comment on why a read path must not be breakable
     * by its own audit row.
     *
     * @param array<string,mixed> $details
     */
    public function record(
        ?string $actor,
        string $eventType,
        string $targetKind = '',
        string $targetId = '',
        array $details = []
    ): void {
        try {
            $this->strict($actor, $eventType, $targetKind, $targetId, $details);
        } catch (\Throwable $e) {
            // Loud, because an audit trail with holes is only useful if the holes are known.
            $this->log(sprintf(
                'AUDIT WRITE FAILED for %s on %s/%s: %s',
                $eventType,
                $targetKind,
                $targetId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Record an event, throwing if it cannot be written.
     *
     * Used for mutations. An undocumented change to a clinical decision is not an acceptable
     * outcome, so the change is refused rather than made unrecorded.
     *
     * @param array<string,mixed> $details
     * @throws \InvalidArgumentException on a disallowed detail key - a programming error
     */
    public function strict(
        ?string $actor,
        string $eventType,
        string $targetKind = '',
        string $targetId = '',
        array $details = []
    ): int {
        $this->store->insertAuditEvent([
            // Never null: an audit row with no actor is not an audit row. A cron gets an explicit
            // sentinel rather than an empty string, so "nobody was logged in" and "we forgot to
            // pass the actor" are distinguishable.
            'actor'       => $this->normaliseActor($actor),
            'actor_role'  => $this->roles->primaryRoleFor($actor),
            'event_type'  => $eventType,
            'target_kind' => $targetKind,
            'target_id'   => $targetId,
            'details'     => $this->sanitiseDetails($details),
        ]);

        return 1;
    }

    private function normaliseActor(?string $actor): string
    {
        $actor = trim((string) $actor);

        return $actor === '' ? self::SYSTEM_ACTOR : $actor;
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function sanitiseDetails(array $details): array
    {
        $clean = [];

        foreach ($details as $key => $value) {
            if (!in_array($key, self::ALLOWED_DETAIL_KEYS, true)) {
                // A programming error, thrown rather than dropped: silently discarding it would
                // make the allowlist invisible, and the next person would assume their key worked.
                throw new \InvalidArgumentException(sprintf(
                    'Audit detail key "%s" is not on the allowlist. The allowlist exists so this '
                    . 'table cannot accumulate participant text; add the key deliberately in '
                    . 'AuditLogger::ALLOWED_DETAIL_KEYS if it genuinely carries no free text. '
                    . 'Allowed: %s',
                    $key,
                    implode(', ', self::ALLOWED_DETAIL_KEYS)
                ));
            }

            if (is_array($value)) {
                // One level, scalars only. A nested structure is where free text hides.
                $clean[$key] = array_map(
                    fn($v): string => $this->truncate((string) (is_scalar($v) ? $v : '?')),
                    array_values($value)
                );
                continue;
            }

            $clean[$key] = is_bool($value) || is_int($value)
                ? $value
                : $this->truncate((string) $value);
        }

        return $clean;
    }

    private function truncate(string $value): string
    {
        // Second line of defence rather than the main one: the allowlist is what stops transcript
        // text, and this stops an allowlisted key being abused to carry a paragraph.
        return mb_strlen($value) <= self::MAX_VALUE_LENGTH
            ? $value
            : mb_substr($value, 0, self::MAX_VALUE_LENGTH - 3) . '...';
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
