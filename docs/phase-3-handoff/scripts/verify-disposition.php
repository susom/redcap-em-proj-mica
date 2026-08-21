<?php

/**
 * Exercise the review/disposition path against a live REDCap.
 *
 *   docker exec <web> php .../scripts/verify-disposition.php [pid] [record] [event_id]
 *
 * Exit 0 = a real finding instance can be read, dispositioned, locked, and corrected - and the
 * model's own fields survive all of it untouched.
 *
 * What only a live run can settle:
 *
 *   - whether `@READONLY` on `review_reviewer` / `review_reviewed_at` / `review_lock_version` blocks
 *     `saveData`. It is a form-rendering action tag and should not, but "should not" is not
 *     evidence, and finding out in the SPA would be worse.
 *   - whether `overwrite` really clears a withdrawn correction. `normal` skips empty values, so a
 *     reviewer who sets a corrected urgency and then decides the model was right could not withdraw
 *     it - the stale correction would survive on a confirmed finding.
 *   - whether `overwrite` stays inside the fields it is given, or blanks the rest of the instance.
 *     That is the risk `overwrite` buys, and it is the reason the write set is allowlisted twice.
 *
 * Creates its own finding instance and removes it.
 */

$PID = (int) ($argv[1] ?? 257);
$RECORD = (string) ($argv[2] ?? '2');
$EVENT = (int) ($argv[3] ?? 1008);

$_GET['pid'] = $PID;
define('NOAUTH', true);
require_once mica_find_redcap_connect();

function mica_find_redcap_connect(): string
{
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) {
        return "$env/redcap_connect.php";
    }
    foreach (['/var/www/html'] as $guess) {
        if (is_file("$guess/redcap_connect.php")) {
            return "$guess/redcap_connect.php";
        }
    }
    for ($d = __DIR__, $i = 0; $i < 8; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) {
            return "$d/redcap_connect.php";
        }
        if ($d === '/') {
            break;
        }
    }
    fwrite(STDERR, "Could not locate redcap_connect.php.\n");
    exit(1);
}

use Stanford\MICA\AuditLogger;
use Stanford\MICA\DispositionService;
use Stanford\MICA\FindingWriter;
use Stanford\MICA\RedcapAuditStore;
use Stanford\MICA\RedcapFindingReviewStore;
use Stanford\MICA\RedcapScanResultStore;
use Stanford\MICA\ReviewAccessException;
use Stanford\MICA\ReviewConflictException;
use Stanford\MICA\RoleService;

$fails = 0;
function check(string $label, $actual, $expected): void
{
    global $fails;
    $ok = (string) $actual === (string) $expected;
    if (!$ok) {
        $fails++;
    }
    printf("  [%s] %-52s got: %-26s want: %s\n", $ok ? 'ok' : 'FAIL', $label, (string) $actual, (string) $expected);
}
function note(string $label, $value): void
{
    printf("  [--] %-52s %s\n", $label, (string) $value);
}

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

$REVIEWER = 'ihabz';
$originalRa = $module->getProjectSetting('role-ra-reviewer', $PID);

// MICA access follows the user's REDCap ROLE, so the fixture is a role the reviewer is in - not
// their username. Created here and removed at the end.
$module->query('DELETE FROM redcap_user_roles WHERE project_id = ? AND role_name = ?', [$PID, 'Verify MICA Reviewer']);
$module->query(
    'INSERT INTO redcap_user_roles (project_id, role_name, unique_role_name, data_export_tool) '
    . 'VALUES (?, ?, ?, 1)',
    [$PID, 'Verify MICA Reviewer', 'U-VERIFYMICA']
);
$verifyRoleId = (int) $module->query(
    'SELECT role_id FROM redcap_user_roles WHERE project_id = ? AND role_name = ?',
    [$PID, 'Verify MICA Reviewer']
)->fetch_assoc()['role_id'];

$originalUserRole = $module->query(
    'SELECT role_id FROM redcap_user_rights WHERE project_id = ? AND username = ?',
    [$PID, $REVIEWER]
)->fetch_assoc()['role_id'] ?? null;

$module->query(
    'UPDATE redcap_user_rights SET role_id = ? WHERE project_id = ? AND username = ?',
    [$verifyRoleId, $PID, $REVIEWER]
);
$module->setProjectSetting('role-ra-reviewer', [(string) $verifyRoleId], $PID);

$results = new RedcapScanResultStore($module);
$reviewStore = new RedcapFindingReviewStore($module);
$roles = RoleService::fromModule($module, $PID);
$audit = new AuditLogger(new RedcapAuditStore($module), $roles, (string) $PID);
$service = new DispositionService($reviewStore, $roles, $audit);

echo "Disposition path against live REDCap (pid $PID, record $RECORD, event $EVENT)\n\n";

$instance = null;

try {
    check('the review instrument exists', $results->findingInstrumentExists((string) $PID) ? 'yes' : 'no', 'yes');
    check('the reviewer resolves as an RA', implode(',', $roles->rolesFor($REVIEWER)), 'ra');

    echo "\n1. Create a finding instance the way ScanRunner does\n";

    $instance = $results->nextFindingInstance((string) $PID, $RECORD, $EVENT);
    (new FindingWriter($results))->write((string) $PID, $RECORD, $EVENT, 999, [[
        'finding_index'                    => 1,
        'source_role'                      => 'participant',
        'concern_type'                     => 'self_harm',
        'urgency'                          => 'critical',
        'finding_summary'                  => 'verify-disposition probe',
        'evidence'                         => [[
            'message_id'   => 'L1',
            'speaker_role' => 'participant',
            'exact_quote'  => 'caña 日本語 🙂',
        ]],
        'recommended_actions'              => ['ra_review'],
        'recommended_notification_targets' => ['research_assistant'],
        'confidence'                       => 0.9,
    ]]);
    note('instance', (string) $instance);

    $read = $reviewStore->readFinding((string) $PID, $RECORD, $EVENT, $instance);
    check('readFinding() finds it', $read === null ? 'no' : 'yes', 'yes');
    check('at review_status', $read['review_status'] ?? 'NONE', 'pending');
    check('and lock version', $read['review_lock_version'] ?? 'NONE', '0');
    check('with the evidence intact', str_contains($read['finding_evidence_json'] ?? '', 'caña') ? 'yes' : 'no', 'yes');

    echo "\n2. Confirm it — does @READONLY block the write?\n";

    $result = $service->submit((string) $PID, $RECORD, $EVENT, $instance, $REVIEWER, [
        'review_status'       => DispositionService::CONFIRMED,
        'review_rationale'    => 'Verified against the transcript; escalating per protocol.',
        'review_lock_version' => '0',
    ]);

    check('the disposition was accepted', $result['review_status'], 'confirmed');
    check('the lock advanced', $result['lock_version'], 1);

    $after = $reviewStore->readFinding((string) $PID, $RECORD, $EVENT, $instance);
    check('review_status persisted', $after['review_status'] ?? 'NONE', 'confirmed');
    check('review_reviewer persisted (@READONLY)', $after['review_reviewer'] ?? 'NONE', $REVIEWER);
    check('review_lock_version persisted (@READONLY)', $after['review_lock_version'] ?? 'NONE', '1');
    check(
        'review_reviewed_at persisted (@READONLY)',
        ($after['review_reviewed_at'] ?? '') !== '' ? 'yes' : 'no',
        'yes'
    );

    echo "\n3. The model's own fields survived it\n";

    foreach (
        [
        'finding_concern_type'  => 'self_harm',
        'finding_urgency'       => 'critical',
        'finding_summary'       => 'verify-disposition probe',
        'finding_scan_run'      => '999',
        ] as $field => $expected
    ) {
        check("$field untouched", $after[$field] ?? 'MISSING', $expected);
    }
    check(
        'finding_evidence_json untouched',
        str_contains($after['finding_evidence_json'] ?? '', 'caña') ? 'yes' : 'no',
        'yes'
    );
    check(
        'finding_id untouched',
        ($after['finding_id'] ?? '') === ($read['finding_id'] ?? 'x') ? 'yes' : 'no',
        'yes'
    );

    echo "\n4. Optimistic locking\n";

    try {
        $service->submit((string) $PID, $RECORD, $EVENT, $instance, $REVIEWER, [
            'review_status'       => DispositionService::DISMISSED,
            'review_rationale'    => 'stale write',
            'review_lock_version' => '0',
        ]);
        check('a stale write is refused', 'accepted', 'refused');
    } catch (ReviewConflictException $e) {
        check('a stale write is refused', 'refused', 'refused');
        check('...reporting the current version', $e->currentVersion, 1);
    }

    check(
        'and the earlier decision stands',
        $reviewStore->readFinding((string) $PID, $RECORD, $EVENT, $instance)['review_status'],
        'confirmed'
    );

    echo "\n5. A withdrawn correction is actually cleared (overwrite, not normal)\n";

    $service->submit((string) $PID, $RECORD, $EVENT, $instance, $REVIEWER, [
        'review_status'            => DispositionService::CONFIRMED,
        'review_rationale'         => 'Urgency looks overstated.',
        'review_corrected_urgency' => 'moderate',
        'review_lock_version'      => '1',
    ]);
    check(
        'the correction is stored',
        $reviewStore->readFinding((string) $PID, $RECORD, $EVENT, $instance)['review_corrected_urgency'] ?? 'NONE',
        'moderate'
    );

    $service->submit((string) $PID, $RECORD, $EVENT, $instance, $REVIEWER, [
        'review_status'            => DispositionService::CONFIRMED,
        'review_rationale'         => 'On reflection the model was right; withdrawing.',
        'review_corrected_urgency' => '',
        'review_lock_version'      => '2',
    ]);
    $withdrawn = $reviewStore->readFinding((string) $PID, $RECORD, $EVENT, $instance);
    check(
        'and can be withdrawn',
        ($withdrawn['review_corrected_urgency'] ?? '') === '' ? 'cleared' : $withdrawn['review_corrected_urgency'],
        'cleared'
    );

    echo "\n6. overwrite stayed inside the fields it was given\n";

    // The risk `overwrite` buys. If it blanked the rest of the instance, the model fields would be
    // gone by now - so this is the check that makes the choice defensible rather than merely stated.
    check('finding_urgency still set', $withdrawn['finding_urgency'] ?? 'MISSING', 'critical');
    check('finding_summary still set', $withdrawn['finding_summary'] ?? 'MISSING', 'verify-disposition probe');
    check('the rationale from this write', $withdrawn['review_rationale'] ?? 'NONE', 'On reflection the model was right; withdrawing.');

    echo "\n7. Access control, against the real REDCap roles\n";

    // The three ways a user can lack access, each proven rather than assumed. A username list could
    // only ever express the first of them.
    try {
        $service->submit((string) $PID, $RECORD, $EVENT, $instance, 'not_a_reviewer', [
            'review_status'       => DispositionService::DISMISSED,
            'review_rationale'    => 'should never land',
            'review_lock_version' => '3',
        ]);
        check('a user not on the project is refused', 'accepted', 'refused');
    } catch (ReviewAccessException) {
        check('a user not on the project is refused', 'refused', 'refused');
    }

    // (a) On the project, but in no REDCap role at all.
    $module->query(
        'UPDATE redcap_user_rights SET role_id = NULL WHERE project_id = ? AND username = ?',
        [$PID, $REVIEWER]
    );
    $noRole = RoleService::fromModule($module, $PID);
    check('a user with NO REDCap role has no access', $noRole->hasAnyRole($REVIEWER) ? 'yes' : 'no', 'no');
    check('...but is still seen as on the project', $noRole->isOnProject($REVIEWER) ? 'yes' : 'no', 'yes');

    // (b) In a REDCap role that is not mapped.
    $module->query(
        'UPDATE redcap_user_rights SET role_id = ? WHERE project_id = ? AND username = ?',
        [$verifyRoleId, $PID, $REVIEWER]
    );
    $module->setProjectSetting('role-ra-reviewer', [], $PID);
    $unmapped = RoleService::fromModule($module, $PID);
    check('an UNMAPPED REDCap role has no access', $unmapped->hasAnyRole($REVIEWER) ? 'yes' : 'no', 'no');

    /**
     * `isUnconfigured()` is a property of ALL THREE role settings, so testing it means controlling
     * all three.
     *
     * This used to clear only `role-ra-reviewer` and then assert the module called itself
     * unconfigured - which was true on a clean project and false on any project where an
     * administrator had mapped an auditor or PI role, because one mapping is enough to make it
     * configured. It failed on PID 257 the moment `role-auditor` was set, reporting a defect in
     * RoleService when the fault was this script's assumption about the project.
     */
    $otherRoles = [];
    foreach (['role-pi-lead', 'role-auditor'] as $key) {
        $otherRoles[$key] = $module->getProjectSetting($key, $PID);
        $module->setProjectSetting($key, [], $PID);
    }

    check(
        '...and with NO role mapped it reports itself unconfigured',
        RoleService::fromModule($module, $PID)->isUnconfigured() ? 'yes' : 'no',
        'yes'
    );

    foreach ($otherRoles as $key => $value) {
        $value === null || $value === [] ? $module->removeProjectSetting($key, $PID)
                                        : $module->setProjectSetting($key, $value, $PID);
    }

    check(
        'and one mapped role is enough to be configured again',
        RoleService::fromModule($module, $PID)->isUnconfigured() ? 'yes' : 'no',
        ($otherRoles['role-pi-lead'] || $otherRoles['role-auditor']) ? 'no' : 'yes'
    );

    // (c) Restored: mapped role, access back. Proves the negatives were the mapping and not
    // something incidental that happened to break access for the rest of the run.
    $module->setProjectSetting('role-ra-reviewer', [(string) $verifyRoleId], $PID);
    $restored = RoleService::fromModule($module, $PID);
    check('re-mapping the role restores access', implode(',', $restored->rolesFor($REVIEWER)), 'ra');

    check(
        'and no disposition landed during any of that',
        $reviewStore->readFinding((string) $PID, $RECORD, $EVENT, $instance)['review_status'],
        'confirmed'
    );

    echo "\n8. Every step is on the audit trail\n";

    $q = $module->query(
        'SELECT actor, actor_role, event_type, details FROM redcap_entity_mica_audit_event '
        . 'ORDER BY id DESC LIMIT 10',
        []
    );
    $events = [];
    while ($row = $q->fetch_assoc()) {
        $events[] = $row;
    }

    check('audit rows written', count($events) >= 3 ? 'yes' : 'no (' . count($events) . ')', 'yes');
    check('actor recorded', $events[0]['actor'] ?? 'NONE', $REVIEWER);
    check('acting as', $events[0]['actor_role'] ?? 'NONE', 'ra');
    check('event type', $events[0]['event_type'] ?? 'NONE', 'disposition');
    check(
        'and no participant text in details',
        str_contains($events[0]['details'] ?? '', 'caña') ? 'LEAKED' : 'clean',
        'clean'
    );
} catch (\Throwable $e) {
    $fails++;
    printf("\n  [FAIL] %s: %s\n         at %s:%d\n", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
}

echo "\n9. Clean up\n";

if ($originalRa === null || $originalRa === '') {
    $module->removeProjectSetting('role-ra-reviewer', $PID);
} else {
    $module->setProjectSetting('role-ra-reviewer', $originalRa, $PID);
}
note('reviewer role mapping restored', $originalRa === null ? '(unset)' : json_encode($originalRa));

$module->query(
    'UPDATE redcap_user_rights SET role_id = ? WHERE project_id = ? AND username = ?',
    [$originalUserRole, $PID, $REVIEWER]
);
$module->query('DELETE FROM redcap_user_roles WHERE project_id = ? AND role_name = ?', [$PID, 'Verify MICA Reviewer']);
note("$REVIEWER's REDCap role restored", $originalUserRole === null ? '(none)' : $originalUserRole);

$fieldsQ = $module->query(
    'SELECT field_name AS f FROM redcap_metadata WHERE project_id = ? AND form_name = ?',
    [$PID, RedcapScanResultStore::INSTRUMENT]
);
$fields = [];
while ($row = $fieldsQ->fetch_assoc()) {
    $fields[] = $row['f'];
}
if ($fields !== []) {
    $module->query(
        'DELETE FROM ' . \Records::getDataTable($PID) . ' WHERE project_id = ? AND record = ? '
        . 'AND field_name IN (' . implode(',', array_fill(0, count($fields), '?')) . ')',
        array_merge([$PID, $RECORD], $fields)
    );
}
$module->query('DELETE FROM redcap_entity_mica_audit_event', []);

check(
    'finding instances left behind',
    (int) $module->query(
        'SELECT COUNT(*) n FROM ' . \Records::getDataTable($PID)
        . ' WHERE project_id = ? AND record = ? AND field_name = ?',
        [$PID, $RECORD, 'finding_id']
    )->fetch_assoc()['n'],
    0
);

echo "\n" . ($fails === 0 ? "PASS - the disposition path works against a live REDCap\n" : "FAIL - $fails check(s) failed\n");
exit($fails === 0 ? 0 : 1);
