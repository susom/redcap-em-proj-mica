<?php
/**
 * Apply the event-prefix fix and the booster follow-up copy to an Alerts CSV exported from any
 * MICA project, without needing database access to that project.
 *
 *   php transform-alerts-csv.php <in.csv> [out.csv] [--enrol-event=day_1_ed_arm_1] [--no-copy-fix]
 *
 * INTENDED USE - promoting to prod (PID 35968) with UI access only:
 *   1. Alerts & Notifications -> Download alerts.   KEEP THAT FILE: it is your rollback.
 *   2. php transform-alerts-csv.php prod-alerts.csv prod-alerts-FIXED.csv
 *   3. Read the diff it prints, then Alerts & Notifications -> Upload, same project.
 *
 * WHY THE ROUND TRIP UPDATES RATHER THAN DUPLICATES. `Alerts.php:5766-5781`: a populated
 * `alert-unique-id` of the form `A-<alert_id>` that exists in the *current* project sets
 * `$_POST['index_modal_update']` and edits that alert in place. Blank creates a new one. This
 * script never touches that column.
 *
 * 🔴 NEVER FEED IT ONE PROJECT'S EXPORT AND UPLOAD TO ANOTHER. Every project's `alert_id`
 * auto-increment is independent, so `A-5759` means a different alert in a different project - the
 * upload would silently overwrite the wrong rows. Export from the target, transform, upload to the
 * same target. The script records the source ids in its report so a mismatch is visible.
 *
 * WHAT IT CHANGES, per docs/alerts/PID271_ALERT_TEST_MATRIX.md sections 6 and 7c:
 *   - enrolment-field references in `alert-condition`, `phone-number-to`, `email-to`,
 *     `send-on-field` and `alert-message` are re-pointed at the arm-1 Day-1 event
 *   - the +98-day booster rungs get their own wording and subject, so they stop being
 *     byte-identical to the +92 rungs
 *
 * WHAT IT DELIBERATELY LEAVES ALONE:
 *   - `study_group` - the one field MICA materialises into the assigned arm, so it legitimately
 *     resolves at the firing event. Re-pointing it blanks the CRC emails for any record whose
 *     study_group exists only at its own arm.
 *   - any alert whose `[event-name]` list includes the arm-1 Day-1 event: a bare reference can
 *     resolve correctly there. That is what keeps alerts 01/16/17/21 untouched.
 *   - `[event-name]` clauses, smart variables, and every column outside the five above.
 *
 * Idempotent: running it on an already-fixed file reports 0 changes.
 */

$args = array_slice($argv, 1);
$in = null; $out = null; $enrolEvent = 'day_1_ed_arm_1'; $copyFix = true;
foreach ($args as $a) {
    if (preg_match('/^--enrol-event=(.+)$/', $a, $m)) $enrolEvent = $m[1];
    elseif ($a === '--no-copy-fix')                   $copyFix = false;
    elseif ($in === null)                             $in = $a;
    elseif ($out === null)                            $out = $a;
}
if ($in === null || !is_file($in)) {
    fwrite(STDERR, "usage: php transform-alerts-csv.php <in.csv> [out.csv] [--enrol-event=NAME] [--no-copy-fix]\n");
    exit(1);
}
$out = $out ?? preg_replace('/\.csv$/i', '', $in) . '-FIXED.csv';

/** Collected once, at the enrolment event, before randomization. `study_group` is NOT here. */
const PREFIX_FIELDS = ['randomization_date', 'phonen', 'email', 'choice_fup_delivery',
                       'study_withdrawn', 'sms_stop', 'first_name', 'calcrnd', 'dummy_email'];

const ATTRS = ['alert-condition', 'phone-number-to', 'email-to', 'send-on-field', 'alert-message'];

// ---- read, preserving BOM and delimiter -------------------------------------------------------
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
if (count($rows) < 2) { fwrite(STDERR, "no data rows found\n"); exit(1); }

$header = array_shift($rows);
$idx = array_flip($header);
foreach (['alert-unique-id', 'alert-condition', 'alert-message'] as $need) {
    if (!isset($idx[$need])) { fwrite(STDERR, "missing required column '$need' - is this an Alerts export?\n"); exit(1); }
}
$col = fn(array $row, string $name) => $idx[$name] !== null && isset($row[$idx[$name]]) ? (string) $row[$idx[$name]] : '';

printf("input   %s\n", $in);
printf("        %d alerts, %d columns, delimiter %s%s\n", count($rows), count($header),
       $delim === "\t" ? 'TAB' : "'$delim'", $bom ? ', UTF-8 BOM' : '');
printf("enrolment event name: %s\n\n", $enrolEvent);

// ---- sanity: does this project even use the expected event naming? ----------------------------
$allText = '';
foreach ($rows as $r) $allText .= implode(' ', $r) . ' ';
preg_match_all("/\[event-name\]\s*=\s*'([a-z0-9_]+)'/i", $allText, $m);
$eventNames = array_values(array_unique($m[1] ?? []));
if (!in_array($enrolEvent, $eventNames, true) && !str_contains($allText, "[$enrolEvent]")) {
    fwrite(STDERR, "REFUSING: '$enrolEvent' appears nowhere in this file.\n");
    fwrite(STDERR, "  event names found: " . implode(', ', $eventNames) . "\n");
    fwrite(STDERR, "  pass --enrol-event=<the arm-1 Day-1 unique event name> for this project.\n");
    exit(1);
}
// Every other arm's Day-1 event, inferred from the naming scheme actually present in the file.
$otherDay1 = array_values(array_filter($eventNames,
    fn($n) => $n !== $enrolEvent && preg_match('/^' . preg_quote(preg_replace('/\d+$/', '', $enrolEvent), '/') . '\d+$/', $n)));
printf("event names in file:  %s\n", implode(', ', $eventNames));
printf("other Day-1 events:   %s\n\n", $otherDay1 ? implode(', ', $otherDay1) : '(none)');

/** Field-scoped, so `study_group` is never moved. Idempotent via the negative lookbehind. */
function prefix_fix(string $s, array $others, string $enrol, bool $bareToo): string {
    if ($s === '') return $s;
    foreach (PREFIX_FIELDS as $f) {
        $fp = preg_quote($f, '/');
        foreach ($others as $o) {
            $s = preg_replace('/\[' . preg_quote($o, '/') . '\]\[' . $fp . '(\([^\)]*\))?\]/', "[$enrol][$f\$1]", $s);
        }
        if ($bareToo) {
            $s = preg_replace('/(?<!\])\[' . $fp . '(\([^\)]*\))?\]/', "[$enrol][$f\$1]", $s);
        }
    }
    return $s;
}

// ---- the +98 follow-up copy --------------------------------------------------------------------
$NAME = "[$enrolEvent][first_name]";
$followSubject = 'Reminder: your MICA booster session is still open';
$followEmail = "<p>Hi $NAME,</p>"
    . '<p>Just a reminder — we wrote last week about your 3-month MICA booster session, and our '
    . "records show it isn't complete yet.</p>"
    . "<p>There's still time, and you can pick it up whenever works for you.</p>"
    . '<p>[survey-link:mica_booster_session:Open your booster session]</p>'
    . '<p>— The MICA Study Team</p>';
$followSms = "Hi $NAME, the MICA Team again. Your booster session is still open: "
    . '[survey-url:mica_booster_session] Reply STOP to opt out.';

// ---- transform -----------------------------------------------------------------------------------
$changedCells = 0; $changedAlerts = 0; $report = [];
foreach ($rows as $i => $row) {
    $id    = $col($row, 'alert-unique-id');
    $title = $col($row, 'alert-title');
    $cond  = $col($row, 'alert-condition');

    // Bare enrolment refs are only wrong when the alert can never fire at the enrolment event.
    preg_match_all("/\[event-name\]\s*=\s*'([a-z0-9_]+)'/i", $cond, $mm);
    $firesAt = $mm[1] ?? [];
    $bareToo = $firesAt && !in_array($enrolEvent, $firesAt, true);

    $before = $row; $notes = [];
    foreach (ATTRS as $attr) {
        if (!isset($idx[$attr])) continue;
        $old = (string) ($row[$idx[$attr]] ?? '');
        $new = prefix_fix($old, $otherDay1, $enrolEvent, $bareToo);
        if ($new !== $old) { $row[$idx[$attr]] = $new; $changedCells++; $notes[] = "prefix:$attr"; }
    }

    // +98 booster rung, identified by content rather than by row position
    $lag = $col($row, 'send-on-time-lag-days');
    $isBoosterFollowup = $copyFix && $lag === '98'
        && str_contains($col($row, 'alert-condition'), 'mica_booster_session_complete');
    if ($isBoosterFollowup) {
        $type = strtoupper($col($row, 'alert-type'));
        $newMsg = $type === 'SMS' ? $followSms : $followEmail;
        if ((string) $row[$idx['alert-message']] !== $newMsg) {
            $row[$idx['alert-message']] = $newMsg; $changedCells++; $notes[] = 'copy:alert-message';
        }
        if ($type !== 'SMS' && isset($idx['email-subject'])
            && (string) $row[$idx['email-subject']] !== $followSubject) {
            $row[$idx['email-subject']] = $followSubject; $changedCells++; $notes[] = 'copy:email-subject';
        }
    }

    if ($row !== $before) {
        $changedAlerts++;
        $report[] = sprintf("  %-8s %-52s %s", $id, mb_substr($title, 0, 50), implode(' ', $notes));
    }
    $rows[$i] = $row;
}

echo $changedAlerts ? "alerts changed:\n" . implode("\n", $report) . "\n\n" : "no changes needed - input is already fixed\n\n";
printf("%d cell(s) across %d alert(s)\n", $changedCells, $changedAlerts);

// ---- validate the conditions with REDCap's own parser, if we are running inside REDCap ---------
$connect = null;
$dir = __DIR__;
for ($i = 0; $i < 8; $i++) { $dir = dirname($dir); if (is_file("$dir/redcap_connect.php")) { $connect = "$dir/redcap_connect.php"; break; } }
if ($connect && !defined('NOAUTH')) {
    define('NOAUTH', true); define('CRON', true);
    $_GET['pid'] = getenv('MICA_VALIDATE_PID') ?: 271;
    require_once $connect;
    echo "\nLogicTester::isValid() on every rewritten condition\n";
    $bad = 0;
    foreach ($rows as $row) {
        $c = $col($row, 'alert-condition');
        if ($c === '') continue;
        if (!LogicTester::isValid($c)) { $bad++; printf("  FALSE  %s  %s\n", $col($row, 'alert-unique-id'), mb_substr($c, 0, 90)); }
    }
    printf("  %s\n", $bad ? "$bad INVALID - do not upload" : 'all conditions valid');
    if ($bad) exit(1);
} else {
    echo "\n(skipped LogicTester validation - no redcap_connect.php found)\n";
}

// ---- write ----------------------------------------------------------------------------------------
$fh = fopen($out, 'w');
fwrite($fh, $bom);
fputcsv($fh, $header, $delim);
foreach ($rows as $row) fputcsv($fh, $row, $delim);
fclose($fh);
printf("\nwritten: %s\n", $out);
echo "Upload this to the SAME project the input came from.\n";
