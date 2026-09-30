<?php
/**
 * Backdate a record's randomization_date and watch which alerts actually queue and send.
 *
 *   php test-backdate-alert-fire.php [pid] [record] [date] [--save] [--restore]
 *
 * Default: pid 271, record 11, date 2026-06-01. Without --save it only prints the prediction and
 * the current state; --save performs the write.
 *
 * WHY THIS EXISTS. docs/alerts/PID271_ALERT_TEST_MATRIX.md section 6 applied the event-prefix fix,
 * and the harness showed all four attributes resolving. That is a static check. This is the
 * dynamic one: does REDCap's own sender, on its own cron, actually queue and deliver?
 *
 * WHY A SAVE IS THE TRIGGER. On PID 271 `AlertsNotificationsDatediffChecker` - the only cron that
 * re-evaluates alert conditions across records - is DISABLED. `...Checker2` and `...Sender` only
 * drain an existing queue. So conditions are evaluated on `saveRecordAction`, i.e. on a record
 * save, and nothing happens through the mere passage of time.
 *
 * WHAT THIS PATH DOES NOT EXERCISE. `Records::saveData()` sets `$isDataImport = true`, and
 * `Alerts.php:193` then forces the instrument to "" so only logic-only alerts can match
 * (finding O7). Alerts 01, 15, 16 and 17 are form-triggered and will NOT fire here. Every alert
 * under test (02-14) is logic-only, so this is the right vehicle for them - but do not read a
 * silent 01/15/16/17 as a failure.
 */

$pid    = (int)    ($argv[1] ?? 271);
$record = (string) ($argv[2] ?? '11');
$date   = (string) ($argv[3] ?? '2026-06-01');
$args   = array_slice($argv, 1);
$doSave  = in_array('--save', $args, true);
$restore = in_array('--restore', $args, true);

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

$Proj      = new Project($pid);
$dataTable = Records::getDataTable($pid);

$enrolEvent = null;
foreach ($Proj->events[1]['events'] ?? [] as $eid => $meta) { $enrolEvent = (int) $eid; break; }

function mica_counters(int $pid): array {
    $q = db_query("SELECT
        (SELECT COUNT(*) FROM redcap_alerts_sent       WHERE alert_id IN (SELECT alert_id FROM redcap_alerts WHERE project_id=$pid)) AS sent,
        (SELECT COUNT(*) FROM redcap_alerts_recurrence WHERE alert_id IN (SELECT alert_id FROM redcap_alerts WHERE project_id=$pid)) AS queued,
        (SELECT COUNT(*) FROM redcap_outgoing_email_sms_log WHERE project_id=$pid) AS outgoing");
    return db_fetch_assoc($q);
}

function mica_show_queue(int $pid): void {
    $q = db_query("SELECT r.alert_id, a.alert_order, a.alert_type, r.record, r.event_id, r.status,
                          r.first_send_time, r.times_sent
                   FROM redcap_alerts_recurrence r JOIN redcap_alerts a USING (alert_id)
                   WHERE a.project_id = ? ORDER BY a.alert_order", [$pid]);
    $n = 0;
    while ($r = db_fetch_assoc($q)) {
        if (!$n++) printf("    %-4s %-6s %-8s %-8s %-9s %-21s %s\n",
                          'ord', 'type', 'record', 'event', 'status', 'first_send_time', 'sent');
        printf("    %-4s %-6s %-8s %-8s %-9s %-21s %s\n", sprintf('%02d', $r['alert_order']),
               $r['alert_type'], $r['record'], $r['event_id'], $r['status'], $r['first_send_time'], $r['times_sent']);
    }
    if (!$n) echo "    (no queue rows)\n";
}

function mica_show_sent(int $pid): void {
    $q = db_query("SELECT s.alert_id, a.alert_order, a.alert_type, s.record, s.event_id, s.last_sent,
                          l.email_to, l.phone_number_to, LEFT(l.subject,48) AS subject
                   FROM redcap_alerts_sent s JOIN redcap_alerts a USING (alert_id)
                   LEFT JOIN redcap_alerts_sent_log l ON l.alert_sent_id = s.alert_sent_id
                   WHERE a.project_id = ? ORDER BY a.alert_order", [$pid]);
    $n = 0;
    while ($r = db_fetch_assoc($q)) {
        if (!$n++) echo "    ord  type   record  event   last_sent            recipient / subject\n";
        printf("    %-4s %-6s %-7s %-7s %-20s %s | %s\n", sprintf('%02d', $r['alert_order']),
               $r['alert_type'], $r['record'], $r['event_id'], $r['last_sent'],
               trim(($r['email_to'] ?? '') . ' ' . ($r['phone_number_to'] ?? '')), $r['subject'] ?? '');
    }
    if (!$n) echo "    (no sent rows)\n";
}

// ---------------------------------------------------------------------------------------------
$q = db_query("SELECT value FROM $dataTable WHERE project_id=? AND record=? AND event_id=? AND field_name='randomization_date'",
              [$pid, $record, $enrolEvent]);
$row = db_fetch_assoc($q);
$current = $row['value'] ?? '(none)';

printf("pid %d  record %s  event %d (%s)\n", $pid, $record, $enrolEvent, $Proj->getUniqueEventNames($enrolEvent));
printf("randomization_date currently: %s   ->   target: %s\n\n", $current, $restore ? '(restore)' : $date);

$c = mica_counters($pid);
printf("counters before:  sent=%s  queued=%s  outgoing=%s\n", $c['sent'], $c['queued'], $c['outgoing']);
echo "queue before:\n";  mica_show_queue($pid);

// --- prediction: which alerts should become due, computed from the alert definitions -----------
echo "\nprediction for randomization_date = $date\n";
$q = db_query("SELECT alert_order, alert_type, alert_condition, cron_send_email_on_time_lag_days AS lag_days
               FROM redcap_alerts WHERE project_id=? AND alert_order BETWEEN 2 AND 14 ORDER BY alert_order", [$pid]);
while ($a = db_fetch_assoc($q)) {
    $due = date('Y-m-d', strtotime("$date +{$a['lag_days']} days"));
    printf("    %02d %-6s +%-4s due %s  %s\n", $a['alert_order'], $a['alert_type'], $a['lag_days'], $due,
           strtotime($due) <= time() ? 'PAST -> should fire if condition true' : 'future');
}

if (!$doSave && !$restore) { echo "\nnothing written. pass --save to perform the backdate.\n"; exit(0); }

// --- the write ---------------------------------------------------------------------------------
$value = $restore ? '2026-09-09' : $date;
$payload = [[
    $Proj->table_pk => $record,
    'redcap_event_name' => $Proj->getUniqueEventNames($enrolEvent),
    'randomization_date' => $value,
]];
echo "\nsaving randomization_date = $value ...\n";
$res = REDCap::saveData($pid, 'json', json_encode($payload), 'overwrite');
printf("  errors: %s\n  warnings: %s\n  ids: %s\n",
    json_encode($res['errors'] ?? []), json_encode($res['warnings'] ?? []), json_encode($res['ids'] ?? []));

$c = mica_counters($pid);
printf("\ncounters after save:  sent=%s  queued=%s  outgoing=%s\n", $c['sent'], $c['queued'], $c['outgoing']);
echo "queue after save:\n"; mica_show_queue($pid);
echo "sent after save:\n";  mica_show_sent($pid);
