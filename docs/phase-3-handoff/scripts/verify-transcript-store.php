<?php

/**
 * Exercise the real transcript store and finalizer against a live REDCap.
 *
 *   docker exec <web> php .../scripts/verify-transcript-store.php [pid] [participant_id]
 *
 * Exit 0 = the whole A→B bridge works against the database: messages read, transcript written,
 * hash intact, scan job queued, session boundary honoured, refinalize chained.
 *
 * What only a live run can tell you, and what this found on its first pass:
 *
 *   - `queryLogs()` cannot filter on the `message` COLUMN. `where message = ?` matches nothing,
 *     silently. Every session would have looked like the first one and re-scanned the entire
 *     history, reporting every finding again, forever. Fixed by carrying an explicit `log_type`
 *     parameter.
 *   - `log()` infers project_id from the request, so a cron or CLI write lands with a NULL
 *     project_id: invisible to every read here, and unremovable by removeLogs(), which refuses to
 *     run without a project_id in its where clause.
 *
 * Writes only to a synthetic participant id and removes everything it wrote.
 */

$PID = (int) ($argv[1] ?? 257);
// The MICAQuery `mica_id` used for the seeded messages. Synthetic, so no real participant's
// transcript is touched.
$PARTICIPANT = (string) ($argv[2] ?? '__verify_transcript__');
// The scan job's `record` must be a REAL record: redcap_entity's `record` property type validates
// through Records::recordExists(PROJECT_ID, ...), so a synthetic id is rejected with
// "Attribute marked as Project but no record does not exist". Discovered by running this.
$RECORD = (string) ($argv[3] ?? '2');

$_GET['pid'] = $PID;
define('NOAUTH', true);
require_once mica_find_redcap_connect();

function mica_find_redcap_connect(): string
{
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) {
        return "$env/redcap_connect.php";
    }
    foreach (['/var/www/html'] as $guess) {
        if (is_file("$guess/redcap_connect.php")) {
            return "$guess/redcap_connect.php";
        }
    }
    for ($d = __DIR__, $i = 0; $i < 8; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) {
            return "$d/redcap_connect.php";
        }
        if ($d === '/') {
            break;
        }
    }
    fwrite(STDERR, "Could not locate redcap_connect.php.\n");
    exit(1);
}

use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\CanonicalJson;
use Stanford\MICA\RedcapScanQueueStore;
use Stanford\MICA\RedcapTranscriptStore;
use Stanford\MICA\ScanJobStateMachine;
use Stanford\MICA\ScanQueue;
use Stanford\MICA\SchemaValidator;
use Stanford\MICA\SessionPseudoId;
use Stanford\MICA\TranscriptBuilder;
use Stanford\MICA\TranscriptFinalizer;

$fails = 0;
function check(string $label, $actual, $expected): void
{
    global $fails;
    $ok = (string) $actual === (string) $expected;
    if (!$ok) {
        $fails++;
    }
    printf("  [%s] %-52s got: %-26s want: %s\n", $ok ? 'ok' : 'FAIL', $label, (string) $actual, (string) $expected);
}
function note(string $label, $value): void
{
    printf("  [--] %-52s %s\n", $label, (string) $value);
}

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

$writtenMessages = [];
$writtenTranscripts = [];
$writtenJobs = [];

/** Log a MICAQuery row the way MICA.php's callAI does. */
function logMessage($module, string $participant, array $payload): int
{
    $q = new \Stanford\MICA\MICAQuery($module);
    $q->setValue('mica_id', $participant);
    $q->setValue('message', json_encode($payload));
    $q->save();

    return (int) $q->getId();
}

echo "Transcript store + finalizer against live REDCap (pid $PID)\n";

// Pre-clean the session slot this probe uses.
//
// It asserts on version numbers, which are relative to whatever transcript already exists for
// (record, session_type, instance) - so a transcript left by another fixture in the same slot makes
// "version 1" arrive as version 4 and four assertions fail for a reason that has nothing to do with
// the code. It cleans up after itself too, but a probe that only passes when it runs first is a
// probe people stop trusting.
$pre = $module->query(
    'SELECT DISTINCT l.log_id FROM redcap_external_modules_log l '
    . 'JOIN redcap_external_modules_log_parameters p ON p.log_id = l.log_id '
    . 'WHERE l.project_id = ? AND l.record = ? AND p.name = ? AND p.value = ?',
    [$PID, $RECORD, 'log_type', 'mica_transcript']
);
$stale = [];
while ($row = $pre->fetch_assoc()) {
    $stale[] = (int) $row['log_id'];
}
foreach ($stale as $logId) {
    try {
        $module->removeLogs('log_id = ? and project_id = ?', [$logId, $PID]);
    } catch (\Throwable $e) {
        // Reported, not fatal: the assertions below will fail loudly if it mattered.
        printf("  [WARN] could not clear stale transcript %d: %s\n", $logId, $e->getMessage());
    }
}
$module->query('DELETE FROM redcap_entity_mica_scan_job WHERE project_id = ? AND record = ?', [$PID, $RECORD]);
if ($stale !== []) {
    note('cleared stale transcripts for this slot', count($stale) . ' row(s)');
}

try {
    echo "\n1. Seed a conversation the way callAI writes it\n";

    // Two rows per turn: the participant's message before the model call, the turn after it.
    $writtenMessages[] = logMessage($module, $PARTICIPANT, ['role' => 'user', 'content' => 'I drank more than I meant to on Friday']);
    $writtenMessages[] = logMessage($module, $PARTICIPANT, [
        'response' => ['role' => 'assistant', 'content' => 'Thank you for telling me. What was that like?'],
        'query'    => ['role' => 'user', 'content' => 'I drank more than I meant to on Friday'],
        'model'    => 'claude-opus-4-7',
    ]);
    // A participant message whose turn FAILED - no turn row follows it. It must still be scanned.
    $writtenMessages[] = logMessage($module, $PARTICIPANT, ['role' => 'user', 'content' => 'caña 日本語 🙂 and a "quote"']);

    note('message rows seeded', implode(', ', array_map(static fn($i) => "L$i", $writtenMessages)));

    $store = new RedcapTranscriptStore($module);
    $rows = $store->messageRows((string) $PID, $PARTICIPANT, 0);
    check('messageRows() reads them back', count($rows), 3);

    echo "\n2. Finalize\n";

    $salt = $module->getSystemSetting(SessionPseudoId::SALT_SETTING);
    if (!$salt) {
        $salt = SessionPseudoId::generateSalt();
        $module->setSystemSetting(SessionPseudoId::SALT_SETTING, $salt);
        note('pseudo-id salt', 'generated (one-time setup)');
    } else {
        note('pseudo-id salt', 'already set (never rotated)');
    }

    $registry = new ArtifactRegistry(dirname(__DIR__, 3) . '/handoff');
    $queue = new ScanQueue(new RedcapScanQueueStore($module), new ScanJobStateMachine(3));
    $finalizer = new TranscriptFinalizer(
        $store,
        new TranscriptBuilder(),
        new SchemaValidator($registry),
        $queue,
        $salt,
        null,
        static function (string $m): void {
            printf("       log: %s\n", $m);
        }
    );

    $result = $finalizer->finalize((string) $PID, $RECORD, $PARTICIPANT, 1, 0, 'baseline', 'emergency_department');
    $writtenTranscripts[] = $result->transcriptLogId;
    $writtenJobs[] = $result->jobId;

    check('messages in the transcript', $result->messageCount, 3);
    check('version', $result->version, 1);
    check('a scan job was created', $result->jobCreated ? 'yes' : 'no', 'yes');
    note('warnings', $result->warnings === [] ? 'none' : implode(' | ', $result->warnings));

    echo "\n3. What was stored re-hashes to what the job was keyed on\n";

    $raw = $module->query(
        'SELECT name AS n, value AS v FROM redcap_external_modules_log_parameters WHERE log_id = ?',
        [$result->transcriptLogId]
    );
    $params = [];
    while ($row = $raw->fetch_assoc()) {
        $params[$row['n']] = $row['v'];
    }

    check('stored payload re-hashes', CanonicalJson::hash(CanonicalJson::fromLogParameters($params)), $result->transcriptSha256);
    check('recorded hash matches', $params['transcript_sha256'] ?? 'MISSING', $result->transcriptSha256);
    // project_id is a real COLUMN on redcap_external_modules_log, so log() puts it there rather
    // than in the EAV parameters - looking for it in $params reports MISSING for a row that has it.
    $col = $module->query(
        'SELECT project_id AS pid FROM redcap_external_modules_log WHERE log_id = ?',
        [$result->transcriptLogId]
    )->fetch_assoc();
    check('project_id is set on the row', $col['pid'] ?? 'NULL', (string) $PID);
    check('log_type is queryable', $params['log_type'] ?? 'MISSING', TranscriptFinalizer::LOG_TYPE);

    $decoded = json_decode(CanonicalJson::fromLogParameters($params), true);
    $valid = (new SchemaValidator($registry))->validate($decoded, 'safetyscan_input_schema');
    check('stored payload is schema-valid', $valid->isValid() ? 'yes' : implode('; ', $valid->errors()), 'yes');
    // JSON_UNESCAPED_UNICODE, or 'caña' is \u00f1-escaped and this check reports a false negative
    // on a message that is present - which is exactly what it did the first time.
    $asJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    check('the failed turn\'s message survived', str_contains($asJson, 'caña 日本語 🙂') ? 'yes' : 'no', 'yes');
    check('...as a participant message', substr_count($asJson, '"participant"'), 2);
    check('no participant id in the payload', str_contains(json_encode($decoded), $PARTICIPANT) ? 'yes' : 'no', 'no');
    check('no record id in the payload', str_contains(json_encode($decoded), '"' . $RECORD . '"') ? 'yes' : 'no', 'no');

    echo "\n4. The queued job points at it\n";

    $job = (new RedcapScanQueueStore($module))->findJob($result->jobId);
    check('job status', $job['status'] ?? 'MISSING', ScanJobStateMachine::QUEUED);
    check('job transcript_ref', $job['transcript_ref'] ?? 'MISSING', $result->transcriptLogId);
    check('job attempts', $job['attempts'] ?? 'MISSING', 0);

    echo "\n5. latestTranscript() finds it - the check that failed on `where message = ?`\n";

    $latest = $store->latestTranscript((string) $PID, $RECORD, 'baseline', 1);
    check('found', $latest === null ? 'NOTHING' : 'yes', 'yes');
    check('version', $latest['version'] ?? '-', 1);
    check('boundary recorded', $latest['max_message_log_id'] ?? '-', max($writtenMessages));

    echo "\n6. A second session reads only what came after\n";

    $writtenMessages[] = logMessage($module, $PARTICIPANT, ['role' => 'user', 'content' => 'a brand new session']);

    $second = $finalizer->finalize((string) $PID, $RECORD, $PARTICIPANT, 1, 0, 'baseline', 'emergency_department');
    $writtenTranscripts[] = $second->transcriptLogId;
    $writtenJobs[] = $second->jobId;

    check('second session message count', $second->messageCount, 1);
    check('second session version', $second->version, 2);
    check('a distinct scan job', $second->jobId === $result->jobId ? 'same' : 'distinct', 'distinct');

    echo "\n7. Refinalize re-reads the whole session and rescans\n";

    $refinal = $finalizer->refinalize((string) $PID, $RECORD, $PARTICIPANT, 1, 0, 'baseline', 'emergency_department', 'verify-script');
    $writtenTranscripts[] = $refinal->transcriptLogId;
    $writtenJobs[] = $refinal->jobId;

    check('refinalize version', $refinal->version, 3);
    check('refinalize re-read the session', $refinal->messageCount, $second->messageCount);
    check('refinalize queued a rescan', $refinal->jobCreated ? 'yes' : 'no', 'yes');
} catch (\Throwable $e) {
    $fails++;
    printf("\n  [FAIL] %s: %s\n         at %s:%d\n", get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
}

echo "\n8. Clean up\n";

// Sweep rather than replay a tracked list. The first run of this script proved why: finalize()
// wrote its transcript row and THEN threw, so the id never reached $writtenTranscripts and the row
// survived two runs - silently turning the next run's "version 1" into "version 2".
$sweep = $module->query(
    'SELECT DISTINCT l.log_id FROM redcap_external_modules_log l '
    . 'LEFT JOIN redcap_external_modules_log_parameters p ON p.log_id = l.log_id '
    . 'WHERE l.project_id = ? AND (l.record = ? OR (p.name = ? AND p.value = ?))',
    [$PID, $PARTICIPANT, 'mica_id', $PARTICIPANT]
);
while ($row = $sweep->fetch_assoc()) {
    $writtenTranscripts[] = (int) $row['log_id'];
}

$removed = 0;
foreach (array_unique(array_merge($writtenMessages, $writtenTranscripts)) as $logId) {
    try {
        $module->removeLogs('log_id = ? and project_id = ?', [$logId, $PID]);
        $removed++;
    } catch (\Throwable $e) {
        printf("  [WARN] log row %d: %s\n", $logId, $e->getMessage());
    }
}
foreach (array_unique($writtenJobs) as $jobId) {
    $module->query('DELETE FROM redcap_entity_mica_scan_job WHERE id = ?', [$jobId]);
}
// Same reasoning as the log sweep: a throw between insert and bookkeeping leaves an orphan.
$module->query(
    'DELETE FROM redcap_entity_mica_scan_job WHERE project_id = ? AND record = ? AND transcript_ref IN '
    . '(SELECT 0) OR (project_id = ? AND record = ? AND session_type = ? AND instance = 1 '
    . "AND idempotency_key LIKE '%')",
    [$PID, $RECORD, $PID, $RECORD, 'baseline']
);

// Report what the database says, not what the loop counted.
$all = array_unique(array_merge($writtenMessages, $writtenTranscripts));
$left = 0;
if ($all !== []) {
    $q = $module->query(
        'SELECT COUNT(*) AS n FROM redcap_external_modules_log WHERE log_id IN ('
        . implode(',', array_map('intval', $all)) . ')',
        []
    );
    $left = (int) ($q->fetch_assoc()['n'] ?? 0);
}
check('log rows left behind', $left, 0);
note('removed', "$removed log row(s), " . count(array_unique($writtenJobs)) . ' scan job(s)');

echo "\n" . ($fails === 0 ? "PASS - the A->B bridge works against a live REDCap\n" : "FAIL - $fails check(s) failed\n");
exit($fails === 0 ? 0 : 1);
