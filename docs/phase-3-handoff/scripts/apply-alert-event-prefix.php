<?php
/**
 * Point every enrolment-field reference in alerts 02-14 at arm 1's Day 1.
 *
 *   php apply-alert-event-prefix.php [pid]            # dry run - prints the diff, writes nothing
 *   php apply-alert-event-prefix.php [pid] --apply    # commits, after writing a rollback file
 *
 * Idempotent. A second --apply run reports "already ok" for every attribute.
 *
 * WHAT THIS FIXES. MICA enrols every participant at arm 1's Day 1 and materializes only
 * `study_group` into the assigned arm, so `phonen`, `email`, `randomization_date`,
 * `choice_fup_delivery`, `study_withdrawn` and `sms_stop` exist at that one event and nowhere else
 * (docs/alerts/ALERT_EVENT_PREFIX_BUG.md section 3, true by construction). Alerts 02-14 read those
 * fields either unprefixed at the participant's own arm event (02-04) or prefixed with
 * `[day_1_ed_arm_2|3]` (05-14). Both resolve to blank. See docs/alerts/PID271_ALERT_TEST_MATRIX.md.
 *
 * WHY ALL FOUR ATTRIBUTES, ALWAYS. This is the whole point of the script existing rather than
 * someone editing the logic box. Alert 11 on PID 271 was hand-fixed in the condition only, leaving
 * the recipient and the anchor on arm 3, and both fail silently:
 *
 *   cron_send_email_on_field blank -> Alerts.php:1181 returns false, the alert is NEVER SCHEDULED
 *   phone_number_to/email_to blank -> sends to nobody, and Message.php:974 DELETES the log row
 *
 * A partial repair is worse than none: `[study_withdrawn(1)]<>'1'` and `[sms_stop(1)]<>'1'`
 * currently evaluate true *because the field is unreadable at that event*, not because the
 * participant is eligible. Repair only `randomization_date`/`phonen` and the alert starts texting
 * people who withdrew and people who sent STOP. Twilio STOP is a legal obligation.
 *
 * WHAT IS DELIBERATELY NOT TOUCHED:
 *   - `study_group` is the one field MICA *does* materialize into the assigned arm, so
 *     `[day_1_ed_arm_3][study_group]` already resolves. Left alone.
 *   - `[event-name]` clauses correctly name the firing event. Left alone.
 *   - Alerts 01, 15-22. The prefix doc is explicit: "Alert 01 - leave it". It fires at whichever
 *     Day-1 event is being saved, which for a real participant is arm 1's.
 *   - Smart variables (`[survey-link:...]`, `[form-link:...]`, `[record-name]`).
 */

$pid   = (int) ($argv[1] ?? 271);
$apply = in_array('--apply', array_slice($argv, 1), true);

$_GET['pid'] = $pid;
define('NOAUTH', true);
define('CRON', true);
require_once mica_find_redcap_connect();

function mica_find_redcap_connect(): string {
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) return "$env/redcap_connect.php";
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $dir = dirname($dir);
        if (is_file("$dir/redcap_connect.php")) return "$dir/redcap_connect.php";
    }
    fwrite(STDERR, "cannot locate redcap_connect.php; set REDCAP_ROOT\n");
    exit(1);
}

/** The 13 alerts identified in ALERT_EVENT_PREFIX_BUG.md. */
const TARGET_ORDERS = [2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14];

/** The five attributes that are piped independently and must all agree. */
const ATTRS = ['alert_condition', 'phone_number_to', 'email_to', 'cron_send_email_on_field', 'alert_message'];

/**
 * Collected once, at arm 1's Day 1, before the record is randomized. Deliberately excludes
 * `study_group` - see the header.
 *
 * Kept byte-identical to PREFIX_FIELDS in test-alert-logic.php, whose --simulate-prefix-fix pass
 * is what validated this transform before it was ever written to the database.
 */
const PREFIX_FIELDS = ['randomization_date', 'phonen', 'email', 'choice_fup_delivery',
                       'study_withdrawn', 'sms_stop', 'first_name', 'calcrnd', 'dummy_email'];

function mica_apply_prefix_fix(?string $s, array $day1OtherNames, string $enrolName): string {
    $s = (string) $s;
    if ($s === '') return $s;
    // Field-scoped on purpose. A blanket `[day_1_ed_arm_3][` -> `[day_1_ed_arm_1][` swap would also
    // re-point `study_group`, which is the ONE field MICA materializes into the assigned arm - so
    // it is legitimately readable there, and on PID 271 record 32 it exists at 1112 and nowhere
    // else. Only the fields collected before randomization get moved.
    foreach (PREFIX_FIELDS as $f) {
        $fp = preg_quote($f, '/');
        // 1. re-point an existing arm-2/3 Day-1 prefix on this field
        foreach ($day1OtherNames as $other) {
            $s = preg_replace('/\[' . preg_quote($other, '/') . '\]\[' . $fp . '(\([^\)]*\))?\]/',
                              "[$enrolName][$f\$1]", $s);
        }
        // 2. prefix a bare reference. The lookbehind is what makes this idempotent: anything that
        //    already carries an event prefix ends in `][` and is skipped.
        $s = preg_replace('/(?<!\])\[' . $fp . '(\([^\)]*\))?\]/', "[$enrolName][$f\$1]", $s);
    }
    return $s;
}

$Proj = new Project($pid);

// arm-1 Day 1: the event every record passes through before randomization
$enrolEvent = null;
foreach ($Proj->events[1]['events'] ?? [] as $eid => $meta) { $enrolEvent = (int) $eid; break; }
if ($enrolEvent === null) { fwrite(STDERR, "pid $pid has no arm 1 events\n"); exit(1); }
$enrolName = $Proj->getUniqueEventNames($enrolEvent);

$day1OtherNames = [];
foreach ($Proj->events as $armNum => $arm) {
    if ($armNum == 1) continue;
    foreach ($arm['events'] as $eid => $meta) { $day1OtherNames[] = $Proj->getUniqueEventNames((int) $eid); break; }
}

printf("pid %d   enrolment event %d (%s)   other Day-1 events: %s\n",
    $pid, $enrolEvent, $enrolName, implode(', ', $day1OtherNames));
printf("mode: %s\n\n", $apply ? 'APPLY' : 'DRY RUN (pass --apply to commit)');

$in = implode(',', TARGET_ORDERS);
$q  = db_query("SELECT * FROM redcap_alerts WHERE project_id = ? AND alert_order IN ($in) ORDER BY alert_order", [$pid]);
$alerts = [];
while ($r = db_fetch_assoc($q)) $alerts[] = $r;

if (count($alerts) !== count(TARGET_ORDERS)) {
    fwrite(STDERR, sprintf("expected %d alerts, found %d - refusing to run\n", count(TARGET_ORDERS), count($alerts)));
    exit(1);
}

$rollback = ["-- rollback for apply-alert-event-prefix.php on pid $pid, generated " . date('Y-m-d H:i:s')];
$totalChanged = 0; $totalOk = 0; $invalid = [];

foreach ($alerts as $a) {
    $id  = (int) $a['alert_id'];
    $ord = (int) $a['alert_order'];
    $sets = []; $lines = [];

    foreach (ATTRS as $k) {
        $old = (string) ($a[$k] ?? '');
        $new = mica_apply_prefix_fix($old, $day1OtherNames, $enrolName);
        if ($new === $old) { $totalOk++; continue; }
        $sets[$k] = $new;
        $lines[] = sprintf("    %-24s %s\n    %-24s %s", "$k -", preg_replace('/\s+/', ' ', mb_substr($old, 0, 150)),
                                                          '+', preg_replace('/\s+/', ' ', mb_substr($new, 0, 150)));
        $totalChanged++;
    }

    printf("=== %02d  %s  (alert_id %d)\n", $ord, $a['alert_title'], $id);
    if (!$sets) { echo "    already ok - nothing to re-point\n\n"; continue; }

    // Never write a condition REDCap's own parser rejects.
    if (isset($sets['alert_condition'])) {
        if (!LogicTester::isValid($sets['alert_condition'])) {
            $invalid[] = $ord;
            echo "    !! LogicTester::isValid() FALSE on the rewritten condition - skipping this alert\n\n";
            continue;
        }
        echo "    LogicTester::isValid() -> true\n";
    }
    echo implode("\n", $lines) . "\n";

    if ($apply) {
        $pairs = [];
        foreach ($sets as $k => $v) {
            $pairs[] = "`$k` = " . checkNull($v);
            $rollback[] = sprintf("UPDATE redcap_alerts SET `%s` = %s WHERE alert_id = %d;  -- %02d",
                                  $k, checkNull((string) ($a[$k] ?? '')), $id, $ord);
        }
        $sql = "UPDATE redcap_alerts SET " . implode(', ', $pairs) . " WHERE alert_id = $id";
        if (!db_query($sql)) {
            fwrite(STDERR, "UPDATE failed on alert_id $id: " . db_error() . "\n");
            exit(1);
        }
        echo "    -> written\n";
    }
    echo "\n";
}

printf("attributes rewritten %d / already ok %d\n", $totalChanged, $totalOk);
if ($invalid) printf("SKIPPED (invalid logic): %s\n", implode(', ', $invalid));

if ($apply && $totalChanged) {
    $path = __DIR__ . "/rollback-alert-event-prefix-$pid-" . date('Ymd-His') . '.sql';
    file_put_contents($path, implode("\n", $rollback) . "\n");
    echo "rollback written to $path\n";
} elseif (!$apply) {
    echo "\nnothing written. re-run with --apply to commit.\n";
}
