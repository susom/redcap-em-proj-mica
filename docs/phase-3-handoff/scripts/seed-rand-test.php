<?php
/**
 * Verification helper: seed an eligible, NOT-yet-randomized participant in a MICA project,
 * then print the consent survey link so the real trigger can be exercised through the UI.
 *
 *   php seed-rand-test.php <pid> [record]
 *
 * Writes ONLY the eligibility inputs (never study_group - that is the randomization target
 * field and Records::saveData() rejects writes to it at the target event).
 */

$pid    = (int)    ($argv[1] ?? 268);
$record = (string) ($argv[2] ?? 'RANDTEST01');

$_GET['pid'] = $pid;
define('NOAUTH', true);
require_once find_connect();

function find_connect(): string {
    foreach (['/var/www/html'] as $g) if (is_file("$g/redcap_connect.php")) return "$g/redcap_connect.php";
    for ($d = __DIR__, $i = 0; $i < 10; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) return "$d/redcap_connect.php";
        if ($d === '/') break;
    }
    fwrite(STDERR, "no redcap_connect.php\n"); exit(1);
}

$Proj = new Project($pid, true);

// Arm 1 Day 1 - the enrollment event and the randomization target event.
$targetEvent = null;
foreach ($Proj->eventInfo as $id => $e) {
    if ((int)($e['arm_num'] ?? 0) === 1) { $targetEvent = (int)$id; break; }
}
$eventNames = REDCap::getEventNames(true, false);
$evName = $eventNames[$targetEvent];

echo "pid=$pid record=$record arm1_day1_event=$targetEvent ($evName)\n";

// Eligible male: 18-65, has cell, not military, not prison, AUDIT-C >= 4.
// days_dr/typ_drink/days_bin feed the audit_c_score calc.
$row = [
    $Proj->table_pk      => $record,
    'redcap_event_name'  => $evName,
    'sc_age'             => '40',
    's_sex'              => '1',
    's_room'             => 'RANDTEST',
    's_cc'               => 'verification',
    'english'            => '1',
    'phone'              => '1',
    'military'           => '0',
    'prison'             => '0',
    'days_dr'            => '3',
    'typ_drink'          => '3',
    'days_binge'         => '2',
];

$res = REDCap::saveData([
    'project_id'        => $pid,
    'dataFormat'        => 'json',
    'data'              => json_encode([$row]),
    'overwriteBehavior' => 'overwrite',
    'returnFormat'      => 'json',
]);
echo "saveData errors: " . json_encode($res['errors'] ?? []) . "\n";
echo "saveData warnings: " . json_encode(array_slice((array)($res['warnings'] ?? []), 0, 3)) . "\n";

// Did the calc land? Randomization's trigger logic reads [calc_screen_result].
$check = REDCap::getData([
    'project_id' => $pid, 'records' => [$record],
    'fields' => ['audit_c_score', 'calc_screen_result', 'study_group'],
    'return_format' => 'array',
]);
foreach (($check[$record] ?? []) as $ev => $vals) {
    echo "  event $ev: audit_c_score=" . ($vals['audit_c_score'] ?? '-')
       . " calc_screen_result=" . ($vals['calc_screen_result'] ?? '-')
       . " study_group=" . ($vals['study_group'] ?? '-') . "\n";
}

echo "randomized already? " . (Randomization::wasRecordRandomized($record, null) ? 'YES' : 'no') . "\n";
echo "consent survey link:\n  "
   . (REDCap::getSurveyLink($record, 'consent', $targetEvent) ?: '(none)') . "\n";
