<?php
/**
 * Adds the `ed_session_url` field that receives each record's ED Day-1 session link.
 *
 *   php apply-ed-session-url-field.php [pid] [form] [field]
 *
 * Defaults: pid=257, form=admin, field=ed_session_url.
 *
 * Idempotent: re-running reports "already present" and changes nothing.
 *
 * WHY THIS FIELD IS NEEDED. The session instrument is designated to one event **per intervention
 * arm**, so there is no static expression for "this record's session". REDCap's
 * `[survey-url:mica_ed_session]` resolves against the *context* event (`Piping.php:1896`), which in
 * the participant chain is the arm-1 event where the instrument does not exist; naming an event
 * explicitly hardcodes an arm and is wrong for the other one. So the arm is resolved once per
 * record at randomization and the URL stored here - which is what lets a survey's *Redirect to a
 * URL* be a bare `[ed_session_url]`, with no arm knowledge and no event prefix.
 *
 * The field's **existence is the on/off switch** for `MICA::ensureEdSessionLink()`. Creating it
 * turns the behaviour on; there is no separate checkbox to forget.
 *
 * ACTION TAGS. `@READONLY` is not decoration: a survey redirect follows whatever is in this field,
 * so a hand-edited value sends a participant somewhere nobody chose. `@HIDDEN-SURVEY` matches the
 * project's convention for module-written fields (`calcrnd`, `rnd`, `calc_code_check`) and is
 * belt-and-braces today, since `admin` has no row in `redcap_surveys`.
 *
 * NOTE: this writes redcap_metadata directly, which only takes effect immediately while the project
 * is in DEVELOPMENT status. In production REDCap requires the draft/approval workflow - use the
 * Designer or a Data Dictionary import there.
 */

$pid   = (int)    ($argv[1] ?? 257);
$form  = (string) ($argv[2] ?? 'admin');
$field = (string) ($argv[3] ?? 'ed_session_url');

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

const LABEL = 'ED Day-1 session link (auto-filled at randomization)';
const NOTE  = 'Written by the MICA module when the record is randomized. Read-only: a survey redirect follows this value. Standard Care records correctly stay blank.';
const TAGS  = '@HIDDEN-SURVEY @READONLY';

echo "\n=== $field field on project $pid ===\n";

$Proj = new Project($pid, true);

if ((int) $Proj->project['status'] !== 0) {
    echo "  [warn] project status is {$Proj->project['status']} (not development).\n";
    echo "         Direct metadata edits do not take effect in production - use the Designer\n";
    echo "         or a Data Dictionary import instead. Aborting.\n";
    exit(1);
}

if (!isset($Proj->forms[$form])) fail("form '$form' does not exist in project $pid");
out("form '$form'", 'exists');

if (isset($Proj->metadata[$field])) {
    out($field, 'already present on form ' . $Proj->metadata[$field]['form_name'] . ' - skipped');
    echo "\n*** NOTHING TO DO ***\n";
    exit(0);
}

// Anchor immediately before the form-status field, exactly as apply-study-group-field.php does.
$anchor = $form . '_complete';
$row = db_fetch_assoc(db_query("select field_order, form_menu_description from redcap_metadata
    where project_id = $pid and field_name = '" . db_escape($anchor) . "'"));
if (!$row) fail("could not find $anchor to anchor the insert");

$order = (int) $row['field_order'];
$menu  = $row['form_menu_description'];

if (!db_query("update redcap_metadata set field_order = field_order + 1
        where project_id = $pid and field_order >= $order order by field_order desc")) {
    fail('could not shift field_order: ' . db_error());
}

$ok = db_query("insert into redcap_metadata
    (project_id, field_name, form_name, field_order, element_type, element_label,
     element_note, misc, field_req, form_menu_description)
    values ($pid, '" . db_escape($field) . "', '" . db_escape($form) . "', $order,
            'text', '" . db_escape(LABEL) . "',
            '" . db_escape(NOTE) . "', '" . db_escape(TAGS) . "', 0,
            " . ($menu === null ? 'null' : "'" . db_escape($menu) . "'") . ")");
if (!$ok) fail('metadata insert failed: ' . db_error());

// REDCap keeps form_menu_description on a form's FIRST field only.
if ($menu !== null) {
    db_query("update redcap_metadata set form_menu_description = null
              where project_id = $pid and field_name = '" . db_escape($anchor) . "'");
}

out($field, "inserted at field_order $order on $form");

// Verify independently of the insert logic.
$check = db_fetch_assoc(db_query("select form_name, element_type, misc from redcap_metadata
    where project_id = $pid and field_name = '" . db_escape($field) . "'"));
if (!$check) fail('field is not readable back after insert');
out('verified', "{$check['element_type']} on {$check['form_name']}");
out('action tags', (string) $check['misc']);

$dupes = (int) db_result(db_query("select count(1) - count(distinct field_order) from redcap_metadata
    where project_id = $pid"), 0);
if ($dupes !== 0) fail("field_order integrity broken: $dupes duplicate(s)");
out('field_order integrity', 'no duplicates');

echo "\n*** DONE ***\n";
echo "The field's existence is the switch - MICA::ensureEdSessionLink() is now live for this\n";
echo "project. Remaining work is REDCap configuration, not code:\n";
echo "  1. Enable + set up Randomization: target field study_group at arm-1 Day 1,\n";
echo "     trigger instrument baseline1, and TRIGGER OPTION 2 ('for all users, including\n";
echo "     survey respondents') - option 1 is skipped on survey pages and fails silently.\n";
echo "  2. Upload allocation tables for BOTH development and production.\n";
echo "  3. Set the handoff survey's 'Redirect to a URL' to [$field].\n";
echo "  4. Name a field in the module's 'Stamp the randomization date' setting, or alerts\n";
echo "     02-14 lose their anchor once randomization is automated.\n";
echo "See docs/phase-3-handoff/24-ed-session-handoff.md\n";
