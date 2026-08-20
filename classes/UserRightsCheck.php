<?php

namespace Stanford\MICA;

/**
 * Whether a given REDCap user may administer MICA sessions.
 *
 * Extracted from `MICA::validatePermissions()` so the decision can be unit-tested without a REDCap
 * bootstrap, and - more to the point - so it is forced to take a username. The version this
 * replaced did:
 *
 *     $test = current(UserRights::getPrivileges(PROJECT_ID)[PROJECT_ID]);
 *     if ($test['user_rights'] === '1') return true;
 *
 * `getPrivileges($pid)` with no `$userid` returns **every** user in the project, ordered by
 * username, so `current()` read the *first user alphabetically* and the answer did not depend on
 * who was asking. Demonstrated on PID 257: with `ihabz` (user_rights=1) and `test`
 * (user_rights=0, design=0) in the project, the expression returned `'1'`, so `test` was granted.
 * The same mechanic denies a legitimate administrator whenever the alphabetically-first user
 * happens to lack the right.
 *
 * The privilege required is unchanged - REDCap's "User Rights" privilege, used here as the proxy
 * for project administrator. Only the lookup is fixed.
 *
 * Why the username checks come *before* the lookup, and are not merely defensive: passing an empty
 * username to `getPrivileges($pid, '')` does not scope the query at all. Its guard is
 * `if ($userid != null)`, and `'' != null` is false in PHP, so the username filter is dropped and
 * the caller gets back *every* user on the project - verified on PID 257. A lookup-first
 * implementation would then find a real administrator's row under a key it was never asked about.
 * Refusing anonymous up front makes that unreachable.
 */
class UserRightsCheck
{
    /**
     * @param array<string,array<string,mixed>> $projectPrivileges `getPrivileges($pid)[$pid]`, i.e.
     *                                          username => rights. Looked up by key, never by
     *                                          position.
     * @param string|null $username             the user being asked about; anonymous is never
     *                                          allowed
     * @param bool $isSuperUser                 REDCap super users bypass project rights, as they do
     *                                          everywhere else in REDCap
     */
    public static function hasSessionAdminRights(
        array $projectPrivileges,
        ?string $username,
        bool $isSuperUser = false
    ): bool {
        if ($isSuperUser) {
            return true;
        }

        if ($username === null || trim($username) === '') {
            return false;
        }

        $rights = $projectPrivileges[$username] ?? null;
        if (!is_array($rights)) {
            // Not a user on this project at all.
            return false;
        }

        // REDCap stores rights as '0'/'1' strings; compare loosely enough to survive an int without
        // ever treating a missing key as permission.
        return isset($rights['user_rights']) && (string) $rights['user_rights'] === '1';
    }
}
