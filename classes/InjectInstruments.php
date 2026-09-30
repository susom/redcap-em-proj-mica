<?php

namespace Stanford\MICA;

/**
 * Which of the instruments named in `chatbot_redcap_inject` / `_booster` this project really has.
 *
 * ## Why an unknown name must be skipped, not fetched
 *
 * The counselor's context is built by fetching each named instrument's fields. For a name that is not
 * an instrument, `REDCap::getFieldNames()` returns `false`, and `REDCap::getData()` reads a false or
 * empty `fields` as **no filter**: it returns every field on the record. So a list of display names
 * instead of form names - `DDQ, AUDIT, BSCQ, SIP-2R` for `ddq, audit, bscq, sip2r`, as PIDs 271 and
 * 279 had it on 2026-09-30 - put the participant's whole record into the Day-1 prompt once per name:
 * first name, phone, email, room, age, about 33 K characters.
 *
 * The booster path already skipped unknown names. This is that check, shared, so both paths read the
 * list the same way.
 *
 * Matching is exact, as REDCap's own form names are. A near miss such as `DDQ` for `ddq` is reported,
 * not corrected: guessing which instrument was meant is exactly how data the study never chose to
 * inject ends up in the prompt.
 */
final class InjectInstruments
{
    /**
     * @param string|null       $raw   the setting's comma-delimited value
     * @param array<string,mixed> $forms the project's instruments, keyed by form name (`Project::$forms`)
     * @return array{known: list<string>, unknown: list<string>} both in list order, blanks dropped
     */
    public static function resolve(?string $raw, array $forms): array
    {
        $known = [];
        $unknown = [];
        foreach (explode(',', (string) $raw) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            if (array_key_exists($name, $forms)) {
                $known[] = $name;
            } else {
                $unknown[] = $name;
            }
        }
        return ['known' => $known, 'unknown' => $unknown];
    }
}
