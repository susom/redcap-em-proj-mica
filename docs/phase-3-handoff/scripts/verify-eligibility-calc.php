<?php
/**
 * Exhaustive server-side check of `calc_screen_result` on a project built from the PI's XML.
 *
 *   php verify-eligibility-calc.php <pid>
 *
 * Saves one record per combination of every screening input through REDCap::saveData() - the path
 * that makes REDCap compute and STORE the calc, so this tests REDCap's own evaluator (blank handling
 * in `<`, `=`, `""` included), not a re-implementation of it. Each stored value is compared with:
 *
 *   expected()  the eligibility rules, written from the protocol criteria, not from the formula;
 *   was271()    what the equation that worked on PID 271 produces, so every behaviour change the new
 *               equation makes is listed rather than discovered later.
 *
 * Records are named TT-<run>-<n>, so a re-run never touches an earlier run's records. Scratch
 * projects only: it writes ~1,000 records.
 *
 * See docs/screening/ELIGIBILITY_CALC_PI_XML_2026-09-25.md.
 */

$pid = (int) ($argv[1] ?? 0);
if ($pid <= 0) { fwrite(STDERR, "usage: php verify-eligibility-calc.php <pid>\n"); exit(2); }

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

$Proj = new Project($pid, true);
if ((int) $Proj->project['status'] !== 0) { fwrite(STDERR, "refusing: project $pid is not in Development\n"); exit(1); }
$event = $Proj->firstEventId;
$eventName = REDCap::getEventNames(true, false)[$event] ?? null;
echo "pid $pid, event $event ($eventName)\n";
echo "equation under test:\n" . $Proj->metadata['calc_screen_result']['element_enum'] . "\n\n";

/** The protocol criteria. '' = not enough answered to decide yet. */
function expected(array $r): string {
    if ($r['sc_age'] === '' || $r['s_sex'] === '' || $r['prison'] === '') return '';
    if ($r['days_dr'] === '') return '';
    if ($r['days_dr'] === '0') return '0';                        // never drinks: AUDIT-C 0
    if ($r['typ_drink'] === '' || $r['days_binge'] === '') return '';
    $score = (int) $r['days_dr'] + (int) $r['typ_drink'] + (int) $r['days_binge'];
    $positive = ($r['s_sex'] === '1' && $score >= 4) || ($r['s_sex'] === '2' && $score >= 3);
    if (!$positive) return '0';
    if ($r['phone'] === '' || ($r['s_sex'] === '2' && $r['preg'] === '')) return '';
    $age = (int) $r['sc_age'];
    $eligible = $age >= 18 && $age <= 65 && $r['phone'] === '1' && $r['prison'] === '0'
             && ($r['s_sex'] === '1' || $r['preg'] === '0');
    return $eligible ? '1' : '2';
}

/** The PID 271 equation, military answered "No" (the field is gone from the PI's project). */
function was271(array $r): string {
    $score = ($r['days_dr'] !== '' && $r['typ_drink'] !== '' && $r['days_binge'] !== '')
        ? (int) $r['days_dr'] + (int) $r['typ_drink'] + (int) $r['days_binge'] : null;
    if ($r['sc_age'] === '' || $r['s_sex'] === '' || $r['phone'] === '' || $r['prison'] === ''
        || $score === null || ($r['s_sex'] === '2' && $r['preg'] === '')) return '';
    $age = (int) $r['sc_age'];
    $cPos = ($r['s_sex'] === '2' && $score >= 3) || ($r['s_sex'] === '1' && $score >= 4);
    if ($age >= 18 && $age <= 65 && $r['phone'] === '1' && $r['prison'] === '0'
        && ($r['s_sex'] === '1' || $r['preg'] === '0') && $cPos) return '1';
    // 271's hazard branch tested s_sex = "0", a code that does not exist.
    if (($r['s_sex'] === '0' && $score >= 3) || ($r['s_sex'] === '1' && $score >= 4)) return '2';
    return '0';
}

// ---- every combination ----------------------------------------------------------------------
$audits = [                      // days_dr, typ_drink, days_binge
    'not started' => ['', '', ''],
    'never drinks' => ['0', '', ''],
    'partial'     => ['2', '', ''],
    'score 2'     => ['1', '0', '1'],
    'score 3'     => ['1', '1', '1'],
    'score 4'     => ['1', '1', '2'],
    'score 8'     => ['3', '3', '2'],
];
$combos = [];
foreach (['1', '2'] as $sex)
foreach (['17', '18', '65', '66'] as $age)
foreach (['0', '1'] as $prison)
foreach ($audits as $label => [$dd, $td, $db])
foreach (['', '0', '1'] as $phone)
foreach (['', '0', '1'] as $preg)
    $combos[] = compact('sex', 'age', 'prison', 'label', 'dd', 'td', 'db', 'phone', 'preg');
// The blank guard on the always-asked pre_screen answers, each on an otherwise eligible record.
foreach (['sc_age', 's_sex', 'prison'] as $blank)
    $combos[] = ['sex' => $blank === 's_sex' ? '' : '1', 'age' => $blank === 'sc_age' ? '' : '40',
                 'prison' => $blank === 'prison' ? '' : '0', 'label' => "blank $blank",
                 'dd' => '3', 'td' => '3', 'db' => '2', 'phone' => '1', 'preg' => ''];

$run = base_convert((string) time(), 10, 36);
$rows = [];
foreach ($combos as $i => $c) {
    $r = ['sc_age' => $c['age'], 's_sex' => $c['sex'], 'prison' => $c['prison'],
          'days_dr' => $c['dd'], 'typ_drink' => $c['td'], 'days_binge' => $c['db'],
          'phone' => $c['phone'], 'preg' => $c['preg']];
    $rows["TT-$run-$i"] = ['in' => $r, 'label' => $c['label']];
}

// ---- save through REDCap, ONE record per call -------------------------------------------------
// Not batched: a 100-record saveData() left `audit_c_score` - a calc that `calc_screen_result`
// depends on - uncomputed for 73 of 1,011 records (measured 2026-09-25), and those surfaced as
// "mismatches" that were REDCap's bulk auto-calc, not the equation. One record per call is what a
// survey submit does, and computes both calcs every time.
$names = array_keys($rows);
foreach ($names as $rec) {
    $row = [$Proj->table_pk => $rec, 'redcap_event_name' => $eventName, 's_room' => $rec];
    foreach ($rows[$rec]['in'] as $f => $v) if ($v !== '') $row[$f] = $v;
    $res = REDCap::saveData(['project_id' => $pid, 'dataFormat' => 'json', 'data' => json_encode([$row]),
                             'overwriteBehavior' => 'normal', 'returnFormat' => 'json']);
    if (!empty($res['errors'])) { fwrite(STDERR, "saveData errors: " . json_encode($res['errors']) . "\n"); exit(1); }
}

// ---- read back what REDCap stored --------------------------------------------------------------
$got = REDCap::getData(['project_id' => $pid, 'records' => $names, 'events' => [$event],
                        'fields' => [$Proj->table_pk, 'calc_screen_result', 'audit_c_score'], 'return_format' => 'array']);

$mismatch = 0; $changes = []; $uncomputed = 0;
foreach ($rows as $rec => $row) {
    $stored = (string) ($got[$rec][$event]['calc_screen_result'] ?? '');
    // Harness guard: complete AUDIT-C answers must have produced a score, or the row tests nothing.
    $in = $row['in'];
    if ($in['days_dr'] !== '' && $in['days_dr'] !== '0' && $in['typ_drink'] !== '' && $in['days_binge'] !== ''
        && (string) ($got[$rec][$event]['audit_c_score'] ?? '') === '') {
        $uncomputed++;
        continue;
    }
    $want = expected($row['in']);
    $old = was271($row['in']);
    if ($stored !== $want) {
        $mismatch++;
        if ($mismatch <= 25) printf("MISMATCH %-16s %-12s %s  stored '%s' expected '%s'\n", $rec, $row['label'],
            json_encode($row['in']), $stored, $want);
    }
    if ($stored !== $old) {
        $in = $row['in'];
        $key = sprintf("%-14s sex %s  271 '%s' -> now '%s'", $row['label'], $in['s_sex'], $old, $stored);
        $changes[$key] = ($changes[$key] ?? 0) + 1;
    }
}

printf("\n%d combinations saved through REDCap::saveData\n", count($rows));
if ($uncomputed) printf("HARNESS: %d record(s) with complete AUDIT-C answers got no audit_c_score - not evaluated\n", $uncomputed);
printf("stored value vs protocol criteria: %d mismatch(es)\n", $mismatch);
echo "\nwhere the new equation differs from PID 271's (military = No), by input class:\n";
ksort($changes);
foreach ($changes as $k => $n) printf("  %4d  %s\n", $n, $k);
exit($mismatch ? 1 : 0);
