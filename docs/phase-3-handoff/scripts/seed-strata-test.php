<?php
/**
 * Seed a participant up to (not including) `tsr`, so the stratified randomization can be exercised
 * through the real `tsr` survey.
 *
 *   php seed-strata-test.php <pid> <record> high|low|incomplete
 *
 *   high        man, AUDIT 17  -> rand_strata should be set to 1 when tsr loads
 *   low         woman, AUDIT 3 -> rand_strata should be set to 0
 *   incomplete  man, audit10 left blank -> audit_score blank -> rand_strata blank
 *
 * Writes the screening, contact and AUDIT answers at Arm 1's Day 1 with REDCap::saveData(), which also
 * computes audit_score. Never writes rand_strata (the `@IF ... @SETVALUE` on tsr sets it when the page
 * loads; that is what is under test), tsr_complete (the survey submit sets it) or study_group (the
 * randomization target). Prints the tsr survey link. Development projects only.
 *
 * See docs/randomization/README.md (stratified test).
 */
$pid     = (int) ($argv[1] ?? 0);
$record  = (string) ($argv[2] ?? '');
$profile = (string) ($argv[3] ?? '');
if (!$pid || $record === '' || !in_array($profile, ['high', 'low', 'incomplete'], true)) {
    fwrite(STDERR, "usage: php seed-strata-test.php <pid> <record> high|low|incomplete\n"); exit(2);
}
$_GET['pid'] = $pid;
define('NOAUTH', true);
require_once '/var/www/html/redcap_connect.php';

$Proj = new Project($pid);
if ((int) $Proj->project['status'] !== 0) { fwrite(STDERR, "refusing: project $pid is not in Development\n"); exit(1); }

$event = null;
foreach ($Proj->eventInfo as $id => $e) if ((int) $e['arm_num'] === 1) { $event = (int) $id; break; }
$eventName = REDCap::getEventNames(true, false, $event);

$now = date('Y-m-d H:i');
$row = [
    $Proj->table_pk => $record, 'redcap_event_name' => $eventName,
    's_room' => "STRATA-$record", 's_cc' => 'strata test', 'sc_age' => '40', 'english' => '1',
    'prison' => '0', 'goodcand' => '1', 's_interest' => '1', 'ts_user' => $now, 'ts_server' => $now,
    'phone' => '1', 'first_name' => 'Strata', 'dummy_email' => 'noreply@stanford.edu',
];
// audit4-audit8 are coded 0-4; audit9 and audit10 only 0, 2, 4 (standard AUDIT scoring).
$audit = fn($a48, $a910) => array_fill_keys(['audit4', 'audit5', 'audit6', 'audit7', 'audit8'], $a48)
                           + ['audit9' => $a910, 'audit10' => $a910];
if ($profile === 'high') {
    $row += ['s_sex' => '1', 'days_dr' => '3', 'typ_drink' => '3', 'days_binge' => '2'] + $audit('1', '2');   // 8 + 5 + 4 = 17
} elseif ($profile === 'low') {
    $row += ['s_sex' => '2', 'preg' => '0', 'days_dr' => '1', 'typ_drink' => '1', 'days_binge' => '1'] + $audit('0', '0');  // 3
} else {
    $a = $audit('1', '2'); unset($a['audit10']);
    $row += ['s_sex' => '1', 'days_dr' => '3', 'typ_drink' => '3', 'days_binge' => '2'] + $a;
}

$res = REDCap::saveData(['project_id' => $pid, 'dataFormat' => 'json', 'overwriteBehavior' => 'normal', 'data' => json_encode([$row])]);
if (!empty($res['errors'])) { fwrite(STDERR, 'saveData errors: ' . json_encode($res['errors']) . "\n"); exit(1); }

$got = REDCap::getData(['project_id' => $pid, 'records' => [$record], 'events' => [$event],
    'fields' => ['audit_c_score', 'audit_score', 'rand_strata', 'study_group'], 'return_format' => 'array'])[$record][$event] ?? [];
printf("pid %d record %s (%s): audit_c_score=%s audit_score=%s rand_strata='%s' study_group='%s'\n", $pid, $record,
    $profile, $got['audit_c_score'] ?? '', $got['audit_score'] ?? '', $got['rand_strata'] ?? '', $got['study_group'] ?? '');
echo "tsr survey link: " . REDCap::getSurveyLink($record, 'tsr', $event) . "\n";
