<?php
/**
 * Independent verifier for the MICA Option B auth configuration.
 *
 * Reads the database and asserts the intended end state. It deliberately does NOT
 * reuse apply-auth-config.php's logic, so it is a real check rather than an echo.
 *
 *   docker exec <web-container> php /var/www/html/temp/mica-auth/verify-auth-config.php [pid] [credential_field] [credential_event_id]
 *
 * Exit code 0 = all checks passed, 1 = at least one failure.
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

$HOSTS = ['mica_ed_session', 'mica_booster_session'];
$fails = 0;
function check($label, $actual, $expected) {
    global $fails;
    $ok = (string)$actual === (string)$expected;
    if (!$ok) $fails++;
    printf("  [%s] %-52s got: %-22s want: %s\n", $ok ? 'ok' : 'FAIL', $label,
        $actual === '' ? "''" : $actual, $expected === '' ? "''" : $expected);
}

/** Event ids in THIS project that $form is designated to. redcap_events_forms has no
 *  project_id, so scope it through events_metadata -> events_arms. */
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

echo "Verifying MICA Option B auth config on project $pid\n";

// Cache bypass: this process may have instantiated Project already, and the config being
// verified is written with raw SQL that REDCap's own caches know nothing about.
$Proj = new Project($pid, true);

// ---------------------------------------------------------------- Survey Login
echo "\n[1] SURVEY LOGIN\n";
$p = db_fetch_assoc(db_query("select survey_auth_enabled, survey_auth_apply_all_surveys,
    survey_auth_field1, survey_auth_event_id1, survey_auth_field2, survey_auth_event_id2,
    survey_auth_field3, survey_auth_event_id3, survey_auth_min_fields,
    survey_auth_fail_limit, survey_auth_fail_window from redcap_projects where project_id=$pid"));
check('survey_auth_enabled',                 $p['survey_auth_enabled'], '1');
check('scoped, not project-wide',            $p['survey_auth_apply_all_surveys'], '0');
check('credential field',                    $p['survey_auth_field1'], $credentialField);
check('credential event',                    $p['survey_auth_event_id1'], $credentialEvent);
check('min_fields',                          $p['survey_auth_min_fields'], '1');
check('fail_limit',                          $p['survey_auth_fail_limit'], '5');
check('fail_window',                         $p['survey_auth_fail_window'], '30');

// The critical one: a second slot reintroduces the blank-credential bypass.
echo "\n[2] EXACTLY ONE CREDENTIAL SLOT  (a 2nd slot is a login bypass - see 10 section 3)\n";
foreach (['survey_auth_field2', 'survey_auth_event_id2', 'survey_auth_field3', 'survey_auth_event_id3'] as $k) {
    $isNull = ($p[$k] === null || $p[$k] === '');
    if (!$isNull) $fails++;
    printf("  [%s] %-52s got: %s\n", $isNull ? 'ok' : 'FAIL', "$k must be empty", $p[$k] ?? 'NULL');
}

// ---------------------------------------------------------------- host surveys
echo "\n[3] HOST SURVEYS\n";
foreach ($HOSTS as $form) {
    $s = db_fetch_assoc(db_query("select survey_id, survey_enabled, save_and_return,
        edit_completed_response, survey_auth_enabled_single, repeat_survey_enabled,
        survey_time_limit_days, survey_time_limit_hours, survey_time_limit_minutes
        from redcap_surveys where project_id=$pid and form_name='".db_escape($form)."'"));
    if (!$s) { $fails++; printf("  [FAIL] %-52s no survey row\n", $form); continue; }
    check("$form: survey_enabled",            $s['survey_enabled'], '1');
    check("$form: scoped-login flag",         $s['survey_auth_enabled_single'], '1');
    check("$form: save_and_return",           $s['save_and_return'], '1');
    // Without this, one page submit permanently kills the participant's own session link.
    check("$form: edit_completed_response (link stays usable)", $s['edit_completed_response'], '1');
    // "Repeat Survey". Inert unless the instrument repeats at the event - see section [4].
    check("$form: repeat_survey_enabled",     $s['repeat_survey_enabled'], '1');
    $hasLimit = ((int)$s['survey_time_limit_days'] + (int)$s['survey_time_limit_hours']
               + (int)$s['survey_time_limit_minutes']) > 0;
    check("$form: link time limit set (gate-2 prerequisite)", $hasLimit ? 'yes' : 'no', 'yes');
    // mount point: the instrument needs >=1 non-complete field to be a survey at all
    $mount = db_result(db_query("select count(1) from redcap_metadata
        where project_id=$pid and form_name='".db_escape($form)."'
        and field_name not like '%\_complete'"), 0);
    check("$form: mount-point field present", $mount >= 1 ? 'yes' : 'no', 'yes');
}

// -------------------------------------------------------- repeating instruments
// Each chat session needs its own survey link. REDCap mints one participant_id/hash per
// record+event+instance, but REDCap::getSurveyLink() clamps $instance to 1 unless
// isRepeatingForm($event_id,$form) is true (Classes/REDCap.php:1747-1750) - so without
// this, every "instance" of a host survey resolves to the same hash, and
// repeat_survey_enabled is ignored too (Surveys/index.php:1030).
// Both layers are asserted: the stored row AND what REDCap actually decides.
echo "\n[4] HOST INSTRUMENTS REPEAT AT EVERY EVENT THEY ARE DESIGNATED TO\n";
check('project has repeating instruments/events enabled', $Proj->project['repeatforms'], '1');
foreach ($HOSTS as $form) {
    $eventIds = mica_designated_event_ids($pid, $form);
    check("$form: designated to >=1 event", count($eventIds) >= 1 ? 'yes' : 'no', 'yes');
    foreach ($eventIds as $eid) {
        $row = db_result(db_query("select count(1) from redcap_events_repeat
            where event_id=$eid and form_name='".db_escape($form)."'"), 0);
        check("$form @ event $eid: redcap_events_repeat row", $row >= 1 ? 'yes' : 'no', 'yes');
        // The call REDCap itself gates on.
        check("$form @ event $eid: Proj->isRepeatingForm()",
            $Proj->isRepeatingForm($eid, $form) ? 'yes' : 'no', 'yes');
    }
}

echo "\n[5] NO OTHER SURVEY IS GATED\n";
$gated = db_result(db_query("select count(1) from redcap_surveys where project_id=$pid and survey_auth_enabled_single=1"), 0);
check('surveys carrying the scoped-login flag', $gated, count($HOSTS));
$q = db_query("select form_name from redcap_surveys where project_id=$pid and survey_auth_enabled_single=1
    and form_name not in ('" . implode("','", array_map('db_escape', $HOSTS)) . "')");
$stray = [];
while ($r = db_fetch_assoc($q)) $stray[] = $r['form_name'];
check('non-MICA surveys gated', empty($stray) ? 'none' : implode(',', $stray), 'none');

echo "\n[6] CREDENTIAL IS RESOLVABLE\n";
check('credential field exists',  isset($Proj->metadata[$credentialField]) ? 'yes' : 'no', 'yes');
check('credential event exists',  isset($Proj->eventInfo[$credentialEvent]) ? 'yes' : 'no', 'yes');
if (isset($Proj->metadata[$credentialField])) {
    $credForm = $Proj->metadata[$credentialField]['form_name'];
    $designated = db_result(db_query("select count(1) from redcap_events_forms
        where event_id=$credentialEvent and form_name='".db_escape($credForm)."'"), 0);
    check("credential form '$credForm' designated to event $credentialEvent",
        $designated >= 1 ? 'yes' : 'no', 'yes');
}
// Any record whose credential value is blank can be entered with a blank submission,
// so the module must refuse to issue a link in that case (10 section 3). Report exposure.
$blank = 0; $total = 0;
$q = db_query("select distinct record from redcap_data where project_id=$pid");
while ($r = db_fetch_assoc($q)) {
    $total++;
    $v = db_result(db_query("select value from redcap_data where project_id=$pid
        and record='".db_escape($r['record'])."' and event_id=$credentialEvent
        and field_name='".db_escape($credentialField)."'"), 0);
    if ($v === null || trim((string)$v) === '') $blank++;
}
printf("  [%s] %-52s %d of %d record(s)\n", $blank === 0 ? 'ok' : 'WARN',
    'records with a BLANK credential (enterable with blank POST)', $blank, $total);

echo "\n[7] STRUCTURAL INTEGRITY (must be unchanged by the auth work)\n";
$dup = db_result(db_query("select count(1) from (select field_order from redcap_metadata
    where project_id=$pid group by field_order having count(1)>1) x"), 0);
check('duplicate field_order values', $dup, '0');
$gaps = db_result(db_query("select count(1) from redcap_metadata m1 where project_id=$pid
    and m1.field_order > 1 and not exists (select 1 from redcap_metadata m2
    where m2.project_id=$pid and m2.field_order = m1.field_order - 1)"), 0);
check('field_order gaps', $gaps, '0');
$desig = db_result(db_query("select count(1) from redcap_events_forms ef
    join redcap_events_metadata em on em.event_id=ef.event_id
    join redcap_events_arms ea on ea.arm_id=em.arm_id where ea.project_id=$pid"), 0);
printf("  [info] %-52s %s\n", 'event/form designations', $desig);
foreach ($HOSTS as $form) {
    $q = db_query("select ea.arm_num, em.descrip from redcap_events_forms ef
        join redcap_events_metadata em on em.event_id=ef.event_id
        join redcap_events_arms ea on ea.arm_id=em.arm_id
        where ea.project_id=$pid and ef.form_name='".db_escape($form)."' order by ea.arm_num");
    $d = [];
    while ($r = db_fetch_assoc($q)) $d[] = "arm{$r['arm_num']}:{$r['descrip']}";
    printf("  [info] %-52s %s\n", "$form designated to", implode(', ', $d));
}

echo "\n" . ($fails === 0 ? "*** ALL CHECKS PASSED ***" : "*** $fails CHECK(S) FAILED ***") . "\n";
exit($fails === 0 ? 0 : 1);
