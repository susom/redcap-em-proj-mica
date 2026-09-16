<?php
/**
 * Reopen a closed MICA session so the participant can use their link again.
 *
 *   php reopen-session.php <pid> <record> <host_instrument> [event_id] [--dry-run]
 *
 * A session is closed by TWO signals and both have to go, or the participant is still refused:
 *
 *   1. `redcap_surveys_response.first_submit_time` / `completion_time` - what
 *      `MICA::sessionIsClosed()` actually enforces on, set by `markSurveyResponseSubmitted()`.
 *   2. `<host>_complete = '2'` - the form status, the CRC-visible control surface.
 *
 * **Setting the form status back to Incomplete on the record page is not enough on its own.**
 * That is what the old docblocks promised, and it stopped being sufficient when enforcement moved
 * onto the response row - which it had to, because the form-status write was silently failing on
 * every session (docs/session-lifecycle/README.md). This script exists so there is one explicit,
 * auditable way to undo a closure rather than inferring intent from a field's value.
 *
 * Deliberately NOT automatic and NOT inferred: nothing treats "form status looks Incomplete" as
 * "a human meant to reopen this", because a value appearing for any other reason would silently
 * reopen a finished session and put back the re-entry bug this all came from.
 *
 * Does not touch the transcript or its SafetyScan. A reopened session's earlier conversation stays
 * finalized and screened; new messages are finalized on the next close.
 */

$pid        = (int)    ($argv[1] ?? 0);
$record     = (string) ($argv[2] ?? '');
$instrument = (string) ($argv[3] ?? '');
$eventId    = isset($argv[4]) && ctype_digit((string) $argv[4]) ? (int) $argv[4] : null;
$dryRun     = in_array('--dry-run', $argv, true);

if ($pid <= 0 || $record === '' || $instrument === '') {
    fwrite(STDERR, "usage: php reopen-session.php <pid> <record> <host_instrument> [event_id] [--dry-run]\n");
    exit(2);
}

$_GET['pid'] = $pid;
define('NOAUTH', true);
require_once find_connect();

function find_connect(): string {
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) return "$env/redcap_connect.php";
    foreach (['/var/www/html'] as $g) if (is_file("$g/redcap_connect.php")) return "$g/redcap_connect.php";
    for ($d = __DIR__, $i = 0; $i < 10; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) return "$d/redcap_connect.php";
        if ($d === '/') break;
    }
    fwrite(STDERR, "Could not locate redcap_connect.php. Set REDCAP_ROOT.\n");
    exit(1);
}

$Proj = new Project($pid);

if (!isset($Proj->forms[$instrument])) {
    fwrite(STDERR, "No such instrument on pid $pid: $instrument\n");
    exit(1);
}

$eventClause = $eventId !== null ? 'AND p.event_id = ?' : '';
$params      = [$pid, $instrument, $record];
if ($eventId !== null) $params[] = $eventId;

/** Current state, so the operator sees what they are undoing. */
$rows = db_query_params(
    'SELECT r.response_id, r.record, p.event_id, r.first_submit_time, r.completion_time '
    . 'FROM redcap_surveys_response r '
    . 'JOIN redcap_surveys_participants p ON p.participant_id = r.participant_id '
    . 'JOIN redcap_surveys s ON s.survey_id = p.survey_id '
    . "WHERE s.project_id = ? AND s.form_name = ? AND r.record = ? $eventClause",
    $params
);

function db_query_params(string $sql, array $params) {
    $out = [];
    $q = db_query($sql, $params);
    while ($row = db_fetch_assoc($q)) $out[] = $row;
    return $out;
}

if ($rows === []) {
    echo "No survey response found for record '$record' on '$instrument'"
       . ($eventId !== null ? " at event $eventId" : '') . " - nothing to reopen.\n";
    exit(0);
}

echo "Responses found:\n";
$closed = 0;
foreach ($rows as $r) {
    $isClosed = $r['completion_time'] !== null || $r['first_submit_time'] !== null;
    if ($isClosed) $closed++;
    printf(
        "  response %-6s event %-6s first_submit=%-20s completion=%-20s  %s\n",
        $r['response_id'], $r['event_id'],
        $r['first_submit_time'] ?? 'NULL', $r['completion_time'] ?? 'NULL',
        $isClosed ? 'CLOSED' : 'open'
    );
}

if ($closed === 0) {
    echo "\nNothing closed - the session is already open.\n";
    exit(0);
}

if ($dryRun) {
    echo "\n--dry-run: would clear first_submit_time/completion_time on the CLOSED response(s) above,\n"
       . "           and set {$instrument}_complete back to Incomplete.\n";
    exit(0);
}

db_query(
    'UPDATE redcap_surveys_response r '
    . 'JOIN redcap_surveys_participants p ON p.participant_id = r.participant_id '
    . 'JOIN redcap_surveys s ON s.survey_id = p.survey_id '
    . 'SET r.first_submit_time = NULL, r.completion_time = NULL '
    . "WHERE s.project_id = ? AND s.form_name = ? AND r.record = ? $eventClause",
    $params
);
echo "\nCleared the response timestamps.\n";

/**
 * Form status back to Incomplete, so the record page agrees with the response row and the expiry
 * cron sees the session as a candidate again. Now that the timestamps above are gone, saveData
 * will accept this write - that is the same ordering dependency the close path has, in reverse.
 */
$eventName = $eventId !== null
    ? (string) $Proj->getUniqueEventNames($eventId)
    : (string) $Proj->getUniqueEventNames((int) $rows[0]['event_id']);

$save = REDCap::saveData([
    'project_id'        => $pid,
    'dataFormat'        => 'json',
    'overwriteBehavior' => 'overwrite',
    'data'              => json_encode([[
        (string) $Proj->table_pk => $record,
        'redcap_event_name'      => $eventName,
        $instrument . '_complete' => '0',
    ]]),
]);

if (!empty($save['errors'])) {
    echo "WARNING: form status not reset: " . json_encode($save['errors']) . "\n";
    echo "The session is reopened regardless - enforcement reads the response row.\n";
} else {
    echo "Form status set back to Incomplete at $eventName.\n";
}

echo "\nDone. The participant's existing link works again.\n";
echo "NOTE: the expiry cron will not re-close this session - MICA::sessionWasClosedBefore() still\n";
echo "      holds its close record, which is deliberate (a reopened window is past by definition).\n";
