<?php

namespace Stanford\MICA;

/**
 * When a session's window closes, and therefore when a participant stops being able to return to it.
 *
 * ## What decides the window
 *
 * The clock starts at the participant's **first message**, per session type, for a configurable
 * length (`ed-session-window-hours`, `booster-session-window-days`). That anchor was chosen
 * deliberately over the event's scheduled date, and it has one consequence worth stating rather than
 * discovering: **a session that was opened and never used has no first message, so it has no window
 * and this class never closes it.** Those sessions are bounded by REDCap's own per-participant link
 * time limit instead (24 h / 14 d, configured on the two host surveys). The closer counts them and
 * says how many it skipped, so "nothing was closed" and "nothing needed closing" are never the same
 * silence.
 *
 * ⚠️ That link limit does not fire until the module writes `link_expiration` at issuance
 * (18-sow-status-review.md §4 item 4). Until it does, an opened-but-unused session has no bound at
 * all from either mechanism.
 *
 * ## What "closed" means
 *
 * The host instrument's `<form>_complete` field is set to Complete. That field is the **control
 * surface**: a CRC reopens a session by setting the form status back to Incomplete on the record
 * page, which is the workflow this feature exists to support.
 *
 * It is not REDCap's enforcement. Writing `_complete` does **not** set
 * `redcap_surveys_response.completion_time`, so REDCap will still serve the survey - the refusal is
 * the module's own check on this field. Nobody reading this later should assume REDCap is blocking
 * entry.
 *
 * ## Closed once, never re-closed
 *
 * A reopen has to survive the next cron pass. If the closer simply asked "is the window past?" it
 * would set the flag straight back and the CRC's action would be undone within the hour - the
 * feature would look implemented and not work. So each closure is recorded, and a session that has
 * already been closed once is left alone regardless of its window. Reopening is a deliberate act by
 * someone with the record in front of them, and it wins.
 */
class SessionWindow
{
    public const DEFAULT_ED_HOURS = 24;
    public const DEFAULT_BOOSTER_DAYS = 14;

    /** Reasons a session is not closed on this pass. Counted and reported, never silent. */
    public const SKIP_NO_MESSAGES = 'no_messages';
    public const SKIP_WITHIN_WINDOW = 'within_window';
    public const SKIP_ALREADY_CLOSED = 'already_closed';
    public const SKIP_AMBIGUOUS = 'ambiguous_session';

    private int $edWindowSeconds;
    private int $boosterWindowSeconds;

    public function __construct(?int $edHours = null, ?int $boosterDays = null)
    {
        // Zero or negative is not "no window", it is a misconfiguration that would close every
        // session the instant it started. Fall back to the shipped default and let the launch
        // readiness/verifier surface the setting rather than acting on it.
        $this->edWindowSeconds = 3600 * ($edHours !== null && $edHours > 0 ? $edHours : self::DEFAULT_ED_HOURS);
        $this->boosterWindowSeconds = 86400
            * ($boosterDays !== null && $boosterDays > 0 ? $boosterDays : self::DEFAULT_BOOSTER_DAYS);
    }

    public function windowSeconds(string $sessionType): int
    {
        return $sessionType === SessionHostMap::BOOSTER
            ? $this->boosterWindowSeconds
            : $this->edWindowSeconds;
    }

    /**
     * Should this session be closed now?
     *
     * @param string $sessionType SessionHostMap::BASELINE|BOOSTER
     * @param int|null $firstMessageAt epoch seconds of the session's first message, null if none
     * @param bool $alreadyClosed whether a previous pass closed this session
     * @param int $now epoch seconds, injected so the decision is testable
     * @return array{close:bool,reason:?string,closesAt:?int}
     */
    public function decide(
        string $sessionType,
        ?int $firstMessageAt,
        bool $alreadyClosed,
        int $now
    ): array {
        // Checked before the window, because a reopened session's window is past by definition and
        // asking about it first would close it again.
        if ($alreadyClosed) {
            return ['close' => false, 'reason' => self::SKIP_ALREADY_CLOSED, 'closesAt' => null];
        }

        if ($firstMessageAt === null || $firstMessageAt <= 0) {
            return ['close' => false, 'reason' => self::SKIP_NO_MESSAGES, 'closesAt' => null];
        }

        $closesAt = $firstMessageAt + $this->windowSeconds($sessionType);

        if ($now < $closesAt) {
            return ['close' => false, 'reason' => self::SKIP_WITHIN_WINDOW, 'closesAt' => $closesAt];
        }

        return ['close' => true, 'reason' => null, 'closesAt' => $closesAt];
    }

    /**
     * Which open session unfinalized messages belong to, when a record has more than one.
     *
     * The message log carries `record` and `log_id` and nothing else - no event, no instance, no
     * host - so a record with two open session links has no field that says which one a conversation
     * happened in. Getting it wrong is not cosmetic: `session_type` and `setting` go into the
     * SafetyScan input, and labelling an ED baseline as a remote booster changes how a finding reads
     * clinically.
     *
     * The rule is the most recently issued link, because that is the only one a participant can
     * currently reach. In the designed flow the question does not arise - the ED window is hours and
     * the booster link is issued about three months later, so two simultaneously open MICA sessions
     * would already be a protocol violation - and the caller reports when it had to apply this rule
     * so an unexpected case is visible rather than assumed.
     *
     * @param list<array{response_id:int}> $openSessions
     * @return array{session:?array<string,mixed>,ambiguous:bool}
     */
    public static function attribute(array $openSessions): array
    {
        if ($openSessions === []) {
            return ['session' => null, 'ambiguous' => false];
        }

        $newest = $openSessions[0];
        foreach ($openSessions as $session) {
            if ((int) $session['response_id'] > (int) $newest['response_id']) {
                $newest = $session;
            }
        }

        return ['session' => $newest, 'ambiguous' => count($openSessions) > 1];
    }
}
