<?php
/**
 * Adds the `study_group` allocation field that the arm-materialisation hook reads.
 *
 *   php apply-study-group-field.php [pid] [form] [field]
 *
 * Defaults: pid=257, form=admin, field=study_group.
 *
 * Idempotent: re-running reports "already present" and changes nothing.
 *
 * Why this field is needed: nothing in PID 257 stored the assigned arm. `randomize_trigger` and
 * `randomization_date` exist on `admin`, but `desc_group_assigned` is a *descriptive* field
 * (display only) and REDCap's built-in Randomization module is disabled, so no code could tell
 * which arm a participant belongs to. The values are deliberately the arm numbers, which is the
 * mapping MICA::redcap_save_record() relies on.
 *
 * NOTE: this writes redcap_metadata directly, which only takes effect immediately while the
 * project is in DEVELOPMENT status. In production REDCap requires the draft/approval workflow -
 * use the Designer or a Data Dictionary import there.
 */

$pid   = (int)    ($argv[1] ?? 257);
$form  = (string) ($argv[2] ?? 'admin');
$field = (string) ($argv[3] ?? 'study_group');

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

// REDCap stores select choices separated by a literal backslash-n (two characters), matching the
// existing radio fields on this project - keep this in single quotes.
const CHOICES = '1, Standard Care (SC)\n2, MICA\n3, MICA + Weekly SMS';
const LABEL   = 'Study group assigned at randomization';
const NOTE    = 'Set this at randomization. The value is also the arm number: the record is added to that arm automatically.';

echo "\n=== study_group field on project $pid ===\n";

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

// Anchor the insert immediately before the form-status field, exactly as apply-auth-config.php does.
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
    (project_id, field_name, form_name, field_order, element_type, element_label, element_enum,
     element_note, field_req, form_menu_description)
    values ($pid, '" . db_escape($field) . "', '" . db_escape($form) . "', $order,
            'radio', '" . db_escape(LABEL) . "', '" . db_escape(CHOICES) . "',
            '" . db_escape(NOTE) . "', 0,
            " . ($menu === null ? 'null' : "'" . db_escape($menu) . "'") . ")");
if (!$ok) fail('metadata insert failed: ' . db_error());

// REDCap keeps form_menu_description on a form's FIRST field only.
if ($menu !== null) {
    db_query("update redcap_metadata set form_menu_description = null
              where project_id = $pid and field_name = '" . db_escape($anchor) . "'");
}

out($field, "inserted at field_order $order on $form");

// Verify independently of the insert logic.
$check = db_fetch_assoc(db_query("select form_name, element_type, element_enum from redcap_metadata
    where project_id = $pid and field_name = '" . db_escape($field) . "'"));
if (!$check) fail('field is not readable back after insert');
out('verified', "{$check['element_type']} on {$check['form_name']}");
out('choices', str_replace('\n', ' | ', $check['element_enum']));

$dupes = (int) db_result(db_query("select count(1) - count(distinct field_order) from redcap_metadata
    where project_id = $pid"), 0);
if ($dupes !== 0) fail("field_order integrity broken: $dupes duplicate(s)");
out('field_order integrity', 'no duplicates');

echo "\n*** DONE ***\n";
echo "Next: tick 'Automatically add the record to its randomized arm' in the MICA module's\n";
echo "project settings, and make sure `study_group` is set at randomization.\n";
