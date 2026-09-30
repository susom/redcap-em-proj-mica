<?php
/**
 * Rewrite the `sunday` instrument so the Arm-3 weekly SMS can actually run.
 *
 *   php apply-weekly-sms-branching.php [pid] [--dry-run]
 *
 * Defaults: pid=268.
 *
 * WHAT IS WRONG WITH `sunday` AS SHIPPED. It is an unported ASPIRE/TRAM artifact. Every week gate
 * names an event that does not exist in MICA (`week_N_sms_arm_1`), and every cross-event reference
 * names an event that does not exist either (`baseline_arm_1`). REDCap refuses to render the survey
 * at all - "Branching Logic errors exist in these fields: sd_1 … bd_12".
 *
 * Beyond the dead event names, the gates are simply mis-authored. The message library is written as
 * twelve DISTINCT messages per branch, one per week, but:
 *
 *   ar_1  … ar_11  -> weeks 1-11   (correct)
 *   ar_12          -> week 11      (duplicate - wrong in ASPIRE's own terms)
 *   sd_1  … sd_12  -> week 12      (ALL twelve on one week)
 *   bd_1  … bd_12  -> week 12      (ALL twelve on one week)
 *   gp_1  … gp_12  -> week 1       (ALL twelve on one week)
 *   gg_1  … gg_12  -> week 1       (ALL twelve on one week)
 *
 * So 60 of the 63 messages were unreachable or would all fire at once. This script puts each
 * message on its own week.
 *
 * THE WEEK GATE IS `[current-instance]`, NOT `[event-name]`. MICA arm 3 has a single
 * `Weeks 1-12` event rather than ASPIRE's twelve `Week N SMS` events, and the decision taken
 * 2026-09-21 was to make that event repeat 12 times instead of creating twelve events. REDCap
 * supports this: recurring ASIs require the event/form to be repeating
 * (`Classes/SurveyScheduler.php:1359-1362`) and allocate the next instance per recurrence, and the
 * Enhanced SMS Conversation module evaluates branching instance-aware
 * (`classes/FormManager.php:392-393`).
 *
 * THE CROSS-EVENT PREFIX IS `[day_1_ed_arm_1]`, NOT `[day_1_ed_arm_3]`. `first_name` and
 * `calc_binge_threshold` live on `contact_info` / `auditc`; `contact_info` is designated at the
 * arm-1 Day-1 event only, and in MICA every participant is enrolled there before being
 * materialized into their randomized arm. `[day_1_ed_arm_3][first_name]` resolves to blank.
 *
 * TWO CORRECTNESS FIXES BEYOND THE PORT:
 *
 *   1. Blank guards. REDCap's logic engine compares loosely, so `[dquant]=0` is true while `dquant`
 *      is still unanswered - which is why every feedback message rendered simultaneously. Each
 *      gate now requires `[dquant] <> ''`. Same for `no_plan`, which displayed "Great! Thanks for
 *      letting us know." before `drink_fut` had been asked.
 *   2. `ar_*` and `sd_*` no longer overlap. `sd_*` was `[dquant] < threshold`, which is also true
 *      at zero drinks, so an alcohol-free week fired both the congratulation and "You reported
 *      drinking this week while staying below higher-risk levels". `sd_*` now requires
 *      `[dquant] > 0`.
 *
 * Idempotent: computes the target string for every field and only writes where it differs.
 * Development status only - it writes `redcap_metadata`, which production ignores in favour of the
 * draft/approval workflow.
 */

$pid    = (int) ($argv[1] ?? 268);
$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

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

require_once __DIR__ . '/weekly-sms-sunday-spec.php';   // the target state - shared with the CSV patcher
const FORM        = MICA_WEEKLY_SMS_FORM;
const ENROL_EVENT = MICA_WEEKLY_SMS_ENROL_EVENT;

$Proj = new Project($pid, true);

echo "\n=== sunday weekly-SMS rewrite, project $pid" . ($dryRun ? ' (DRY RUN)' : '') . " ===\n";

$status = db_result(db_query("select status from redcap_projects where project_id = $pid"), 0);
if ((string) $status !== '0') {
    fwrite(STDERR, "  project $pid is not in development status (status=$status). Aborting - direct\n"
                 . "  redcap_metadata writes are ignored by production. Use the Designer there.\n");
    exit(1);
}

// ---------------------------------------------------------------------------------------------
// `calc_gset_threshold` - the goal threshold `gset` asks the participant to commit to.
//
// ASPIRE keys it on `birth_sex` (4 for male, 3 otherwise) and parks it on `screen`. MICA has no
// `birth_sex` field at all; it uses `s_sex`, which is what the sibling `calc_binge_threshold`
// already keys on. So the equation is ported onto `s_sex` and the field is placed immediately
// after `calc_binge_threshold` on `auditc`, where the CRC-facing pair reads together.
//
// field_order integrity is maintained the way apply-study-group-field.php does it: shift
// everything at or after the insertion point, insert, then assert no duplicates.
// ---------------------------------------------------------------------------------------------
$gsetSpec  = mica_weekly_sms_gset_field();
$gsetField = $gsetSpec['field_name'];
$exists = (int) db_result(db_query(
    "select count(*) from redcap_metadata where project_id = $pid and field_name = '$gsetField'"), 0);

if ($exists) {
    echo "  field   $gsetField already present\n";
} elseif ($dryRun) {
    echo "  field   $gsetField would be created on `auditc` after calc_binge_threshold\n";
} else {
    $anchor = db_fetch_assoc(db_query(
        "select field_order, form_name from redcap_metadata
          where project_id = $pid and field_name = ?", [$gsetSpec['after']]));
    if (!$anchor) {
        fwrite(STDERR, "  [!!] calc_binge_threshold not found - cannot place $gsetField. Aborting.\n");
        exit(1);
    }
    $order = (int) $anchor['field_order'] + 1;
    $form  = $anchor['form_name'];

    db_query("update redcap_metadata set field_order = field_order + 1
               where project_id = $pid and field_order >= $order order by field_order desc");

    db_query("insert into redcap_metadata
              (project_id, field_name, form_name, field_order, element_type, element_label,
               element_enum, misc)
              values (?, ?, ?, ?, ?, ?, ?, ?)",
        [$pid, $gsetField, $form, $order, $gsetSpec['element_type'], $gsetSpec['label'],
         $gsetSpec['equation'], $gsetSpec['annotation']]);

    $dupes = (int) db_result(db_query(
        "select count(1) - count(distinct field_order) from redcap_metadata where project_id = $pid"), 0);
    if ($dupes !== 0) {
        fwrite(STDERR, "  [!!] field_order integrity broken: $dupes duplicate(s)\n");
        exit(1);
    }
    echo "  field   $gsetField created on `$form` at field_order $order (field_order integrity ok)\n";
}

// ---------------------------------------------------------------------------------------------
// Target branching logic, per field.
// ---------------------------------------------------------------------------------------------
$target            = mica_weekly_sms_branching(ENROL_EVENT);
$labelRewrites     = mica_weekly_sms_label_rewrites(ENROL_EVENT);
$fullLabelRewrites = mica_weekly_sms_full_labels(ENROL_EVENT);

// ---------------------------------------------------------------------------------------------
// Apply.
// ---------------------------------------------------------------------------------------------
$q = db_query("select field_name, element_type, branching_logic, element_label
               from redcap_metadata
               where project_id = $pid and form_name = '" . FORM . "'
               order by field_order");

$changedLogic = $changedLabel = $unchanged = 0;
$missing = [];

while ($row = db_fetch_assoc($q)) {
    $f = $row['field_name'];

    // --- branching logic ---
    if (isset($target[$f])) {
        $want = $target[$f];
        $have = (string) $row['branching_logic'];
        if (trim($have) !== $want) {
            if (!\LogicTester::isValid($want)) {
                fwrite(STDERR, "  [!!] $f: target logic is not valid REDCap logic, refusing: $want\n");
                exit(1);
            }
            printf("  logic   %-18s %s\n", $f, $want);
            if (!$dryRun) {
                db_query("update redcap_metadata set branching_logic = ? "
                       . "where project_id = $pid and field_name = ?", [$want, $f]);
            }
            $changedLogic++;
        } else {
            $unchanged++;
        }
        unset($target[$f]);
    }

    // --- labels ---
    $label = (string) $row['element_label'];
    $newLabel = $fullLabelRewrites[$f]
        ?? str_replace(array_keys($labelRewrites), array_values($labelRewrites), $label);
    if ($newLabel !== $label) {
        printf("  label   %-18s %s\n", $f, mb_substr($newLabel, 0, 70));
        if (!$dryRun) {
            db_query("update redcap_metadata set element_label = ? "
                   . "where project_id = $pid and field_name = ?", [$newLabel, $f]);
        }
        $changedLabel++;
    }
}

if (!empty($target)) {
    $missing = array_keys($target);
    fwrite(STDERR, "\n  [!!] these fields were expected on `" . FORM . "` and are absent: "
                 . implode(', ', $missing) . "\n");
}

// ---------------------------------------------------------------------------------------------
// Anything left pointing at an event MICA does not have is a failure, not a warning.
// ---------------------------------------------------------------------------------------------
$leftover = db_result(db_query(
    "select count(*) from redcap_metadata
      where project_id = $pid and form_name = '" . FORM . "'
        and (coalesce(branching_logic,'') regexp 'week_[0-9]+_sms_arm_1'
          or coalesce(branching_logic,'') like '%baseline_arm_1%'
          or coalesce(element_label,'')  like '%baseline_arm_1%')"), 0);

echo "\n--- summary ---\n";
echo "  branching rewritten   $changedLogic\n";
echo "  branching already ok  $unchanged\n";
echo "  labels rewritten      $changedLabel\n";
echo "  dead-event refs left  $leftover" . ($leftover > 0 && !$dryRun ? "   <-- SHOULD BE 0" : "") . "\n";
echo "  fields missing        " . count($missing) . "\n";

echo ($leftover == 0 || $dryRun) && empty($missing) ? "\n*** DONE ***\n" : "\n*** INCOMPLETE ***\n";
