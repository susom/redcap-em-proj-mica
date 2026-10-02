<?php
/**
 * C4 + C5 of doc 33: make the booster ladder (alerts 05-14) able to fire, and add the Month 3/6/12
 * follow-up invitations that do not exist yet. Works on an Alerts CSV exported from the target project.
 *
 *   php transform-alerts-booster-followup.php <in.csv> [out.csv] [--send-hour=10] [--activate-new]
 *
 * INTENDED USE (same round trip as transform-alerts-csv.php):
 *   1. Alerts & Notifications -> Upload/Download -> Download alerts.  KEEP THAT FILE: it is the rollback.
 *   2. php transform-alerts-booster-followup.php alerts.csv alerts-FIXED.csv
 *   3. Read the report, then Alerts & Notifications -> Upload alerts, SAME project.
 *   4. Alerts & Notifications -> Re-evaluate Alerts, so records randomized before the change are scheduled.
 *
 * WHY C4 IS NEEDED. REDCap evaluates a logic-only alert only at the event being saved
 * (Alerts.php:141-352). Alerts 05-14 required [event-name]='month_3_arm_N', and nothing is saved at a
 * Month-3 event before the participant is invited, so they never queued (doc 33 C4, verified on PID 280).
 * The fix evaluates them at the arm's Day-1 event, which MICA saves at randomization, and reaches Month 3
 * through event prefixes:
 *   - condition  [event-name]='month_3_arm_N'        -> [event-name]='day_1_ed_arm_N'
 *                [mica_booster_session_complete]      -> [month_3_arm_N][mica_booster_session_complete]
 *   - message    [survey-link:..] [survey-url:..] [form-link:..] get the [month_3_arm_N] prefix
 *   - schedule   lag hours 0 -> --send-hour (default 10), so it is not sent at midnight
 * Each alert keeps its own alert_id, so RECORD_EVENT scoping at the Day-1 event does not collide with
 * alerts 02-04, which also fire there.
 *
 * H3. Every other time-lag alert anchored on the date-only randomization_date with a 0 h lag (02-04)
 * also moves to --send-hour, so no alert sends at midnight. Immediate alerts are not touched. Changing
 * the hour does not move rows already queued (Alerts.php:3943 only reschedules when the send TYPE
 * changes); Re-evaluate Alerts removes those whose logic is now false.
 *
 * WHAT C5 ADDS. 18 new alerts: Month 3/6/12 x arms 1/2/3 x email/SMS, at randomization_date +
 * 91/182/365 days, --send-hour o'clock. They link to `auditc`, the first follow-up instrument; the rest
 * of the battery auto-continues from it. Each is pinned to its arm with [day_1_ed_arm_1][study_group]
 * (everyone is enrolled at arm 1's Day 1). Channel rule and suppression are copied from alerts 02/03:
 * email only when email alone is ticked, otherwise SMS; withdrawn never; SMS never after STOP. Each
 * cancels itself when that follow-up's `auditc` is complete ("ensure logic still true"). New rows have a
 * blank alert-unique-id, so the upload CREATES them; they arrive deactivated unless --activate-new.
 *
 * Never upload one project's export to another: alert-unique-id A-<n> means "update alert n" in the
 * project you upload to. Idempotent: re-running on an already-fixed file changes nothing.
 */

$args = array_slice($argv, 1);
$in = null; $out = null; $sendHour = '10'; $activateNew = false;
foreach ($args as $a) {
    if (preg_match('/^--send-hour=(\d{1,2})$/', $a, $m)) $sendHour = $m[1];
    elseif ($a === '--activate-new')                   $activateNew = true;
    elseif ($in === null)                              $in = $a;
    elseif ($out === null)                             $out = $a;
}
if ($in === null || !is_file($in)) {
    fwrite(STDERR, "usage: php transform-alerts-booster-followup.php <in.csv> [out.csv] [--send-hour=10] [--activate-new]\n");
    exit(1);
}
$out = $out ?? preg_replace('/\.csv$/i', '', $in) . '-FIXED.csv';

// ---- read, preserving BOM and delimiter ---------------------------------------------------------
$raw = file_get_contents($in);
$bom = '';
if (substr($raw, 0, 3) === "\xEF\xBB\xBF") { $bom = "\xEF\xBB\xBF"; $raw = substr($raw, 3); }
$firstLine = strtok($raw, "\n");
$delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'),
           "\t" => substr_count($firstLine, "\t"), '|' => substr_count($firstLine, '|'),
           '^' => substr_count($firstLine, '^')];
arsort($delims);
$delim = array_key_first($delims);
if ($delims[$delim] === 0) { fwrite(STDERR, "cannot detect a delimiter in the header row\n"); exit(1); }
$fh = fopen('php://memory', 'r+'); fwrite($fh, $raw); rewind($fh);
$rows = [];
while (($r = fgetcsv($fh, 0, $delim)) !== false) { if ($r !== [null]) $rows[] = $r; }
fclose($fh);
$header = array_shift($rows);
$idx = array_flip($header);
foreach (['alert-unique-id', 'alert-title', 'alert-condition', 'alert-message', 'send-on-time-lag-hours'] as $need) {
    if (!isset($idx[$need])) { fwrite(STDERR, "missing column '$need' - is this an Alerts export?\n"); exit(1); }
}
$get = fn(array $row, string $c) => isset($idx[$c], $row[$idx[$c]]) ? (string) $row[$idx[$c]] : '';
$set = function (array &$row, string $c, string $v) use ($idx) { $row[$idx[$c]] = $v; };
printf("input   %s  (%d alerts, delimiter %s%s)\n\n", $in, count($rows), $delim === "\t" ? 'TAB' : "'$delim'", $bom ? ', BOM' : '');

// ---- C4: the booster ladder --------------------------------------------------------------------
$report = [];
foreach ($rows as $i => $row) {
    $cond = $get($row, 'alert-condition');
    if (!str_contains($cond, 'mica_booster_session_complete')) continue;
    if (!preg_match("/\[event-name\]\s*=\s*'(?:month_3|day_1_ed)_arm_(\d)'/", $cond, $m)) continue;
    $arm = $m[1]; $m3 = "month_3_arm_$arm"; $d1 = "day_1_ed_arm_$arm";
    $notes = [];

    $newCond = preg_replace("/\[event-name\]\s*=\s*'$m3'/", "[event-name]='$d1'", $cond);
    $newCond = preg_replace('/(?<!\])\[mica_booster_session_complete\]/', "[$m3][mica_booster_session_complete]", $newCond);
    // Only randomized participants: MICA stamps randomization_date before randomization (doc 33 §7),
    // and a record can reach an arm-2/3 event through that arm's public link (C2).
    if (!str_contains($newCond, "[day_1_ed_arm_1][study_group]")) {
        $newCond = preg_replace("/\[event-name\]='$d1'/", "[event-name]='$d1' and [day_1_ed_arm_1][study_group]='$arm'", $newCond, 1);
    }
    if ($newCond !== $cond) { $set($row, 'alert-condition', $newCond); $notes[] = 'condition'; }

    $msg = $get($row, 'alert-message');
    $newMsg = preg_replace('/(?<!\])\[(survey-link|survey-url|form-link):/', "[$m3][\$1:", $msg);
    if ($newMsg !== $msg) { $set($row, 'alert-message', $newMsg); $notes[] = 'message'; }

    if ($get($row, 'send-on-time-lag-hours') === '0' && strtoupper($get($row, 'send-on')) === 'TIME_LAG') {
        $set($row, 'send-on-time-lag-hours', $sendHour); $notes[] = "lag +{$sendHour}h";
    }
    if ($notes) {
        $rows[$i] = $row;
        $report[] = sprintf("  C4 %-8s %-55s %s", $get($row, 'alert-unique-id'), mb_substr($get($row, 'alert-title'), 0, 55), implode(', ', $notes));
    }
}

// ---- H3: every other alert that sends at midnight ------------------------------------------------
// A time lag of N days + 0 h on a date-only anchor sends at 00:00 (doc 33 H3: prod queued alert 02 for
// 12:00am). Only rows anchored on randomization_date (date_ymd) are touched, so a datetime anchor is
// never shifted. Immediate ("send now") alerts are left alone: the passcode text must go at once.
foreach ($rows as $i => $row) {
    if (strtoupper($get($row, 'send-on')) !== 'TIME_LAG') continue;
    if (!preg_match('/\[randomization_date\]$/', $get($row, 'send-on-field'))) continue;
    if ($get($row, 'send-on-time-lag-hours') !== '0' || !in_array($get($row, 'send-on-time-lag-minutes'), ['0', ''], true)) continue;
    $set($row, 'send-on-time-lag-hours', $sendHour);
    $rows[$i] = $row;
    $report[] = sprintf("  H3 %-8s %-55s lag +%sh (was midnight)", $get($row, 'alert-unique-id'), mb_substr($get($row, 'alert-title'), 0, 55), $sendHour);
}

// ---- C5: the follow-up invitations --------------------------------------------------------------
$template = ['EMAIL' => null, 'SMS' => null];
foreach ($rows as $row) {   // alerts 05/06: same project's From, display name and flags
    $t = strtoupper($get($row, 'alert-type'));
    if (array_key_exists($t, $template) && $template[$t] === null && str_contains($get($row, 'alert-condition'), 'mica_booster_session_complete')) $template[$t] = $row;
}
if (!$template['EMAIL'] || !$template['SMS']) { fwrite(STDERR, "cannot find the booster email/SMS alerts to copy settings from\n"); exit(1); }

$titles = array_map(fn($r) => $get($r, 'alert-title'), $rows);
$next = 23;
$E1 = '[day_1_ed_arm_1]';
$channel = [
    'EMAIL' => "{$E1}[email]<>'' and ({$E1}[choice_fup_delivery(1)]='1' and {$E1}[choice_fup_delivery(2)]<>'1') and {$E1}[study_withdrawn(1)]<>'1'",
    'SMS'   => "{$E1}[phonen]<>'' and ({$E1}[choice_fup_delivery(2)]='1' or ({$E1}[choice_fup_delivery(1)]<>'1' and {$E1}[choice_fup_delivery(2)]<>'1')) and {$E1}[study_withdrawn(1)]<>'1' and {$E1}[sms_stop(1)]<>'1'",
];
foreach ([3 => 91, 6 => 182, 12 => 365] as $month => $days) {
    foreach ([1, 2, 3] as $arm) {
        foreach (['EMAIL', 'SMS'] as $type) {
            $title = sprintf('%02d Month %d follow-up invitation (%s, arm %d)', $next++, $month, strtolower($type), $arm);
            $base = preg_replace('/^\d+ /', '', $title);
            if (array_filter($titles, fn($t) => preg_replace('/^\d+ /', '', $t) === $base)) continue;   // already added
            $fu = "month_{$month}_arm_$arm";
            $row = $template[$type];
            $set($row, 'alert-unique-id', '');
            $set($row, 'alert-title', $title);
            // study_group pins the arm: every participant is enrolled at arm 1's Day 1, so without it
            // the arm-1 rows would also fire for arm-2/3 participants and send them arm 1's survey.
            $set($row, 'alert-condition', "[event-name]='day_1_ed_arm_$arm' and {$E1}[study_group]='$arm' and [$fu][auditc_complete]<>'2' and {$E1}[randomization_date]<>'' and " . $channel[$type]);
            $set($row, 'send-on-time-lag-days', (string) $days);
            $set($row, 'send-on-time-lag-hours', $sendHour);
            $set($row, 'send-on-time-lag-minutes', '0');
            $set($row, 'send-on-field', "{$E1}[randomization_date]");
            $label = "$month-month";
            if ($type === 'EMAIL') {
                $set($row, 'email-subject', "Your MICA $label follow-up survey");
                $set($row, 'alert-message', "<p>Hi {$E1}[first_name],</p>\n<p>It's time for your $label MICA follow-up survey.</p>\n"
                    . "<p>[$fu][survey-link:auditc:Start your $label follow-up]</p>\n<p>— The MICA Study Team</p>");
            } else {
                $set($row, 'alert-message', "Hi {$E1}[first_name], it's the MICA Team. Your $label follow-up survey is ready: [$fu][survey-url:auditc] Reply STOP to opt out.");
            }
            if (isset($idx['alert-deactivated'])) $set($row, 'alert-deactivated', $activateNew ? 'N' : 'Y');
            $rows[] = $row;
            $report[] = sprintf("  C5 %-8s %-55s +%dd %sh%s", 'new', $title, $days, $sendHour, $activateNew ? '' : ', deactivated');
        }
    }
}

echo $report ? implode("\n", $report) . "\n" : "no changes needed - input is already fixed\n";
printf("\n%d change(s); output has %d alerts\n", count($report), count($rows));

// ---- validate with REDCap's own parser and the target project's events/fields, if inside REDCap -
$connect = null; $dir = __DIR__;
for ($i = 0; $i < 8; $i++) { $dir = dirname($dir); if (is_file("$dir/redcap_connect.php")) { $connect = "$dir/redcap_connect.php"; break; } }
$vpid = getenv('MICA_VALIDATE_PID');
if ($connect && $vpid) {
    define('NOAUTH', true); define('CRON', true);
    $_GET['pid'] = $vpid;
    require_once $connect;
    $Proj = new Project((int) $vpid);
    $events = array_flip($Proj->getUniqueEventNames());
    $bad = 0;
    foreach ($rows as $row) {
        $c = $get($row, 'alert-condition');
        $all = $c . ' ' . $get($row, 'alert-message') . ' ' . $get($row, 'send-on-field');
        if ($c !== '' && !LogicTester::isValid($c)) { $bad++; printf("  INVALID logic  %s\n", $get($row, 'alert-title')); }
        preg_match_all('/\[([a-z0-9_]+)\]\[([a-z0-9_]+)(?:\([^)]*\))?\]/', $all, $mm, PREG_SET_ORDER);
        foreach ($mm as $x) {
            if (isset($events[$x[1]]) && !isset($Proj->metadata[$x[2]]) && !preg_match('/_complete$/', $x[2])) { $bad++; printf("  unknown field  [%s][%s] in %s\n", $x[1], $x[2], $get($row, 'alert-title')); }
        }
        preg_match_all("/\[event-name\]\s*=\s*'([a-z0-9_]+)'|\[([a-z0-9_]+_arm_\d+)\]/", $all, $ee, PREG_SET_ORDER);
        foreach ($ee as $x) { $e = $x[1] ?: $x[2]; if (!isset($events[$e])) { $bad++; printf("  unknown event  %s in %s\n", $e, $get($row, 'alert-title')); } }
    }
    printf("validated against PID %s: %s\n", $vpid, $bad ? "$bad problem(s) - do not upload" : 'all conditions valid, all events and fields exist');
    if ($bad) exit(1);
} else {
    echo "(validation skipped: run inside the REDCap web container with MICA_VALIDATE_PID=<target pid>)\n";
}

// ---- write, same delimiter and BOM ---------------------------------------------------------------
$fh = fopen($out, 'w');
fwrite($fh, $bom);
fputcsv($fh, $header, $delim);
foreach ($rows as $row) fputcsv($fh, $row, $delim);
fclose($fh);
echo "wrote   $out\n";
