<?php
/**
 * Applies the Option B authentication configuration to a MICA REDCap project.
 *
 *   docs/phase-3-handoff/08-auth-discovery.md   - the design
 *   docs/phase-3-handoff/10-auth-implementation-pid257.md - what this did to PID 257, and why
 *
 * Idempotent: safe to re-run. Run verify-auth-config.php afterwards.
 *
 * Usage (from the host, against the dockerised REDCap):
 *   docker cp docs/phase-3-handoff/scripts/. <web-container>:/var/www/html/temp/mica-auth/
 *   docker exec <web-container> php /var/www/html/temp/mica-auth/apply-auth-config.php [pid] [credential_field] [credential_event_id]
 *
 * Defaults: pid=257, credential_field=last_name, credential_event_id=1004 (Day 1 (ED) arm 1).
 *
 * ############################################################################
 * # DO NOT ADD A SECOND CREDENTIAL SLOT.                                     #
 * # REDCap treats a blank submitted credential as matching a blank stored     #
 * # value (Surveys/index.php:1470-1481, Classes/Survey.php:2066-2075). Any    #
 * # second slot necessarily points at an event where the participant has no   #
 * # data, so posting an empty credential matches it and bypasses the login.   #
 * # Verified on PID 257. See 10-auth-implementation-pid257.md section 3.      #
 * ############################################################################
 */

$pid             = (int)   ($argv[1] ?? 257);
$credentialField = (string)($argv[2] ?? 'last_name');
$credentialEvent = (int)   ($argv[3] ?? 1004);

$_GET['pid'] = $pid;
define('NOAUTH', true);
require_once mica_find_redcap_connect();

/** Locate redcap_connect.php so the script runs from the repo path or anywhere it is copied. */
function mica_find_redcap_connect(): string {
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) return "$env/redcap_connect.php";
    foreach (['/var/www/html'] as $guess) if (is_file("$guess/redcap_connect.php")) return "$guess/redcap_connect.php";
    for ($d = __DIR__, $i = 0; $i < 8; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) return "$d/redcap_connect.php";
        if ($d === '/') break;
    }
    fwrite(STDERR, "Could not locate redcap_connect.php. Set REDCAP_ROOT=/path/to/redcap web root.\n");
    exit(1);
}

function out($k, $v) { printf("  %-46s %s\n", $k, $v); }
function step($s)     { echo "\n=== $s ===\n"; }
function fail($m)     { echo "\nABORTED: $m\n"; exit(1); }

/**
 * Event ids in THIS project that $form is designated to.
 *
 * redcap_events_forms has no project_id column, so it must be scoped through
 * events_metadata -> events_arms or it will pick up identically named forms in
 * other projects. Deriving the ids instead of hardcoding them keeps the script
 * correct against a rebuilt project, where the event ids differ.
 */
function mica_designated_event_ids(int $pid, string $form): array {
    $ids = [];
    $q = db_query("select ef.event_id from redcap_events_forms ef
        join redcap_events_metadata em on em.event_id = ef.event_id
        join redcap_events_arms ea on ea.arm_id = em.arm_id
        where ea.project_id = $pid and ef.form_name = '" . db_escape($form) . "'
        order by ef.event_id");
    while ($r = db_fetch_assoc($q)) $ids[] = (int) $r['event_id'];
    return $ids;
}

/** Host instruments: one per MICA session window. Both must already be designated
 *  to their event in the MICA arms (arms 2 & 3 in the R01 structure). */
$hosts = [
    'mica_ed_session' => [
        'field' => 'desc_mica_ed_chat',
        'title' => 'MICA ED session',
        'limit' => ['days' => null, 'hours' => 24, 'minutes' => null],
    ],
    'mica_booster_session' => [
        'field' => 'desc_mica_booster_chat',
        'title' => 'MICA booster session',
        // Placeholder: reconcile with the booster-window-* settings in Stage 2.
        'limit' => ['days' => 14, 'hours' => null, 'minutes' => null],
    ],
];

echo "Applying MICA Option B auth config to project $pid\n";
out('credential', "$credentialField @ event $credentialEvent");

// ---- preflight -------------------------------------------------------------
step('Preflight');
$Proj = new Project($pid, true);
if ((int)$Proj->project['status'] !== 0) {
    out('project status', $Proj->project['status'] . ' (NOT development)');
    fail("project $pid is not in Development; dictionary changes must be drafted instead");
}
out('project status', 'Development');
if (!isset($Proj->metadata[$credentialField])) {
    fail("credential field '$credentialField' does not exist in project $pid");
}
out("credential field '$credentialField'", 'exists on form ' . $Proj->metadata[$credentialField]['form_name']);
if (!isset($Proj->eventInfo[$credentialEvent])) fail("event $credentialEvent does not exist in project $pid");
// Project::$eventInfo keys the event label as 'name' (the DB column is descrip).
out("credential event $credentialEvent", $Proj->eventInfo[$credentialEvent]['name']
    . ' (arm ' . $Proj->eventInfo[$credentialEvent]['arm_num'] . ')');
foreach (array_keys($hosts) as $form) {
    if (!isset($Proj->forms[$form])) fail("host instrument '$form' does not exist in project $pid");
}
out('host instruments', implode(', ', array_keys($hosts)));

// ---- 1. mount-point fields -------------------------------------------------
step('1. Mount-point field on each host instrument');
foreach ($hosts as $form => $cfg) {
    if (db_result(db_query("select count(1) from redcap_metadata
            where project_id=$pid and field_name='".db_escape($cfg['field'])."'"), 0)) {
        out($cfg['field'], 'already present - skipped');
        continue;
    }
    $row = db_fetch_assoc(db_query("select field_order, form_menu_description from redcap_metadata
        where project_id=$pid and field_name='".db_escape($form)."_complete'"));
    if (!$row) fail("could not find {$form}_complete to anchor the insert");
    $order = (int)$row['field_order'];
    $menu  = $row['form_menu_description'];

    // make room, then insert where _complete used to sit
    db_query("update redcap_metadata set field_order = field_order + 1
              where project_id=$pid and field_order >= $order order by field_order desc");
    $ok = db_query("insert into redcap_metadata
        (project_id, field_name, form_name, field_order, element_type, element_label, form_menu_description)
        values ($pid, '".db_escape($cfg['field'])."', '".db_escape($form)."', $order,
                'descriptive', 'Loading your MICA session&hellip;',
                ".($menu === null ? 'null' : "'".db_escape($menu)."'").")");
    if (!$ok) fail('metadata insert failed: ' . db_error());
    // REDCap keeps form_menu_description on a form's FIRST field
    if ($menu !== null) {
        db_query("update redcap_metadata set form_menu_description = null
                  where project_id=$pid and field_name='".db_escape($form)."_complete'");
    }
    out($cfg['field'], "inserted at field_order $order on $form");
}

// ---- 2. repeating instruments ----------------------------------------------
step('2. Host instruments repeat at every event they are designated to');
// A participant can open more than one chat session in a window, and each one needs its
// own storage row AND its own survey link. Making the host form repeating at its event is
// what gives us both: REDCap mints one participant_id/hash per record+event+instance
// (Classes/Survey.php:1687 getFollowupSurveyParticipantIdHash), but only if the form
// actually repeats there - REDCap::getSurveyLink() silently clamps $instance to 1 when
// !$Proj->isRepeatingForm($event_id,$form) (Classes/REDCap.php:1747-1750), and
// repeat_survey_enabled (step 3) is gated on the same call at Surveys/index.php:1030.
if ((int) $Proj->project['repeatforms'] !== 1) {
    if (!db_query("update redcap_projects set repeatforms = 1 where project_id = $pid")) {
        fail('could not enable repeating instruments/events: ' . db_error());
    }
    out('repeatforms', '0 -> 1 (repeating instruments enabled for the project)');
} else {
    out('repeatforms', '1 (already enabled)');
}
foreach (array_keys($hosts) as $form) {
    $eventIds = mica_designated_event_ids($pid, $form);
    if (!$eventIds) fail("host instrument '$form' is not designated to any event in project $pid");
    foreach ($eventIds as $eid) {
        // UNIQUE(event_id, form_name) makes INSERT IGNORE the idempotent form here.
        // custom_repeat_form_label stays NULL so REDCap uses its default instance label.
        if (!db_query("insert ignore into redcap_events_repeat
                (event_id, form_name, custom_repeat_form_label)
                values ($eid, '" . db_escape($form) . "', null)")) {
            fail("could not make '$form' repeating at event $eid: " . db_error());
        }
        out("$form @ event $eid", (db_affected_rows() > 0 ? 'now repeating' : 'already repeating')
            . ' (' . ($Proj->eventInfo[$eid]['name'] ?? '?')
            . ', arm ' . ($Proj->eventInfo[$eid]['arm_num'] ?? '?') . ')');
    }
}

// ---- 3. enable host instruments as surveys ---------------------------------
step('3. Enable host instruments as surveys');
// `logo` and `confirmation_email_attachment` are UNIQUE-indexed edoc refs - never clone them.
$skip = ['survey_id', 'logo', 'confirmation_email_attachment'];
$cols = [];
$q = db_query('show columns from redcap_surveys');
while ($r = db_fetch_assoc($q)) if (!in_array($r['Field'], $skip, true)) $cols[] = $r['Field'];
$colList  = '`' . implode('`,`', $cols) . '`';
$template = db_result(db_query("select survey_id from redcap_surveys where project_id=$pid order by survey_id limit 1"), 0);
if (!$template) fail("project $pid has no existing survey to clone column defaults from");

foreach ($hosts as $form => $cfg) {
    $sid = db_result(db_query("select survey_id from redcap_surveys
        where project_id=$pid and form_name='".db_escape($form)."'"), 0);
    if (!$sid) {
        // (project_id, form_name) is UNIQUE, so form_name must be set in the INSERT itself.
        $selectList = implode(',', array_map(
            fn($c) => $c === 'form_name' ? "'".db_escape($form)."'" : "`$c`", $cols));
        if (!db_query("insert into redcap_surveys ($colList)
                       select $selectList from redcap_surveys where survey_id = $template")) {
            fail('survey insert failed: ' . db_error());
        }
        $sid = db_insert_id();
        if (!$sid) fail('survey insert produced no survey_id');
        out($form, "survey created (survey_id $sid)");
    } else {
        out($form, "survey exists (survey_id $sid)");
    }
    $l = $cfg['limit'];
    // save_and_return: Survey Login forces this on at runtime anyway - set it explicitly
    // so the behaviour is predictable rather than implicit.
    //
    // edit_completed_response MUST be 1. These host surveys have no real questions, so a
    // single page submit completes the response - and a completed response with
    // edit_completed_response = 0 makes the participant's link dead ("you have already
    // completed this survey"), with no way back into their own session. With it set to 1 the
    // link still works and Survey Login is still enforced on re-entry (the gate condition at
    // Surveys/index.php:1566-1573 requires save_and_return && edit_completed_response to
    // re-prompt on a completed response). Verified both ways.
    //
    // repeat_survey_enabled = 1 is "Repeat Survey". It is a no-op unless the instrument
    // repeats at the event (step 2) - Surveys/index.php:1030 ANDs it with isRepeatingForm().
    // It is also mutually exclusive with end_survey_redirect_url in REDCap's own UI
    // (Surveys/survey_info_table.php:1107), which is why that is nulled in the same statement.
    db_query("update redcap_surveys set
        title                     = '".db_escape($cfg['title'])."',
        instructions              = '',
        acknowledgement           = '',
        survey_enabled            = 1,
        survey_auth_enabled_single = 1,
        save_and_return           = 1,
        edit_completed_response   = 1,
        hide_title                = 1,
        repeat_survey_enabled     = 1,
        survey_time_limit_days    = ".($l['days']    === null ? 'null' : (int)$l['days'])   .",
        survey_time_limit_hours   = ".($l['hours']   === null ? 'null' : (int)$l['hours'])  .",
        survey_time_limit_minutes = ".($l['minutes'] === null ? 'null' : (int)$l['minutes']).",
        end_survey_redirect_url   = null
        where survey_id = $sid");
    out('  -> settings', 'enabled, scoped-login on, save&return on, title hidden, repeat survey on, link limit '
        . (trim(($l['days'] ? $l['days'].'d ' : '') . ($l['hours'] ? $l['hours'].'h' : '')) ?: 'none'));
}

// ---- 4. Survey Login -------------------------------------------------------
step('4. Survey Login (scoped, single credential slot)');
$msg = 'For your security, please confirm your identity to open your MICA session.';
db_query("update redcap_projects set
    survey_auth_enabled           = 1,
    survey_auth_apply_all_surveys = 0,
    survey_auth_field1            = '".db_escape($credentialField)."',
    survey_auth_event_id1         = $credentialEvent,
    survey_auth_field2            = null, survey_auth_event_id2 = null,
    survey_auth_field3            = null, survey_auth_event_id3 = null,
    survey_auth_min_fields        = 1,
    survey_auth_fail_limit        = 5,
    survey_auth_fail_window       = 30,
    survey_auth_custom_message    = '".db_escape($msg)."'
    where project_id = $pid");
out('survey_auth_enabled', '1');
out('survey_auth_apply_all_surveys', '0 (scoped to the MICA host surveys only)');
out('slot 1', "$credentialField @ event $credentialEvent");
out('slots 2 and 3', 'NULL - intentionally; see the header comment');
out('min_fields / lockout', '1 / 5 failures per 30-minute sliding window');

echo "\nApplied. Now run: php verify-auth-config.php $pid $credentialField $credentialEvent\n";
