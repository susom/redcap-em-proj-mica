<?php

/**
 * Puts a stand-in CRC into the REDCap role mapped as MICA Reviewer (and takes them out again), so
 * the "findings ready" email has somebody to reach in a local end-to-end run.
 *
 *   php e2e-crc-reviewer.php <pid> setup|teardown
 *
 * WHY. `reviewers_ready` is addressed to everyone whose `redcap_user_rights.role_id` is one of the
 * roles in the module's `role-ra-reviewer` setting, resolved at send time
 * (RedcapRecipientDirectory::addressesForMicaRole). A mapped role with nobody in it is not an
 * error until a finding exists: the notice is then recorded as failed with "No recipient addresses
 * were given", which is what happened on PID 271 on 2026-09-22. This is the same step a real CRC
 * needs on prod, done with a throwaway account.
 *
 * The account never logs in, so it has no password: it exists only to hold the role and an address.
 * The address is at example.org (reserved, RFC 2606), so nothing can leave even without a mail sink.
 *
 * TEAR IT DOWN when finished:
 *   php e2e-crc-reviewer.php 271 teardown
 */

$pid  = (int)    ($argv[1] ?? 0);
$mode = (string) ($argv[2] ?? 'setup');

if ($pid <= 0) {
    fwrite(STDERR, "usage: php e2e-crc-reviewer.php <pid> setup|teardown\n");
    exit(2);
}

$_GET['pid'] = $pid;
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
    fwrite(STDERR, "Could not locate redcap_connect.php. Set REDCAP_ROOT.\n");
    exit(1);
}

function out(string $label, string $value): void
{
    printf("  [ok] %-36s %s\n", $label, $value);
}
function fail(string $msg): void
{
    fwrite(STDERR, "  [FAIL] $msg\n");
    exit(1);
}

const USER  = 'e2e_crc';
const EMAIL = 'crc-e2e@example.org';

$u = db_escape(USER);

if ($mode === 'teardown') {
    db_query("delete from redcap_user_rights where username = '$u'");
    db_query("delete from redcap_user_information where username = '$u'");
    out('removed', USER . ' from every project');
    exit(0);
}

if ($mode !== 'setup') {
    fail("unknown mode '$mode' - expected setup or teardown");
}

// The mapping the module reads, not a role id typed here: if the setting changes, so does the test.
$mapped = \ExternalModules\ExternalModules::getProjectSetting('proj_mica', $pid, 'role-ra-reviewer');
$mapped = array_values(array_filter(array_map('intval', is_array($mapped) ? $mapped : [$mapped])));
if ($mapped === []) {
    fail("project $pid maps no REDCap role as MICA Reviewer (setting role-ra-reviewer)");
}
$role = $mapped[0];

$name = db_result(db_query("select role_name from redcap_user_roles where project_id = $pid and role_id = $role"), 0);
if ($name === false || $name === null) {
    fail("role $role is mapped as Reviewer but does not exist on project $pid");
}

db_query("delete from redcap_user_rights where username = '$u'");
db_query("delete from redcap_user_information where username = '$u'");

if (
    !db_query("insert into redcap_user_information
        (username, user_email, user_firstname, user_lastname, user_creation, super_user, account_manager)
        values ('$u', '" . db_escape(EMAIL) . "', 'E2E', 'CRC', now(), 0, 0)")
) {
    fail('redcap_user_information insert failed: ' . db_error());
}
if (!db_query("insert into redcap_user_rights (project_id, username, role_id) values ($pid, '$u', $role)")) {
    fail('redcap_user_rights insert failed: ' . db_error());
}
out('user', USER . ' <' . EMAIL . '>');
out('role', "$role \"$name\" (mapped as MICA Reviewer)");

// Proven the way the module will resolve it at send time, not assumed from the inserts.
$ids = implode(',', $mapped);
$q = db_query("select distinct i.user_email from redcap_user_rights r
    join redcap_user_information i on i.username = r.username
    where r.project_id = $pid and r.role_id in ($ids)
      and i.user_suspended_time is null and i.user_email is not null and i.user_email != ''");
$addresses = [];
while ($row = db_fetch_assoc($q)) {
    $addresses[] = $row['user_email'];
}
if (!in_array(EMAIL, $addresses, true)) {
    fail('the reviewer query does not resolve ' . EMAIL);
}
out('"findings ready" would reach', implode(', ', $addresses));
