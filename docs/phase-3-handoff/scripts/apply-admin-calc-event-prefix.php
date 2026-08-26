<?php
/**
 * Clears the `admin` form's "syntactical errors in the Branching Logic and/or Calculations"
 * banner for the four fields whose ONLY defect is a stale event prefix.
 *
 *   php apply-admin-calc-event-prefix.php [--dry-run] [pid] [form]
 *
 * Defaults: pid=257, form=admin.
 *
 * Idempotent: re-running reports "already clean" and changes nothing.
 *
 * WHAT IT DOES. `calc_month_3`, `calc_month_6`, `calc_month_12` and `first_monday` carry
 * `@CALCDATE([baseline_arm_1][randomization_date], ...)` action tags. `randomization_date`
 * exists on this project; the event `baseline_arm_1` does not (it came from PID 192 / ASPIRE,
 * which `admin` was copied from). REDCap's LogicTester resolves every `[token]` against the
 * project's own field and event list, so the unresolvable event prefix is what makes it call
 * these "syntactical errors" - the expressions themselves parse fine.
 *
 * WHY DROP THE PREFIX RATHER THAN SUBSTITUTE AN EVENT. `admin` is assigned to exactly one
 * event per arm (1004 / 1008 / 1012, all "Day 1 (ED)"). A bare `[randomization_date]` resolves
 * within the current event on all three arms. Hardcoding `[day_1_ed_arm_1]` would pin the calc
 * to arm 1 and silently produce nothing for arms 2 and 3. `weekday` on this same form already
 * uses a bare `[randomization_date]` and validates cleanly.
 *
 * SCOPE. `[baseline_arm_1]` has 39 references project-wide (`audit.audit3_b`,
 * `sunday.bd_1..bd_11`, and 35 more). This script touches ONLY the four `admin` fields listed
 * in TARGETS - the rest are a separate, researcher-owned cleanup. It also does not touch the
 * fields that need a `consent_date` / `group` / `dummy_email` / `time_diff` decision. See
 * ../19-admin-form-logic-errors.md for the full three-bucket split.
 *
 * NOTE: this writes redcap_metadata directly, which only takes effect immediately while the
 * project is in DEVELOPMENT status. In production REDCap requires the draft/approval workflow -
 * use the Designer or a Data Dictionary import there.
 */

$args   = array_slice($argv, 1);
$dryRun = false;
foreach ($args as $i => $a) {
    if ($a === '--dry-run' || $a === '-n') { $dryRun = true; unset($args[$i]); }
}
$args = array_values($args);

$pid  = (int)    ($args[0] ?? 257);
$form = (string) ($args[1] ?? 'admin');

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
function warn(string $msg): void { echo "  [warn] $msg\n"; }
function fail(string $msg): void { fwrite(STDERR, "  [FAIL] $msg\n"); exit(1); }

/** The stale event prefix, and the four fields allowed to be rewritten. */
const NEEDLE      = '[baseline_arm_1][randomization_date]';
const REPLACEMENT = '[randomization_date]';
const TARGETS     = ['calc_month_3', 'calc_month_6', 'calc_month_12', 'first_monday'];

echo "\n=== admin calc event-prefix fix on project $pid" . ($dryRun ? ' (DRY RUN)' : '') . " ===\n";

$Proj = new Project($pid, true);

if ((int) $Proj->project['status'] !== 0) {
    echo "  [warn] project status is {$Proj->project['status']} (not development).\n";
    echo "         Direct metadata edits do not take effect in production - use the Designer\n";
    echo "         or a Data Dictionary import instead. Aborting.\n";
    exit(1);
}

if (!isset($Proj->forms[$form])) fail("form '$form' does not exist in project $pid");
out("form '$form'", 'exists');

// Precondition: the replacement is only safe because `randomization_date` actually exists and
// `admin` sits on one event per arm. Assert both rather than assuming.
if (!isset($Proj->metadata['randomization_date'])) {
    fail('randomization_date does not exist in this project - the bare reference would not resolve either');
}
out('randomization_date', 'exists on form ' . $Proj->metadata['randomization_date']['form_name']);

$placement = db_query("select ef.event_id, e.descrip, a.arm_num
    from redcap_events_forms ef
    join redcap_events_metadata e on e.event_id = ef.event_id
    join redcap_events_arms a on a.arm_id = e.arm_id
    where a.project_id = $pid and ef.form_name = '" . db_escape($form) . "'
    order by a.arm_num, e.day_offset");
$eventsByArm = [];
while ($r = db_fetch_assoc($placement)) $eventsByArm[(int) $r['arm_num']][] = $r['event_id'];
if (!$eventsByArm) fail("form '$form' is not assigned to any event");
foreach ($eventsByArm as $arm => $eventIds) {
    if (count($eventIds) > 1) {
        fail("form '$form' is on " . count($eventIds) . " events in arm $arm (" . implode(', ', $eventIds) . ").\n"
           . "         A bare field reference would be ambiguous across events - this fix assumes one\n"
           . "         event per arm. Re-check the repair before proceeding.");
    }
}
out('form placement', count($eventsByArm) . ' arm(s), one event each: '
    . implode(', ', array_map(fn($a, $e) => "arm $a=" . $e[0], array_keys($eventsByArm), $eventsByArm)));

if (array_search('baseline_arm_1', \REDCap::getEventNames(true, false), true) !== false) {
    fail("this project DOES have a baseline_arm_1 event - the premise of this fix does not hold");
}
out('baseline_arm_1 event', 'confirmed absent (this is the defect)');

// Rewrite, one field at a time, only where the needle is actually present.
$changed = $clean = 0;
echo "\n  --- fields ---\n";
foreach (TARGETS as $field) {
    $row = db_fetch_assoc(db_query("select form_name, misc from redcap_metadata
        where project_id = $pid and field_name = '" . db_escape($field) . "'"));

    if (!$row)                      { warn("$field: not present in project $pid - skipped"); continue; }
    if ($row['form_name'] !== $form){ warn("$field: on form '{$row['form_name']}', not '$form' - skipped"); continue; }

    $before = (string) $row['misc'];
    if (strpos($before, NEEDLE) === false) {
        if (strpos($before, '[baseline_arm_1]') !== false) {
            warn("$field: has a [baseline_arm_1] reference that is NOT the expected\n"
               . "         '" . NEEDLE . "' - left alone, needs a look:\n"
               . "         $before");
        } else {
            out($field, 'already clean - skipped');
            $clean++;
        }
        continue;
    }

    $after = str_replace(NEEDLE, REPLACEMENT, $before);
    echo "  [" . ($dryRun ? '--' : 'ok') . "] $field\n";
    echo "         before: " . str_replace("\n", ' ', $before) . "\n";
    echo "         after:  " . str_replace("\n", ' ', $after)  . "\n";

    if (!$dryRun) {
        $ok = db_query("update redcap_metadata set misc = '" . db_escape($after) . "'
            where project_id = $pid and field_name = '" . db_escape($field) . "'");
        if (!$ok) fail("update failed for $field: " . db_error());
    }
    $changed++;
}

if ($dryRun) {
    echo "\n*** DRY RUN - nothing written. $changed field(s) would change, $clean already clean. ***\n";
    exit(0);
}

// Verify independently of the update logic: re-read and assert the needle is gone.
echo "\n  --- verification ---\n";
foreach (TARGETS as $field) {
    $misc = db_result(db_query("select misc from redcap_metadata
        where project_id = $pid and field_name = '" . db_escape($field) . "'"), 0);
    if ($misc === null) continue;
    if (strpos((string) $misc, '[baseline_arm_1]') !== false) fail("$field still references [baseline_arm_1]");
    out($field, str_replace("\n", ' ', (string) $misc));
}

// Scope guard: report, do not touch, everything else still pointing at the phantom event.
$remaining = (int) db_result(db_query("select count(1) from redcap_metadata
    where project_id = $pid and (
        coalesce(misc,'')            like '%[baseline_arm_1]%' or
        coalesce(element_enum,'')    like '%[baseline_arm_1]%' or
        coalesce(branching_logic,'') like '%[baseline_arm_1]%' or
        coalesce(element_label,'')   like '%[baseline_arm_1]%' or
        coalesce(element_note,'')    like '%[baseline_arm_1]%')"), 0);
out('[baseline_arm_1] refs left project-wide', (string) $remaining . ' (out of scope - see 19-admin-form-logic-errors.md)');

echo "\n*** DONE - $changed field(s) updated, $clean already clean. ***\n";
echo "The banner will still list the bucket-C fields, which need a researcher decision:\n";
echo "  branching logic: desc_valid_dummy_email, desc_group_assigned, desc_valid_consent_2\n";
echo "  calculations:    calc_esms_valid, calc_valid_fup_emails, first_monday_1200, first_monday_1700,\n";
echo "                   calc_week_6, calc_week_12, debug_calc_1, debug_calc_2 (last four are dead - bucket B)\n";
echo "\n@CALCDATE values are computed on form load and stored on save, so existing records show the\n";
echo "new dates only after their `$form` form is saved again.\n";
