<?php

namespace Stanford\MICA;

require_once __DIR__ . "/SessionHostMap.php";

/**
 * Where a randomized record's Day-1 session lives - which arm, which instrument, which event.
 *
 * ## What this is for
 *
 * A CRC handing a participant into the chat, and a survey redirecting them into it, both need the
 * same answer: the URL of *this* record's ED session. That URL cannot be written as a constant or a
 * REDCap smart variable, because the session instrument is designated to one event **per
 * intervention arm** and which arm a record is in is only known after randomization.
 * `[survey-url:mica_ed_session]` resolves against the *context* event (`Piping.php:1896`), which for
 * the participant chain is the arm-1 event where the instrument does not exist; naming an event
 * explicitly hardcodes an arm. So the arm has to be resolved per record, once, by something that
 * knows the allocation - which is this.
 *
 * ## Why it is framework-free
 *
 * Same convention as the rest of `classes/` (see ArtifactRegistry): this takes plain arrays -
 * `Project::$eventsForms` and `Project::$eventInfo` - and returns a decision. Minting the link and
 * writing the field need `REDCap::getSurveyLink()` and `REDCap::saveData()`, so those stay in
 * `MICA.php`. What is here is the part that is worth testing, because getting it wrong has a
 * protocol consequence rather than a cosmetic one.
 *
 * ## The consequence, stated plainly
 *
 * `NO_SESSION_IN_ARM` is the reason this class exists as its own unit. A Standard Care participant
 * (arm 1) has no session instrument in their arm and must never be handed a link to one - that would
 * be a control participant receiving the intervention. The same rule already governs arm
 * materialization (`MICA.php:750-752`). Here it is returned as an explicit outcome rather than
 * falling out of "no event was found", so a future refactor cannot turn it into a link by accident.
 */
class EdSessionLink
{
    /** The allocation field is empty - the record has not been randomized yet. */
    public const NOT_RANDOMIZED = 'not-randomized';
    /** The allocation value does not name an arm that exists. */
    public const BAD_GROUP = 'bad-group';
    /**
     * The arm exists and the session instrument is not designated to any of its events.
     * The correct, permanent state for Standard Care - not an error.
     */
    public const NO_SESSION_IN_ARM = 'no-session-in-arm';
    /** Arm and event both known; a link can be minted. */
    public const RESOLVED = 'resolved';

    /**
     * The Day-1 session host instrument, read from the project's own host map.
     *
     * Not the literal `mica_ed_session`: the map is a project setting, so a study that renames or
     * replaces the host would otherwise have a correct map and a hardcoded link. `baseline` is the
     * schema's own word for the Day-1 session (`SessionHostMap::BASELINE`), which is why it is the
     * thing keyed on rather than the instrument name.
     *
     * @return string|null null when no host is mapped as the baseline session
     */
    public static function hostInstrument(SessionHostMap $map): ?string
    {
        foreach ($map->instruments() as $instrument) {
            if ($map->resolve($instrument)['session_type'] === SessionHostMap::BASELINE) {
                return $instrument;
            }
        }

        return null;
    }

    /**
     * Resolve the arm and event for a record's Day-1 session.
     *
     * @param mixed  $studyGroupValue raw allocation value; by project convention it IS the arm number
     * @param string $hostInstrument  from hostInstrument()
     * @param array<int,list<string>>            $eventsForms `Project::$eventsForms`
     * @param array<int,array<string,mixed>>     $eventInfo   `Project::$eventInfo`
     *
     * @return array{status:string,arm:?int,eventId:?int}
     */
    public static function resolve(
        $studyGroupValue,
        string $hostInstrument,
        array $eventsForms,
        array $eventInfo
    ): array {
        $raw = is_scalar($studyGroupValue) ? trim((string) $studyGroupValue) : '';
        if ($raw === '') {
            return self::outcome(self::NOT_RANDOMIZED);
        }

        // `ctype_digit` rather than `is_numeric`: an arm number is a positive integer, and casting
        // "2.5" or "2abc" to 2 would silently put a record in an arm nobody allocated it to.
        if (!ctype_digit($raw) || (int) $raw < 1) {
            return self::outcome(self::BAD_GROUP);
        }

        $arm = (int) $raw;

        $armExists = false;
        foreach ($eventInfo as $info) {
            if ((int) ($info['arm_num'] ?? 0) === $arm) {
                $armExists = true;
                break;
            }
        }
        if (!$armExists) {
            return self::outcome(self::BAD_GROUP, $arm);
        }

        /*
         * Lowest day_offset among the arm's events that actually host the instrument - the same
         * tie-break `MICA.php::getFirstEventIdForArm()` uses. A study that designates the host to
         * more than one event in an arm gets the earliest, which is the Day-1 one by construction;
         * picking arbitrarily would make the URL depend on hash ordering.
         */
        $bestEvent = null;
        $bestOffset = null;
        foreach ($eventInfo as $eventId => $info) {
            if ((int) ($info['arm_num'] ?? 0) !== $arm) {
                continue;
            }
            if (!in_array($hostInstrument, $eventsForms[$eventId] ?? [], true)) {
                continue;
            }
            $offset = (int) ($info['day_offset'] ?? 0);
            if ($bestOffset === null || $offset < $bestOffset) {
                $bestOffset = $offset;
                $bestEvent = (int) $eventId;
            }
        }

        return $bestEvent === null
            ? self::outcome(self::NO_SESSION_IN_ARM, $arm)
            : self::outcome(self::RESOLVED, $arm, $bestEvent);
    }

    /** @return array{status:string,arm:?int,eventId:?int} */
    private static function outcome(string $status, ?int $arm = null, ?int $eventId = null): array
    {
        return ['status' => $status, 'arm' => $arm, 'eventId' => $eventId];
    }
}
