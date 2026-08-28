<?php
/**
 * Creates (and removes) a throwaway REDCap account with DESIGN rights, so the module's own
 * configuration dialog can be opened in a real browser.
 *
 *   php e2e-module-config-user.php [pid] setup|teardown
 *
 * Defaults: pid=257, mode=setup. Prints a username, password and the External Modules URL.
 *
 * WHY A DEDICATED USER. The settings dialog is the delivery surface for anything rendered by
 * `redcap_module_configuration_settings()` - currently the read-only SafetyScan prompt panel
 * (`PinnedPromptView`). Nothing about that panel is provable from PHP: whether the framework calls
 * the method is, but whether the HTML it returns renders, collapses and stays inside the modal is a
 * browser question, and `descriptive` is a client-side-only setting type - the whole render lives in
 * `ExternalModules/manager/js/globals.js`.
 *
 * WHY DESIGN AND NOT super_user. `ExternalModules::hasProjectSettingSavePermission()` short-circuits
 * to true for a super user, so a super user cannot tell you whether an ordinary study designer can
 * open the dialog at all. Design rights is the real gate on the project path, and a study designer
 * is the person who actually writes `safetyscan-prompt-addendum`. This account therefore gets design
 * rights and nothing else: no user-rights, no super_user, no data entry.
 *
 * Table auth only, hashed via REDCap's own helper (see the same note in e2e-admin-form-user.php):
 * `Authentication::hashPassword()` with a per-user salt, NOT password_hash() - the latter yields a
 * valid-looking string and an undiagnosable "invalid user name or password".
 *
 * TEAR IT DOWN when finished - the account has a known password AND design rights, which is the one
 * combination worth being fussy about:
 *   php e2e-module-config-user.php 257 teardown
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

const USER = 'e2e_mica_config';
const PASS = 'E2eModCfg!2026';

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

echo "\n=== throwaway design-rights account on project $pid ===\n";

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
        values ('$u', '" . $u . "@example.invalid', 'E2E', 'ModuleConfig', now(), 0, 0, 0)")) {
    fail('redcap_user_information insert failed: ' . db_error());
}
out('user', USER . ' (super_user=0)');

if (!db_query("insert into redcap_user_rights
        (project_id, username, role_id, expiration, group_id, design, user_rights,
         record_create, data_export_tool, data_entry)
        values ($pid, '$u', null, null, null, 1, 0, 0, 0, '')")) {
    fail('redcap_user_rights insert failed: ' . db_error());
}
out('rights', 'design=1, user_rights=0, no data entry');

$base = rtrim((string) db_result(db_query("select value from redcap_config where field_name='redcap_base_url'"), 0), '/');

echo "\n  username: " . USER . "\n";
echo "  password: " . PASS . "\n";
echo "  login:    $base/\n";
echo "  external modules: $base/redcap_v" . REDCAP_VERSION . "/ExternalModules/manager/project.php?pid=$pid\n";
echo "\n*** DONE - tear this account down when finished:\n";
echo "    php " . basename(__FILE__) . " $pid teardown\n";
