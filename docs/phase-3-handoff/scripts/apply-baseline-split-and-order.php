<?php
/**
 * Split `baseline1` into ID + demographics, and put the Day-1 instruments in flow order.
 *
 *   php apply-baseline-split-and-order.php [pid] [--dry-run]
 *
 * Defaults: pid=257. Idempotent - a second run reports "already applied" and changes nothing.
 *
 * ## What the PI asked for (2026-08-31)
 *
 *   "Demographics needs to be separated from ID stuff. ID stuff should come after consent.
 *    Then Check code. Then Demographics and rest of baseline stuff.
 *    MICA is not auto-loading after the TSR."
 *
 * Three changes, in that order:
 *
 * 1. **Split.** `baseline1` ("Demographics & BL Data") carries both the contact/identity fields and
 *    the demographics questions. The contact fields move to a new `contact_info` instrument. The
 *    three hidden fields move with them and that is not cosmetic: `calcrnd` is the passcode
 *    `check_code` validates, so it has to be generated *before* check code runs.
 *
 * 2. **Order.** consent -> person_obtaining_consent -> contact_info -> check_code -> sms_code_check
 *    -> baseline1 (demographics) -> the battery -> tsr -> session -> postsession -> close, with
 *    auto-continue closed across the gaps that currently stop the chain.
 *
 * 3. **MICA after TSR.** `mica_ed_session` is moved to sit immediately after `tsr`, and `tsr` gets
 *    auto-continue. No redirect and no piped URL is involved, because REDCap's auto-continue only
 *    considers instruments designated to the participant's **current event**
 *    (`Survey::getAutoContinueSurveyUrl`, `Classes/Survey.php:2702`). That makes the branching free:
 *    arms 2/3 at Day 1 land on the session, Standard Care skips it because it is not designated to
 *    their event, and Month 3 skips it too and picks up `mica_booster_session` instead - which is
 *    why the booster is ordered directly after it rather than left where it was.
 *
 * ## What it deliberately does NOT do
 *
 * `baseline1` keeps its form name. Renaming it to `demographics` would rename `baseline1_complete`,
 * which two alert conditions test and which is the kind of rename that breaks quietly. Only its
 * survey *title* changes, which is what a participant sees.
 *
 * ## Production
 *
 * This writes `redcap_metadata` directly, which only takes effect in DEVELOPMENT status - the script
 * refuses to run otherwise. On production the same change is a Data Dictionary import (the field
 * moves and the new form) plus the survey settings by hand; see 26-baseline-split-runbook.md.
 */

$pid    = (int) ($argv[1] ?? 257);
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

function out(string $l, string $v): void { printf("  [ok] %-40s %s\n", $l, $v); }
function note(string $l, string $v): void { printf("       %-40s %s\n", $l, $v); }
function fail(string $m): void { fwrite(STDERR, "  [FAIL] $m\n"); exit(1); }

// ---------------------------------------------------------------------------- the intended shape

/** Fields that move out of `baseline1` into the new ID instrument, in order. */
const ID_FIELDS = ['first_name', 'last_name', 'email', 'phonen', 'choice_fup_delivery',
                   'rnd', 'calcrnd', 'dummy_email'];

const NEW_FORM       = 'contact_info';
const NEW_FORM_MENU  = 'Contact & ID';
const NEW_SURVEY_TTL = 'Enrollment';          // baseline1's current participant-facing title
const OLD_SURVEY_TTL = 'Demographics';        // what baseline1 becomes once the ID fields leave

/**
 * Every instrument, in the order the Designer should show them.
 *
 * The battery order is the participant flow. The four that follow `close` are off-battery
 * (arm-3 weekly SMS, opt-out) or staff-only, and are grouped at the end so the middle of the list
 * reads as the Day-1 journey.
 */
const FORM_ORDER = [
    'pre_screen', 'screen', 'screen_eligibility', 'consent', 'person_obtaining_consent',
    NEW_FORM, 'check_code', 'sms_code_check', 'baseline1',
    'ddq', 'audit', 'sip2r', 'phq', 'bscq', 'drug_use', 'tsr',
    'mica_ed_session', 'mica_booster_session', 'postsession', 'close',
    'sunday', 'sms_opt_out', 'admin', 'mica_safety_finding',
];

/** Auto-continue ON for every link in the Day-1 chain except the ones that must break. */
const AUTO_CONTINUE_ON = ['pre_screen', 'screen', 'screen_eligibility', 'consent',
    NEW_FORM, 'check_code', 'sms_code_check', 'baseline1',
    'ddq', 'audit', 'sip2r', 'phq', 'bscq', 'drug_use', 'tsr'];

/**
 * Auto-continue OFF, each for its own reason:
 *  - person_obtaining_consent: the staff consent-witness signature. The chain is *meant* to stop
 *    while the CRC signs; turning it on would carry the participant past it.
 *  - mica_ed_session / mica_booster_session: the chat SPA never submits the survey form, so there
 *    is no submit for auto-continue to follow. The participant leaves via End Session.
 *  - postsession / close: close is the last thing anyone should see.
 */
const AUTO_CONTINUE_OFF = ['person_obtaining_consent', 'mica_ed_session', 'mica_booster_session',
                           'postsession', 'close'];

echo "\n=== baseline split + flow order on project $pid" . ($dryRun ? ' (DRY RUN)' : '') . " ===\n";

$Proj = new Project($pid, true);

if ((int) $Proj->project['status'] !== 0) {
    fail("project status is {$Proj->project['status']} (not development). Direct metadata edits do "
        . 'not take effect in production - use a Data Dictionary import instead. Aborting.');
}

if (!isset($Proj->forms['baseline1'])) fail("project $pid has no 'baseline1' form");

$alreadySplit = isset($Proj->forms[NEW_FORM]);
if ($alreadySplit) {
    note('split', NEW_FORM . ' already exists - skipping steps 1-3, re-asserting order and settings');
}

// ------------------------------------------------------------------- 1. move the ID fields across

if (!$alreadySplit) {
    $missing = array_diff(ID_FIELDS, array_keys($Proj->metadata));
    if ($missing) fail('these ID fields do not exist: ' . implode(', ', $missing));

    foreach (ID_FIELDS as $f) {
        $onForm = $Proj->metadata[$f]['form_name'];
        if ($onForm !== 'baseline1') fail("field '$f' is on '$onForm', not baseline1 - refusing to guess");
    }

    if (!$dryRun) {
        $in = "'" . implode("','", array_map('db_escape', ID_FIELDS)) . "'";
        if (!db_query("update redcap_metadata set form_name = '" . NEW_FORM . "'
                        where project_id = $pid and field_name in ($in)")) {
            fail('could not move the ID fields: ' . db_error());
        }
    }
    out('moved to ' . NEW_FORM, implode(', ', ID_FIELDS));

    // --------------------------------------------------------- 2. the new form's _complete field
    // Every REDCap instrument owns a `<form>_complete` field; without it the form has no status.
    if (!$dryRun) {
        $maxOrd = (int) db_result(db_query("select max(field_order) from redcap_metadata where project_id = $pid"), 0);
        if (!db_query("insert into redcap_metadata
                (project_id, field_name, form_name, field_order, element_type, element_label, element_enum, field_req)
                values ($pid, '" . NEW_FORM . "_complete', '" . NEW_FORM . "', " . ($maxOrd + 1) . ",
                        'select', 'Complete?', '0, Incomplete \\\\n 1, Unverified \\\\n 2, Complete', 0)")) {
            fail('could not create the form-status field: ' . db_error());
        }
    }
    out(NEW_FORM . '_complete', 'created');

}

// ------------------------------------------- 3. designate it to baseline1's own events, and a survey
//
// Checked independently of the field move rather than inside the same guard. The first version
// gated all of this on "does the form exist in metadata", which meant a failure part-way through
// left a form with fields, no survey row, and no way to finish except by hand. Each step now
// asserts its own end state, so a re-run completes whatever is missing.

// NOTE: `redcap_events_forms` has NO project_id column - it is shared across every project on the
// instance, and `contact_info` is a common instrument name (six other projects here own one). Every
// read and write against it must be scoped through redcap_events_metadata/arms, or it silently
// touches other studies.
$eventsScoped = "(select e.event_id from redcap_events_metadata e
                  join redcap_events_arms a on a.arm_id = e.arm_id where a.project_id = $pid)";

$designated = (int) db_result(db_query("select count(*) from redcap_events_forms
    where form_name = '" . NEW_FORM . "' and event_id in $eventsScoped"), 0);

if ($designated === 0) {
    if (!$dryRun) {
        if (!db_query("insert into redcap_events_forms (event_id, form_name)
                       select ef.event_id, '" . NEW_FORM . "' from redcap_events_forms ef
                        where ef.form_name = 'baseline1' and ef.event_id in $eventsScoped")) {
            fail('could not designate the new form: ' . db_error());
        }
    }
    out(NEW_FORM . ' designated', "to baseline1's events");
} else {
    note(NEW_FORM . ' designated', "already on $designated event(s)");
}

$hasSurvey = (int) db_result(db_query("select count(*) from redcap_surveys
    where project_id = $pid and form_name = '" . NEW_FORM . "'"), 0);

if ($hasSurvey === 0) {
    if (!$dryRun) {
        /*
         * Cloned from baseline1 rather than hand-built: it already carries the study's chosen theme,
         * title display, question numbering and completion behaviour, and a survey split out of
         * another should not silently differ from it.
         *
         * `logo` is excluded because it carries a UNIQUE index - copying the edoc id collides with
         * the row it was copied from. Anything else uniquely keyed must be excluded here too, which
         * is why the exclusion list is derived from the schema rather than hardcoded.
         */
        $unique = ['survey_id'];
        $q = db_query('show index from redcap_surveys where non_unique = 0');
        while ($r = db_fetch_assoc($q)) {
            // (project_id, form_name) is a composite unique key and both are overridden below, so
            // only single-column unique keys need dropping.
            if ($r['Key_name'] !== 'PRIMARY' && $r['Seq_in_index'] == 1) {
                $isComposite = (int) db_result(db_query("select count(*) from information_schema.statistics
                    where table_schema = database() and table_name = 'redcap_surveys'
                      and index_name = '" . db_escape($r['Key_name']) . "'"), 0) > 1;
                if (!$isComposite) $unique[] = $r['Column_name'];
            }
        }

        $cols = [];
        $q = db_query('show columns from redcap_surveys');
        while ($r = db_fetch_assoc($q)) if (!in_array($r['Field'], $unique, true)) $cols[] = $r['Field'];

        $select = [];
        foreach ($cols as $c) {
            if ($c === 'form_name') { $select[] = "'" . NEW_FORM . "'"; continue; }
            if ($c === 'title')     { $select[] = "'" . db_escape(NEW_SURVEY_TTL) . "'"; continue; }
            $select[] = "`$c`";
        }
        if (!db_query('insert into redcap_surveys (`' . implode('`,`', $cols) . '`) select '
                    . implode(',', $select) . " from redcap_surveys
                       where project_id = $pid and form_name = 'baseline1' limit 1")) {
            fail('could not create the survey row: ' . db_error());
        }
        note('excluded from the clone', implode(', ', $unique) . ' (uniquely indexed)');
    }
    out(NEW_FORM . ' survey', 'created, cloned from baseline1, titled "' . NEW_SURVEY_TTL . '"');
} else {
    note(NEW_FORM . ' survey', 'already exists');
}

// --------------------------------------------------------------- 4. retitle what is left as demog

if (!$dryRun) {
    db_query("update redcap_surveys set title = '" . db_escape(OLD_SURVEY_TTL) . "'
               where project_id = $pid and form_name = 'baseline1'");
}
out('baseline1 survey title', '"' . OLD_SURVEY_TTL . '" (form name deliberately unchanged)');

// ---------------------------------------------------------------------------- 5. renumber the order

$present = [];
$q = db_query("select distinct form_name from redcap_metadata where project_id = $pid");
while ($r = db_fetch_assoc($q)) $present[] = $r['form_name'];

$unknown = array_diff($present, FORM_ORDER);
if ($unknown && !$dryRun) fail('these forms are not in FORM_ORDER, refusing to renumber: ' . implode(', ', $unknown));

if (!$dryRun) {
    // Read every field in (target form position, current intra-form order), then rewrite field_order
    // as a dense 1..N sequence. Intra-form order is preserved, so `<form>_complete` stays last.
    $rows = [];
    $q = db_query("select field_name, form_name, field_order from redcap_metadata
                    where project_id = $pid order by field_order");
    while ($r = db_fetch_assoc($q)) $rows[] = $r;

    $pos = array_flip(FORM_ORDER);
    usort($rows, static function ($a, $b) use ($pos) {
        $fa = $pos[$a['form_name']] ?? 999;
        $fb = $pos[$b['form_name']] ?? 999;
        return $fa === $fb ? ((int) $a['field_order'] <=> (int) $b['field_order']) : ($fa <=> $fb);
    });

    // Park them above the current maximum first: field_order has a uniqueness expectation per
    // project, so renumbering in place collides mid-way through.
    $park = (int) db_result(db_query("select max(field_order) from redcap_metadata where project_id = $pid"), 0) + 1000;
    foreach ($rows as $i => $r) {
        db_query("update redcap_metadata set field_order = " . ($park + $i) . "
                   where project_id = $pid and field_name = '" . db_escape($r['field_name']) . "'");
    }
    foreach ($rows as $i => $r) {
        db_query("update redcap_metadata set field_order = " . ($i + 1) . "
                   where project_id = $pid and field_name = '" . db_escape($r['field_name']) . "'");
    }

    // form_menu_description belongs to a form's FIRST field only; the reshuffle moves that field.
    db_query("update redcap_metadata set form_menu_description = null where project_id = $pid");
    foreach (FORM_ORDER as $form) {
        if (!in_array($form, $present, true)) continue;
        $menu = $form === NEW_FORM ? NEW_FORM_MENU : ($Proj->forms[$form]['menu'] ?? $form);
        db_query("update redcap_metadata set form_menu_description = '" . db_escape($menu) . "'
                   where project_id = $pid and form_name = '" . db_escape($form) . "'
                   order by field_order limit 1");
    }
}
out('field_order', count($present) . ' instruments renumbered into flow order');

// ------------------------------------------------------------------------- 6. auto-continue flags

if (!$dryRun) {
    foreach (AUTO_CONTINUE_ON as $f) {
        db_query("update redcap_surveys set end_survey_redirect_next_survey = 1
                   where project_id = $pid and form_name = '" . db_escape($f) . "'");
    }
    foreach (AUTO_CONTINUE_OFF as $f) {
        db_query("update redcap_surveys set end_survey_redirect_next_survey = 0
                   where project_id = $pid and form_name = '" . db_escape($f) . "'");
    }
}
out('auto-continue on', implode(', ', AUTO_CONTINUE_ON));
out('auto-continue off', implode(', ', AUTO_CONTINUE_OFF));

// -------------------------------------------------------------------------------- 7. the alerts

// Both test [baseline1_complete], which after the split no longer means what they intend.
//  - Alert 01 fires the passcode SMS and also tests [phonen] and [calcrnd]: all three now live on
//    the new form, so the trigger form and the status reference both move.
//  - Alert 17 tells the CRC "baseline complete". `close` still ends the battery, so only its
//    status reference changes - to the form that now carries the demographics.
if (!$dryRun) {
    db_query("update redcap_alerts set form_name = '" . NEW_FORM . "',
                     alert_condition = replace(alert_condition, '[baseline1_complete]', '[" . NEW_FORM . "_complete]')
               where project_id = $pid and alert_title like '01 %'");
    // Alert 17 is deliberately not touched: `[baseline1_complete]` still names the form that now
    // carries the demographics, which is what "baseline complete" means to the CRC.
}
out('alert 01', 'trigger form -> ' . NEW_FORM . ', [' . NEW_FORM . '_complete] in its condition');
note('alert 17', 'left on [baseline1_complete] - still the right meaning (demographics = end of baseline)');

if ($dryRun) { echo "\n*** DRY RUN - nothing written ***\n"; exit(0); }

echo "\n*** DONE *** - now run: verify-baseline-split.php $pid\n";
