<?php
/**
 * Creates (and removes) a throwaway REDCap account with plain data-entry rights, so the `admin`
 * form's branching-logic/calculation banner can be checked in a real browser.
 *
 *   php e2e-admin-form-user.php [pid] setup|teardown
 *
 * Defaults: pid=257, mode=setup. Prints a username, password and the data-entry URL.
 *
 * WHY A DEDICATED USER. Verifying that the banner changed requires REDCap's own DataEntry page -
 * SQL showing that the tokens now resolve does not exercise `LogicTester`, which is what actually
 * produces the message. The alternatives are resetting a real user's password (destructive) or
 * reusing e2e-review-fixture.php, which also seeds safety findings on record 2 and deliberately
 * withholds data-entry rights. This account gets ordinary data-entry rights on every instrument and
 * nothing else: no design, no user-rights, no super_user - so the banner it sees is the banner a
 * study coordinator sees.
 *
 * Table auth only, hashed via REDCap's own helper: `hash($password_algo, $password . $salt)` with a
 * per-user salt in `redcap_auth.password_salt` - NOT password_hash(). Writing a password_hash()
 * digest yields a valid-looking string and an undiagnosable "invalid user name or password"
 * (see the same note in e2e-review-fixture.php).
 *
 * TEAR IT DOWN when finished - the account has a known password:
 *   php e2e-admin-form-user.php 257 teardown
 */

$pid  = (int)    ($argv[1] ?? 257);
$mode = (string) ($argv[2] ?? 'setup');

$_GET['pid'] = $pid;
define('NOAUTH', true);
require_once mica_find_redcap_connect();

function mica_find_redcap_connect(): string {
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) return "$env/redcap_connect.php";
    foreach (['/var/www/html'] as $guess) if (is_file("$guess/redcap_connect.php")) return "$guess/redcap_connect.php";
    for ($d = __DIR__, $i = 0; $i < 8; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) return "$d/redcap_connect.php";
        if ($d === '/') break;
    }
    fwrite(STDERR, "Could not locate redcap_connect.php. Set REDCAP_ROOT.\n"); exit(1);
}

function out(string $label, string $value): void { printf("  [ok] %-44s %s\n", $label, $value); }
function fail(string $msg): void { fwrite(STDERR, "  [FAIL] $msg\n"); exit(1); }

const USER = 'e2e_admin_form';
const PASS = 'E2eAdminForm!2026';

$u = db_escape(USER);

if ($mode === 'teardown') {
    echo "\n=== removing the throwaway account " . USER . " ===\n";
    db_query("delete from redcap_user_rights where project_id = $pid and username = '$u'");
    db_query("delete from redcap_auth where username = '$u'");
    db_query("delete from redcap_user_information where username = '$u'");
    out('removed', USER);
    echo "\n*** DONE ***\n";
    exit(0);
}

if ($mode !== 'setup') fail("unknown mode '$mode' - expected setup or teardown");

echo "\n=== throwaway data-entry account on project $pid ===\n";

$Proj = new Project($pid, true);
if (!isset($Proj->forms['admin'])) fail("project $pid has no 'admin' form");

// Recreate from scratch so a half-finished previous run cannot leave a stale password behind.
db_query("delete from redcap_user_rights where project_id = $pid and username = '$u'");
db_query("delete from redcap_auth where username = '$u'");
db_query("delete from redcap_user_information where username = '$u'");

$salt = \Authentication::generatePasswordSalt();
$hash = \Authentication::hashPassword(PASS, $salt);

if (!db_query("insert into redcap_auth
        (username, password, password_salt, legacy_hash, password_question, password_answer, temp_pwd)
        values ('$u', '" . db_escape($hash) . "', '" . db_escape($salt) . "', 0, null, null, 0)")) {
    fail('redcap_auth insert failed: ' . db_error());
}

// Proven, not assumed - a fixture whose password does not work wastes the session it was meant to save.
if (!\Authentication::verifyTableUsernamePassword(USER, PASS)) {
    fail("the seeded password does not verify against REDCap's own check");
}
out('password', 'verified via Authentication::verifyTableUsernamePassword()');

if (!db_query("insert into redcap_user_information
        (username, user_email, user_firstname, user_lastname, user_creation, super_user,
         account_manager, allow_create_db)
        values ('$u', '" . $u . "@example.invalid', 'E2E', 'AdminForm', now(), 0, 0, 0)")) {
    fail('redcap_user_information insert failed: ' . db_error());
}
out('user', USER . ' (super_user=0)');

// Form rights, in REDCap's own `[form,level]` encoding - level 1 = view & edit.
$dataEntry = '';
foreach (array_keys($Proj->forms) as $formName) $dataEntry .= "[$formName,1]";

if (!db_query("insert into redcap_user_rights
        (project_id, username, role_id, expiration, group_id, design, user_rights,
         record_create, data_export_tool, data_entry)
        values ($pid, '$u', null, null, null, 0, 0, 1, 1, '" . db_escape($dataEntry) . "')")) {
    fail('redcap_user_rights insert failed: ' . db_error());
}
out('rights', count($Proj->forms) . ' instrument(s) at view+edit, no design, no user-rights');

$base  = rtrim((string) db_result(db_query("select value from redcap_config where field_name='redcap_base_url'"), 0), '/');
$event = (int) db_result(db_query("select ef.event_id from redcap_events_forms ef
    join redcap_events_metadata e on e.event_id = ef.event_id
    join redcap_events_arms a on a.arm_id = e.arm_id
    where a.project_id = $pid and ef.form_name = 'admin'
    order by a.arm_num, e.day_offset limit 1"), 0);

echo "\n  username: " . USER . "\n";
echo "  password: " . PASS . "\n";
echo "  login:    $base/\n";
echo "  admin form (record 1): $base/redcap_v" . REDCAP_VERSION
   . "/DataEntry/index.php?pid=$pid&id=1&page=admin&event_id=$event\n";
echo "\n*** DONE - tear this account down when finished:\n";
echo "    php " . basename(__FILE__) . " $pid teardown\n";
