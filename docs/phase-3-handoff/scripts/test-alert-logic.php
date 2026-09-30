<?php
/**
 * Test every alert in a project along all four attributes that have to work for a send, and
 * separate "no test record is in the right state" from "the alert is structurally broken".
 *
 *   php test-alert-logic.php [pid] [--alert=N] [--verbose]
 *
 * WHY FOUR ATTRIBUTES AND NOT JUST THE CONDITION. A condition that evaluates true is necessary and
 * nowhere near sufficient. Three other things are piped independently, each with its own silent
 * failure mode:
 *
 *   1. alert_condition            - false: nothing queues. Visible if you look.
 *   2. cron_send_email_on_field   - blank after piping: `Alerts.php:1181` returns false and the
 *                                   alert is NEVER SCHEDULED. No row, no log, no error.
 *   3. phone_number_to / email_to - blank after piping: sends to nobody, and `Message.php:974`
 *                                   DELETES the `redcap_outgoing_email_sms_log` row, so the
 *                                   failure leaves no trace at all.
 *   4. alert_message              - unresolved `[tokens]` are delivered verbatim to the
 *                                   participant. Not a send failure; a content failure.
 *
 * A condition-only test reports PASS on an alert whose recipient is blank.
 *
 * Each attribute is piped through the same call `Alerts.php` uses, with the same arguments:
 *   anchor     Alerts.php:1176   (then the blank check at :1181)
 *   phone      Alerts.php:1385   (then non-numerals stripped at :1389)
 *   email      Alerts.php:1608
 * so a verdict here means the real sender would see the same value.
 *
 * WHY TWO PASSES. Running only against whatever records a project happens to hold is circular: an
 * alert that reads enrolment data at arm 3's Day 1 will "pass" if someone once seeded a test
 * record there, and fail for every real participant. MICA enrols everyone at arm 1's Day 1 and
 * materializes only `study_group` into the assigned arm (docs/alerts/ALERT_EVENT_PREFIX_BUG.md
 * section 3), so:
 *
 *   PASS A - LIVE   only (record, event) pairs where the record is really in that event's arm.
 *                   Answers "does anything fire in this project today?"
 *   PASS B - SHAPE  every alert against one REALISTIC record - enrolment data at arm 1's Day 1 and
 *                   nowhere else - evaluated at each firing event regardless of arm membership.
 *                   Answers "would this fire for a real participant?" That is the production
 *                   question, and it is the one that catches the event-prefix bug.
 *
 * An alert that is OK in pass A and broken in pass B passes only on mis-seeded test data.
 *
 * Read-only. Evaluates and pipes; writes nothing, queues nothing, sends nothing.
 */

$pid = (int) ($argv[1] ?? 271);
$onlyAlert = null; $verbose = false; $simulateFix = false;
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--alert=(\d+)$/', $a, $m)) $onlyAlert   = (int) $m[1];
    elseif ($a === '--verbose')                  $verbose     = true;
    elseif ($a === '--simulate-prefix-fix')      $simulateFix = true;
}

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

/** Fields that identify where a record's enrolment data physically sits. */
const ENROL_FIELDS = ['phonen', 'email', 'first_name', 'choice_fup_delivery', 'randomization_date'];

/**
 * Split a condition into top-level AND clauses so a FALSE can be attributed to one of them.
 * Parenthesis- and quote-aware; leaves OR groups intact as a single clause.
 */
function mica_split_and(string $logic): array {
    $out = []; $buf = ''; $depth = 0; $quote = null;
    $n = strlen($logic);
    for ($i = 0; $i < $n; $i++) {
        $c = $logic[$i];
        if ($quote !== null) { $buf .= $c; if ($c === $quote) $quote = null; continue; }
        if ($c === '"' || $c === "'") { $quote = $c; $buf .= $c; continue; }
        if ($c === '(') $depth++;
        if ($c === ')') $depth--;
        if ($depth === 0 && ($c === 'a' || $c === 'A') && preg_match('/^and\b/i', substr($logic, $i, 4))) {
            if (trim($buf) !== '') { $out[] = trim($buf); $buf = ''; $i += 2; continue; }
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

/** Event ids an alert can fire at, taken from its own [event-name] clauses where it has them. */
function mica_firing_events(array $a, Project $Proj): array {
    $cond = (string) $a['alert_condition'];
    if (preg_match_all("/\[event-name\]\s*=\s*['\"]([a-z0-9_]+)['\"]/i", $cond, $m)) {
        $ids = [];
        foreach (array_unique($m[1]) as $name) {
            $id = array_search($name, $Proj->getUniqueEventNames(), true);
            if ($id !== false) $ids[$id] = $name;
        }
        if ($ids) return $ids;
    }
    $form = $a['form_name'];
    $ids = [];
    foreach ($Proj->eventsForms as $eid => $forms) {
        if ($form === null || $form === '' || in_array($form, $forms, true)) {
            $ids[$eid] = $Proj->getUniqueEventNames($eid);
        }
    }
    return $ids;
}

/** Pipe exactly as Alerts.php:1176 does for the time-lag anchor; :1181 rejects a blank. */
function mica_pipe_anchor(string $field, $record, $event_id, Project $Proj, $pid, $instrument): array {
    if ($field === '' || strpos($field, '[survey-date-completed:') === 0) return ['(n/a)', true];
    if ($Proj->longitudinal) $field = LogicTester::logicPrependEventName($field, 'event-name', $Proj);
    $field = LogicTester::logicAppendCurrentInstance($field, $Proj, $event_id);
    $v = trim(Piping::replaceVariablesInLabel($field, $record, $event_id, 1, [], false, $pid, false,
              $instrument, 1, false, false, $instrument, null, true, false, false, false, true));
    return [$v, $v !== ''];
}

/** Pipe exactly as Alerts.php:1385 does for an SMS recipient, including the :1389 digit strip. */
function mica_pipe_phone(string $tpl, $record, $event_id, $pid, $instrument): array {
    $out = [];
    foreach (explode(';', $tpl) as $one) {
        $one = trim($one);
        if ($one === '') continue;
        $v = Piping::replaceVariablesInLabel($one, $record, $event_id, 1, [], false, $pid, false,
                 $instrument, 1, false, false, $instrument, null, false);
        $d = preg_replace('/[^0-9]/', '', $v);
        if ($d !== '') $out[] = $d;
    }
    $joined = implode(';', $out);
    return [$joined, $joined !== ''];
}

/** Pipe exactly as Alerts.php:1608 does for an email recipient. */
function mica_pipe_email(string $tpl, $record, $event_id, $pid, $instrument): array {
    $v = trim(Piping::replaceVariablesInLabel($tpl, $record, $event_id, 1, [], false, $pid, false,
              $instrument, 1, false, false, $instrument, null, false));
    return [$v, $v !== '' && strpos($v, '@') !== false];
}

/** Any [token] left after piping the body is delivered verbatim to the participant. */
function mica_pipe_body(string $tpl, $record, $event_id, $pid, $instrument): array {
    $v = Piping::replaceVariablesInLabel($tpl, $record, $event_id, 1, [], false, $pid, false,
             $instrument, 1, false, false, $instrument, null, false);
    preg_match_all('/\[[a-z0-9_:\-]+\](?:\[[a-z0-9_:\-]+\])?/i', $v, $m);
    return [$v, empty($m[0]), array_values(array_unique($m[0]))];
}

/**
 * Enrolment fields: collected once, at arm 1's Day 1, before the record is randomized. Every
 * reference to one of these from a later/other-arm event needs the arm-1 Day-1 prefix.
 * `first_name` is here for the message body; the completion and progress fields are not, because
 * they are correctly read at the firing event.
 */
const PREFIX_FIELDS = ['randomization_date', 'phonen', 'email', 'choice_fup_delivery',
                       'study_withdrawn', 'sms_stop', 'first_name', 'calcrnd', 'dummy_email'];

/**
 * Rewrite one alert attribute the way docs/alerts/ALERT_EVENT_PREFIX_BUG.md section 5 prescribes:
 * point every enrolment-field reference at arm 1's Day 1, whether it is currently prefixed with
 * another arm's Day 1 or not prefixed at all. `[event-name]` clauses are left alone - those
 * correctly name the firing event.
 */
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

/** Run all four attribute checks for one (record, event) pair. Returns [verdict, detail lines]. */
function mica_check_attrs(array $a, $rec, $eid, Project $Proj, int $pid): array {
    $instrument = $a['form_name'] ?: '';
    $type = $a['alert_type'];
    $problems = []; $lines = []; $pending = null;

    if ((string) $a['cron_send_email_on_field'] !== '') {
        [$v, $ok] = mica_pipe_anchor((string) $a['cron_send_email_on_field'], $rec, $eid, $Proj, $pid, $instrument);
        $lines[] = sprintf("    anchor ............ %s  %s -> '%s'%s",
            $ok ? 'ok   ' : 'BLANK', $a['cron_send_email_on_field'], $v,
            $ok ? '' : '   <- Alerts.php:1181 returns false; alert is never scheduled');
        if (!$ok) {
            $problems[] = 'anchor blank';
        } else {
            // Alerts.php:1189 - the send time is anchor + the time lag. A correct alert whose due
            // date is months away looks identical to a broken one: nothing arrives.
            $mins = ((int) $a['cron_send_email_on_time_lag_days']) * 1440
                  + ((int) $a['cron_send_email_on_time_lag_hours']) * 60
                  + ((int) $a['cron_send_email_on_time_lag_minutes']);
            if ($a['cron_send_email_on_field_after'] === 'before') $mins = -$mins;
            $due  = calcdate($v, $mins, 'm', 'datetime_seconds');
            $past = strtotime($due) <= time();
            $lines[] = sprintf("    due ............... %s  %s (%+d days from anchor)%s",
                $past ? 'PAST ' : 'future', $due,
                (int) $a['cron_send_email_on_time_lag_days'],
                $past ? '' : '   <- correct but not due yet; nothing will arrive');
            if (!$past) $pending = substr($due, 0, 10);
        }
    }

    if ($type === 'SMS' || $type === 'VOICE_CALL') {
        [$v, $ok] = mica_pipe_phone((string) $a['phone_number_to'], $rec, $eid, $pid, $instrument);
        $lines[] = sprintf("    phone_number_to ... %s  %s -> '%s'%s",
            $ok ? 'ok   ' : 'BLANK', $a['phone_number_to'], $v,
            $ok ? '' : '   <- sends to nobody; Message.php:974 deletes the log row');
        if (!$ok) $problems[] = 'recipient blank';
    } else {
        [$v, $ok] = mica_pipe_email((string) $a['email_to'], $rec, $eid, $pid, $instrument);
        $lines[] = sprintf("    email_to .......... %s  %s -> '%s'", $ok ? 'ok   ' : 'BLANK', $a['email_to'], $v);
        if (!$ok) $problems[] = 'recipient blank';
    }

    [$body, $bodyOk, $leaks] = mica_pipe_body((string) $a['alert_message'], $rec, $eid, $pid, $instrument);
    if (!$bodyOk) {
        $lines[] = sprintf("    message ........... LEAK  unresolved: %s", implode(' ', array_slice($leaks, 0, 6)));
        $problems[] = 'body tokens unresolved';
    } else {
        $lines[] = "    message ........... ok";
    }

    // A future due date is not a defect - the alert is correctly configured and correctly waiting.
    // It is called out separately because it is indistinguishable from breakage by observation:
    // nothing arrives either way.
    $verdict = $problems ? 'BROKEN: ' . implode(', ', $problems)
                         : ($pending ? "PENDING $pending" : 'OK');
    return [$verdict, $lines, $body];
}

// --- arm membership -----------------------------------------------------------------------------
$armsOfRecord = [];
$q = db_query("SELECT record, arm FROM redcap_record_list WHERE project_id = ?", [$pid]);
while ($r = db_fetch_assoc($q)) $armsOfRecord[$r['record']][] = (int) $r['arm'];

$armOfEvent = [];
$q = db_query("SELECT em.event_id, ea.arm_num FROM redcap_events_metadata em
               JOIN redcap_events_arms ea ON ea.arm_id = em.arm_id WHERE ea.project_id = ?", [$pid]);
while ($r = db_fetch_assoc($q)) $armOfEvent[(int) $r['event_id']] = (int) $r['arm_num'];

// arm-1 Day 1: the event every record passes through before randomization
$enrolEvent = null;
foreach ($Proj->events[1]['events'] ?? [] as $eid => $meta) { $enrolEvent = (int) $eid; break; }

// Day-1 events of arms 2 and 3 - where enrolment data must NOT be, for a record to be realistic
$day1Other = [];
foreach ($Proj->events as $armNum => $arm) {
    if ($armNum == 1) continue;
    foreach ($arm['events'] as $eid => $meta) { $day1Other[] = (int) $eid; break; }
}

// --- record shape -------------------------------------------------------------------------------
// realistic = enrolment values at arm 1's Day 1 and at no other arm's Day 1.
$in  = implode(',', array_map(fn($f) => "'" . db_escape($f) . "'", ENROL_FIELDS));
$q = db_query("SELECT DISTINCT record, event_id FROM $dataTable
               WHERE project_id = ? AND field_name IN ($in) AND value <> ''", [$pid]);
$enrolAt = [];
while ($r = db_fetch_assoc($q)) $enrolAt[$r['record']][] = (int) $r['event_id'];

$q = db_query("SELECT DISTINCT record FROM $dataTable WHERE project_id = ?", [$pid]);
$records = [];
while ($r = db_fetch_assoc($q)) $records[] = $r['record'];
usort($records, fn($x, $y) => strnatcmp($x, $y));

$shape = [];
foreach ($records as $rec) {
    $at = $enrolAt[$rec] ?? [];
    if (!$at)                                          $shape[$rec] = 'empty';
    elseif (array_intersect($at, $day1Other))          $shape[$rec] = 'seeded';
    elseif (in_array($enrolEvent, $at, true))          $shape[$rec] = 'realistic';
    else                                               $shape[$rec] = 'other';
}

// The realistic probe record: the one with the most enrolment fields present at arm 1's Day 1.
$probe = null; $probeScore = -1;
foreach ($records as $rec) {
    if ($shape[$rec] !== 'realistic') continue;
    $q = db_query("SELECT COUNT(*) c FROM $dataTable WHERE project_id = ? AND record = ?
                   AND event_id = ? AND field_name IN ($in) AND value <> ''", [$pid, $rec, $enrolEvent]);
    $c = (int) db_fetch_assoc($q)['c'];
    if ($c > $probeScore) { $probeScore = $c; $probe = $rec; }
}

// --- alerts -------------------------------------------------------------------------------------
$sql = "SELECT * FROM redcap_alerts WHERE project_id = ?" . ($onlyAlert !== null ? " AND alert_order = $onlyAlert" : "")
     . " ORDER BY alert_order";
$q = db_query($sql, [$pid]);
$alerts = [];
while ($r = db_fetch_assoc($q)) $alerts[] = $r;

$shapeCounts = array_count_values($shape);
printf("PID %d  %d alerts  %d records  (%s)\n", $pid, count($alerts), count($records),
    count(array_filter($alerts, fn($a) => $a['email_deleted'] == 1)) === count($alerts)
        ? 'all alerts deactivated' : 'SOME ALERTS ARE LIVE');
printf("record shapes: %s\n", implode('  ', array_map(fn($k, $v) => "$k=$v", array_keys($shapeCounts), $shapeCounts)));
printf("arm-1 Day-1 event: %d (%s)   realistic probe record: %s (%d enrolment fields)\n\n",
    $enrolEvent, $Proj->getUniqueEventNames($enrolEvent), $probe ?? 'NONE', max($probeScore, 0));

$summary = [];

$enrolName      = $Proj->getUniqueEventNames($enrolEvent);
$day1OtherNames = array_map(fn($e) => $Proj->getUniqueEventNames($e), $day1Other);

foreach ($alerts as $a) {
    $ord = (int) $a['alert_order'];
    $cond = (string) $a['alert_condition'];
    $events = mica_firing_events($a, $Proj);

    printf("=== %02d  [%s]  %s  (alert_id %d)\n", $ord, $a['alert_type'], $a['alert_title'], (int) $a['alert_id']);
    printf("    fires at: %s\n", implode(', ', $events) ?: '(none)');

    // ---------- PASS A: live - only pairs where the record is really in that event's arm --------
    $livePasses = [];
    foreach ($records as $rec) {
        foreach ($events as $eid => $ename) {
            $arm = $armOfEvent[$eid] ?? null;
            if ($arm === null || !in_array($arm, $armsOfRecord[$rec] ?? [], true)) continue;
            if (REDCap::evaluateLogic($cond, $pid, $rec, $ename) === true) $livePasses[] = [$rec, $eid, $ename];
        }
    }

    $liveVerdict = 'NO-DATA'; $liveLines = []; $liveOn = '';
    if ($livePasses) {
        $byShape = [];
        foreach ($livePasses as $p) $byShape[$shape[$p[0]]][] = "{$p[0]}@{$p[2]}";
        printf("  A live  condition TRUE for %d in-arm pair(s): %s\n", count($livePasses),
            implode('  ', array_map(fn($k, $v) => "[$k] " . implode(',', array_slice($v, 0, 5))
                . (count($v) > 5 ? '...' : ''), array_keys($byShape), $byShape)));
        // prefer a realistic record for the attribute checks; fall back to whatever passed
        $pick = null;
        foreach ($livePasses as $p) if ($shape[$p[0]] === 'realistic') { $pick = $p; break; }
        $pick = $pick ?? $livePasses[0];
        [$liveVerdict, $liveLines] = mica_check_attrs($a, $pick[0], $pick[1], $Proj, $pid);
        foreach ($liveLines as $l) echo $l . "\n";
        $liveOn = "{$pick[0]}@{$pick[2]} (" . $shape[$pick[0]] . ")";
        printf("  A => %s   on %s\n", $liveVerdict, $liveOn);
        if (!array_key_exists('realistic', $byShape)) {
            echo "      NOTE: every passing record is mis-seeded test data, not a real participant shape\n";
        }
    } else {
        echo "  A live  condition FALSE for every in-arm record/event pair\n";
    }

    // ---------- PASS B: shape - one realistic record, at each firing event ----------------------
    $shapeVerdict = 'n/a'; $shapeDetail = '';
    if ($probe === null) {
        echo "  B shape no realistic record exists in this project - cannot probe\n";
    } else {
        $hit = null;
        foreach ($events as $eid => $ename) {
            if (REDCap::evaluateLogic($cond, $pid, $probe, $ename) === true) { $hit = [$eid, $ename]; break; }
        }
        if ($hit) {
            [$shapeVerdict, $lines] = mica_check_attrs($a, $probe, $hit[0], $Proj, $pid);
            printf("  B shape condition TRUE for record %s @ %s\n", $probe, $hit[1]);
            foreach ($lines as $l) echo $l . "\n";
            printf("  B => %s\n", $shapeVerdict);
        } else {
            // clause breakdown at the firing event that gets furthest
            $best = null; $bestScore = -1; $clauses = mica_split_and($cond);
            foreach ($events as $eid => $ename) {
                $s = 0;
                foreach ($clauses as $c) if (REDCap::evaluateLogic($c, $pid, $probe, $ename) === true) $s++;
                if ($s > $bestScore) { $bestScore = $s; $best = [$eid, $ename]; }
            }
            $shapeVerdict = 'COND-FALSE';
            printf("  B shape condition FALSE for realistic record %s; closest %s (%d/%d clauses)\n",
                $probe, $best[1], $bestScore, count($clauses));
            foreach ($clauses as $c) {
                $r = REDCap::evaluateLogic($c, $pid, $probe, $best[1]);
                printf("        %-5s %s\n", $r === true ? 'true' : ($r === null ? 'NULL' : 'FALSE'),
                       preg_replace('/\s+/', ' ', $c));
            }
        }
    }

    // ---------- PASS C: what the section-5 prefix fix would do, without applying it -------------
    $fixVerdict = '';
    if ($simulateFix && $probe !== null) {
        $fixed = $a;
        foreach (['alert_condition', 'phone_number_to', 'email_to', 'cron_send_email_on_field', 'alert_message'] as $k) {
            $fixed[$k] = mica_apply_prefix_fix($a[$k] ?? '', $day1OtherNames, $enrolName);
        }
        $changedKeys = [];
        foreach (['alert_condition', 'phone_number_to', 'email_to', 'cron_send_email_on_field', 'alert_message'] as $k) {
            if ((string) ($fixed[$k] ?? '') !== (string) ($a[$k] ?? '')) $changedKeys[] = $k;
        }
        if (!$changedKeys) {
            echo "  C fix   no enrolment references to re-point - alert unchanged by the fix\n";
            $fixVerdict = 'unchanged';
        } else {
            $hit = null;
            foreach ($events as $eid => $ename) {
                if (REDCap::evaluateLogic($fixed['alert_condition'], $pid, $probe, $ename) === true) { $hit = [$eid, $ename]; break; }
            }
            printf("  C fix   rewrites %s\n", implode(', ', $changedKeys));
            if ($hit) {
                [$fixVerdict, $lines] = mica_check_attrs($fixed, $probe, $hit[0], $Proj, $pid);
                printf("          condition TRUE for realistic record %s @ %s\n", $probe, $hit[1]);
                foreach ($lines as $l) echo $l . "\n";
                printf("  C => %s\n", $fixVerdict);
            } else {
                $fixVerdict = 'COND-FALSE';
                $clauses = mica_split_and($fixed['alert_condition']);
                $best = array_key_first($events); $bestName = $events[$best];
                printf("          condition STILL FALSE for realistic record %s @ %s\n", $probe, $bestName);
                foreach ($clauses as $c) {
                    $r = REDCap::evaluateLogic($c, $pid, $probe, $bestName);
                    printf("        %-5s %s\n", $r === true ? 'true' : ($r === null ? 'NULL' : 'FALSE'),
                           preg_replace('/\s+/', ' ', $c));
                }
            }
        }
    }

    $summary[$ord] = [$a['alert_type'], $a['alert_title'], $liveVerdict, $shapeVerdict, $liveOn, $fixVerdict];
    echo "\n";
}

// --- matrix ---------------------------------------------------------------------------------------
$w = $simulateFix ? 140 : 112;
echo str_repeat('=', $w) . "\n";
printf("%-4s %-6s %-40s %-26s %-34s %s\n", '#', 'type', 'alert', 'A: live in PID ' . $pid,
    'B: realistic participant', $simulateFix ? 'C: after prefix fix' : '');
echo str_repeat('-', $w) . "\n";
foreach ($summary as $ord => $s) {
    printf("%-4s %-6s %-40s %-26s %-34s %s\n", sprintf('%02d', $ord), $s[0], mb_substr($s[1], 0, 39),
        $s[2], $s[3], $simulateFix ? ($s[5] ?? '') : '');
}
echo str_repeat('=', $w) . "\n";
printf("pass B (the production question): %s\n",
    implode('  ', array_map(fn($k, $v) => "$k=$v",
        array_keys(array_count_values(array_map(fn($s) => explode(':', $s[3])[0], $summary))),
        array_values(array_count_values(array_map(fn($s) => explode(':', $s[3])[0], $summary))))));
