<?php

/**
 * Set up (or tear down) everything the review-dashboard E2E needs.
 *
 *   docker exec <web> php .../scripts/e2e-review-fixture.php 257 setup
 *   docker exec <web> php .../scripts/e2e-review-fixture.php 257 teardown
 *
 * Creates a dedicated throwaway REDCap user with a known password, gives it project rights and the
 * MICA reviewer role, and seeds three finding instances covering the cases the dashboard has to get
 * right. Teardown removes all of it.
 *
 * **A dedicated user, not a real one.** The alternative is resetting somebody's password for the
 * duration of a test, which is both invasive and easy to forget to undo. This account exists only
 * while the test runs, is named so nobody mistakes it for a person, and has no rights beyond this
 * project.
 *
 * Setup prints the credentials and the dashboard URL for the Playwright spec to consume.
 */

$PID = (int) ($argv[1] ?? 257);
$MODE = (string) ($argv[2] ?? 'setup');
$RECORD = (string) ($argv[3] ?? '2');
$EVENT = (int) ($argv[4] ?? 1008);

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

use Stanford\MICA\FindingWriter;
use Stanford\MICA\RedcapScanResultStore;

const USER = 'e2e_mica_reviewer';
const PASS = 'E2eReview!2026';
const ROLE = 'E2E MICA Reviewer';

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

$results = new RedcapScanResultStore($module);

/** Every field on the finding instrument, for a targeted cleanup. */
function findingFields($module, int $pid): array
{
    $q = $module->query(
        'SELECT field_name AS f FROM redcap_metadata WHERE project_id = ? AND form_name = ?',
        [$pid, RedcapScanResultStore::INSTRUMENT]
    );
    $fields = [];
    while ($row = $q->fetch_assoc()) {
        $fields[] = $row['f'];
    }

    return $fields;
}

if ($MODE === 'teardown') {
    echo "Tearing down the review E2E fixture\n";

    $fields = findingFields($module, $PID);
    if ($fields !== []) {
        $module->query(
            'DELETE FROM ' . \Records::getDataTable($PID) . ' WHERE project_id = ? AND record = ? '
            . 'AND field_name IN (' . implode(',', array_fill(0, count($fields), '?')) . ')',
            array_merge([$PID, $RECORD], $fields)
        );
        echo "  removed finding instances\n";
    }

    $module->query('DELETE FROM redcap_entity_mica_scan_run', []);
    $module->query('DELETE FROM redcap_entity_mica_scan_job WHERE project_id = ?', [$PID]);
    $module->query('DELETE FROM redcap_entity_mica_audit_event', []);
    echo "  removed scan jobs, runs and audit events\n";

    $module->query('DELETE FROM redcap_user_rights WHERE project_id = ? AND username = ?', [$PID, USER]);
    $module->query('DELETE FROM redcap_auth WHERE username = ?', [USER]);
    $module->query('DELETE FROM redcap_user_information WHERE username = ?', [USER]);
    echo "  removed the throwaway user " . USER . "\n";

    $module->query('DELETE FROM redcap_user_roles WHERE project_id = ? AND role_name = ?', [$PID, ROLE]);
    echo "  removed the throwaway REDCap role \"" . ROLE . "\"\n";

    $module->removeProjectSetting('role-ra-reviewer', $PID);
    echo "  cleared the reviewer role mapping\n";

    echo "\nDone. Nothing from this fixture remains.\n";
    exit(0);
}

echo "Setting up the review E2E fixture (pid $PID, record $RECORD, event $EVENT)\n\n";

if (!$results->findingInstrumentExists((string) $PID)) {
    fwrite(
        STDERR,
        "The mica_safety_finding instrument does not exist on project $PID. Run\n"
        . "apply-safety-finding-instrument.php first — there is nothing to review without it.\n"
    );
    exit(1);
}

// ---------------------------------------------------------------- the throwaway user

$module->query('DELETE FROM redcap_auth WHERE username = ?', [USER]);
$module->query('DELETE FROM redcap_user_information WHERE username = ?', [USER]);

// Table auth, hashed by REDCap's own helper rather than by hand.
//
// It is `hash($password_algo, $password . $salt)` with a PER-USER salt in redcap_auth.password_salt
// - NOT password_hash(), and not an unsalted md5 unless legacy_hash is set. Writing a
// password_hash() digest here produced a valid-looking 60-char string and a login that simply said
// "invalid user name or password", which is the least diagnosable possible failure. Going through
// Authentication also means a REDCap upgrade that changes the algorithm does not silently break
// this fixture.
$salt = \Authentication::generatePasswordSalt();
$hash = \Authentication::hashPassword(PASS, $salt);

$module->query(
    'INSERT INTO redcap_auth (username, password, password_salt, legacy_hash, password_question, '
    . 'password_answer, temp_pwd) VALUES (?, ?, ?, 0, NULL, NULL, 0)',
    [USER, $hash, $salt]
);

// Proven, not assumed: a fixture whose password does not work wastes a whole debugging session on
// the thing it was supposed to make easy.
if (!\Authentication::verifyTableUsernamePassword(USER, PASS)) {
    fwrite(STDERR, "The seeded password does not verify against REDCap's own check. Aborting.\n");
    exit(1);
}
echo "  password verified through Authentication::verifyTableUsernamePassword()\n";
$module->query(
    'INSERT INTO redcap_user_information (username, user_email, user_firstname, user_lastname, '
    . 'user_creation, super_user, account_manager, allow_create_db) '
    . 'VALUES (?, ?, ?, ?, NOW(), 0, 0, 0)',
    [USER, USER . '@example.invalid', 'E2E', 'Reviewer']
);
echo "  created user " . USER . "\n";

// A dedicated REDCap USER ROLE, because MICA access follows REDCap roles rather than a list of
// usernames (see RoleService). This is what the study team would actually do: create a role, put
// people in it, and tell the module that role reviews findings.
$module->query('DELETE FROM redcap_user_roles WHERE project_id = ? AND role_name = ?', [$PID, ROLE]);
$module->query(
    'INSERT INTO redcap_user_roles (project_id, role_name, unique_role_name, data_export_tool) '
    . 'VALUES (?, ?, ?, 1)',
    [$PID, ROLE, 'U-E2EMICAREV']
);
$roleId = (int) $module->query(
    'SELECT role_id FROM redcap_user_roles WHERE project_id = ? AND role_name = ?',
    [$PID, ROLE]
)->fetch_assoc()['role_id'];
echo "  created REDCap role \"" . ROLE . "\" (role_id $roleId)\n";

// Project rights: enough to reach a module page, and nothing else. Deliberately NOT design or
// user_rights - the dashboard must work for an ordinary reviewer, not only for an administrator,
// and running this as an admin is what hid a real access bug once already.
$module->query('DELETE FROM redcap_user_rights WHERE project_id = ? AND username = ?', [$PID, USER]);
$module->query(
    'INSERT INTO redcap_user_rights (project_id, username, role_id, expiration, group_id, design, '
    . 'user_rights, data_export_tool, external_module_config) '
    . 'VALUES (?, ?, ?, NULL, NULL, 0, 0, 1, NULL)',
    [$PID, USER, $roleId]
);
echo "  put the user in that role, with no design and no user-rights\n";

// The module setting is a MAPPING, not a roster: it names the REDCap role, never the person.
$module->setProjectSetting('role-ra-reviewer', [(string) $roleId], $PID);
echo "  mapped role_id $roleId as the MICA reviewer role\n";

// Proven rather than assumed - the mapping and the roster have to agree or the dashboard 403s.
$check = \Stanford\MICA\RoleService::fromModule($module, $PID);
if (!$check->hasAnyRole(USER)) {
    fwrite(STDERR, "The seeded role mapping does not resolve for " . USER . ". Aborting.\n");
    exit(1);
}
echo "  RoleService resolves " . USER . " as: " . implode(', ', $check->rolesFor(USER)) . "\n";

// ---------------------------------------------------------------- seeded findings

$fields = findingFields($module, $PID);
if ($fields !== []) {
    $module->query(
        'DELETE FROM ' . \Records::getDataTable($PID) . ' WHERE project_id = ? AND record = ? '
        . 'AND field_name IN (' . implode(',', array_fill(0, count($fields), '?')) . ')',
        array_merge([$PID, $RECORD], $fields)
    );
}
$module->query('DELETE FROM redcap_entity_mica_scan_run', []);
$module->query('DELETE FROM redcap_entity_mica_scan_job WHERE project_id = ?', [$PID]);

// A transcript to cite, with a quote the finding below matches byte-for-byte. The evidence has to
// really be in the text or the highlight cannot appear, and a spec that asserted a highlight against
// a quote the server would have rejected would be testing nothing.
$said = 'I have been drinking a lot more since my brother died, and some nights I think about not '
      . 'waking up. caña 日本語 🙂';

$msgIds = [];
foreach ([
    ['role' => 'user', 'content' => $said],
    ['response' => ['role' => 'assistant', 'content' => 'Thank you for telling me. That sounds heavy.'],
     'query' => ['role' => 'user', 'content' => $said]],
] as $payload) {
    $q = new \Stanford\MICA\MICAQuery($module);
    $q->setValue('mica_id', $RECORD);
    $q->setValue('message', json_encode($payload));
    $q->save();
    $msgIds[] = (int) $q->getId();
}

$finalizer = $module->transcriptFinalizer();
$result = $finalizer->finalize((string) $PID, $RECORD, $RECORD, 1, $EVENT, 'baseline', 'emergency_department');
echo "  finalized {$result->transcriptRef} and queued scan job {$result->jobId}\n";

// Three findings, covering the three things the dashboard must not blur together.
$runId = $results->insertRun([
    'job_id'               => $result->jobId,
    'attempt'              => 1,
    'model_alias'          => 'gemini-2.5-flash',
    'resolved_model'       => 'mock:e2e',
    'prompt_sha256'        => str_repeat('a', 64),
    'input_schema_sha256'  => str_repeat('b', 64),
    'output_schema_sha256' => str_repeat('c', 64),
    'app_version'          => 'e2e',
    'run_status'           => 'ok',
    'model_output_json'    => json_encode(['model_output' => ['scan_result' => 'findings_present']]),
]);

(new FindingWriter($results))->write((string) $PID, $RECORD, $EVENT, $runId, [
    [
        'finding_index'                    => 1,
        'source_role'                      => 'participant',
        'concern_type'                     => 'self_harm',
        'urgency'                          => 'critical',
        'finding_summary'                  => 'Participant describes passive suicidal ideation in the '
                                            . 'context of increased drinking after a bereavement.',
        // Byte-exact in $said, so the highlight is real.
        'evidence'                         => [[
            'message_id'   => 'L' . $msgIds[0],
            'speaker_role' => 'participant',
            'exact_quote'  => 'some nights I think about not waking up',
        ]],
        'recommended_actions'              => ['ra_review', 'alert_care_team'],
        'recommended_notification_targets' => ['research_assistant', 'care_team'],
        'confidence'                       => 0.91,
    ],
    [
        'finding_index'                    => 2,
        'source_role'                      => 'participant',
        'concern_type'                     => 'dangerous_alcohol_use',
        'urgency'                          => 'moderate',
        'finding_summary'                  => 'Self-reported escalation in drinking.',
        // Multi-byte, so the spec can prove highlighting survives it.
        'evidence'                         => [[
            'message_id'   => 'L' . $msgIds[0],
            'speaker_role' => 'participant',
            'exact_quote'  => 'caña 日本語 🙂',
        ]],
        'recommended_actions'              => ['ra_review'],
        'recommended_notification_targets' => ['research_assistant'],
        'confidence'                       => 0.7,
    ],
]);
echo "  seeded 2 findings (critical + moderate) with verifiable quotes\n";

// And an unscreened session, which must sort ABOVE the critical finding.
$module->query(
    'UPDATE redcap_entity_mica_scan_job SET status = ?, last_error = ? WHERE id = ?',
    ['ready_for_review', '', $result->jobId]
);

echo "\n";
echo "USERNAME=" . USER . "\n";
echo "PASSWORD=" . PASS . "\n";
echo "URL=" . $module->getUrl('pages/review.php') . "\n";
echo "RECORD=$RECORD\n";
echo "\nRun the spec, then tear down:\n";
echo "  php " . basename(__FILE__) . " $PID teardown\n";
