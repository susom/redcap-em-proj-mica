<?php
/**
 * Manual-test helper for the MICA Option B auth configuration.
 *
 * Creates a throwaway participant that satisfies the real preconditions (credential stored
 * pre-randomization in arm 1, then randomized into arm 2 so a session link can exist), prints
 * the session links, and can reset the login lockout between attempts.
 *
 *   php manual-test-auth.php <pid> setup          # create test participant + print links
 *   php manual-test-auth.php <pid> links          # reprint the links
 *   php manual-test-auth.php <pid> reset-lockout  # clear failed attempts (REDCap has no admin unlock)
 *   php manual-test-auth.php <pid> teardown       # delete the test participant and all traces
 *
 * Defaults: pid=257. See ../11-auth-manual-test-guide.md for what to click and expect.
 */

$pid    = (int)   ($argv[1] ?? 257);
$action = (string)($argv[2] ?? 'setup');

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

const RECORD    = 'MICATEST01';
const LAST_NAME = 'Testerson';

$Proj = new Project($pid, true);
$p    = $Proj->project;

/** Resolve the event id for a unique event name, failing loudly. */
function eventId(Project $Proj, string $uniqueName): int {
    foreach ($Proj->eventInfo as $id => $e) {
        if (($e['unique_event_name'] ?? '') === $uniqueName) return (int)$id;
    }
    // fall back to REDCap's own resolver
    $id = \REDCap::getEventIdFromUniqueEvent($uniqueName);
    if (!is_numeric($id)) { echo "Could not resolve event '$uniqueName'\n"; exit(1); }
    return (int)$id;
}

$credField = $p['survey_auth_field1'];
$credEvent = (int)$p['survey_auth_event_id1'];

switch ($action) {

case 'setup':
    if ($credField !== 'last_name') {
        echo "WARNING: configured credential is '$credField', not 'last_name'.\n";
        echo "         This helper stores a last_name; adjust if your credential differs.\n\n";
    }
    // 1. pre-randomization: credential is stored at the configured credential event (arm 1)
    $r1 = \REDCap::saveData($pid, 'json', json_encode([[
        'record_id'         => RECORD,
        'redcap_event_name' => $Proj->eventInfo[$credEvent]['unique_event_name'],
        'first_name'        => 'Manual',
        'last_name'         => LAST_NAME,
    ]]), 'overwrite');
    if (!empty($r1['errors'])) { echo "save failed: " . json_encode($r1['errors']) . "\n"; exit(1); }

    // 2. randomized into arm 2 - required before a MICA session link can exist,
    //    because getSurveyLink() checks record existence *within the event's arm*.
    $edEvent      = eventId($Proj, 'day_1_ed_arm_2');
    $boosterEvent = eventId($Proj, 'month_3_arm_2');
    $r2 = \REDCap::saveData($pid, 'json', json_encode([[
        'record_id'         => RECORD,
        'redcap_event_name' => 'day_1_ed_arm_2',
        'randomization_date'=> date('Y-m-d'),
    ]]), 'overwrite');
    if (!empty($r2['errors'])) { echo "save failed: " . json_encode($r2['errors']) . "\n"; exit(1); }
    echo "Created test participant " . RECORD . " (credential stored at event $credEvent, randomized into arm 2)\n";
    // fall through to print links

case 'links':
    $edEvent      = eventId($Proj, 'day_1_ed_arm_2');
    $boosterEvent = eventId($Proj, 'month_3_arm_2');
    $ed      = \REDCap::getSurveyLink(RECORD, 'mica_ed_session', $edEvent, 1, $pid);
    $booster = \REDCap::getSurveyLink(RECORD, 'mica_booster_session', $boosterEvent, 1, $pid);
    echo "\n";
    echo "  Record            : " . RECORD . "\n";
    echo "  Credential to type: $credField = \"" . LAST_NAME . "\"  (stored at event $credEvent)\n";
    echo "  Lockout           : {$p['survey_auth_fail_limit']} failures / {$p['survey_auth_fail_window']}-min sliding window\n\n";
    echo "  ED session link      : " . ($ed      ?: 'NULL  <-- record not present in that arm') . "\n";
    echo "  Booster session link : " . ($booster ?: 'NULL  <-- record not present in that arm') . "\n\n";
    echo "  A control (must NOT prompt for login - proves scoping):\n";
    $ctlEvent = eventId($Proj, 'day_1_ed_arm_2');
    $ctl = \REDCap::getSurveyLink(RECORD, 'baseline1', $ctlEvent, 1, $pid);
    echo "  baseline1 link       : " . ($ctl ?: '(not designated/enabled at this event)') . "\n";
    break;

case 'reset-lockout':
    $n = db_result(db_query("select count(1) from redcap_surveys_login l
        join redcap_surveys_response r on r.response_id = l.response_id
        where r.record = '" . db_escape(RECORD) . "'"), 0);
    db_query("delete l from redcap_surveys_login l
        join redcap_surveys_response r on r.response_id = l.response_id
        where r.record = '" . db_escape(RECORD) . "'");
    echo "Cleared $n recorded login attempt(s) for " . RECORD . ". Lockout reset.\n";
    echo "NOTE: this is a test convenience. In production the lock clears only by waiting out\n";
    echo "      the {$p['survey_auth_fail_window']}-minute window - REDCap has no admin unlock.\n";
    break;

case 'teardown':
    Records::deleteRecord(RECORD, $Proj->table_pk, false, false, '', $pid);
    // deleteRecord() clears the record-list cache for ONE arm (its $arm_id parameter). This helper
    // deliberately puts the participant in arm 2 as well, so without this loop the record keeps
    // showing up in arm 2's Record Status Dashboard after teardown, with no data behind it.
    foreach (array_keys($Proj->events) as $armNum) {
        Records::deleteRecordFromRecordListCache($pid, RECORD, $armNum);
    }
    db_query("delete l from redcap_surveys_login l
        join redcap_surveys_response r on r.response_id = l.response_id
        where r.record = '" . db_escape(RECORD) . "'");
    db_query("delete r from redcap_surveys_response r where r.record = '" . db_escape(RECORD) . "'");
    db_query("delete p from redcap_surveys_participants p
        join redcap_surveys s on s.survey_id = p.survey_id
        where s.project_id = $pid
        and not exists (select 1 from redcap_surveys_response r2 where r2.participant_id = p.participant_id)");
    $left = db_result(db_query("select count(1) from redcap_data where project_id=$pid"), 0);
    echo "Removed " . RECORD . ". Data rows remaining in project $pid: $left\n";
    break;

default:
    echo "Unknown action '$action'. Use: setup | links | reset-lockout | teardown\n";
    exit(1);
}
