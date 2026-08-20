<?php

/**
 * Drive the whole post-session pipeline against a live REDCap, once per fixture.
 *
 *   docker exec <web> php .../scripts/verify-safetyscan.php [pid] [record]
 *
 * Exit 0 = every branch behaved. For each fixture it seeds a conversation, finalizes it, runs a real
 * cron pass, and asserts where the job ended up and what was written.
 *
 * The point is the *failure* branches. A clean scan working proves the plumbing; what has to be
 * proven is that nothing else can look like one:
 *
 *   no_supported_concern  ok            -> ready_for_review, zero findings
 *   self_harm_critical    ok            -> ready_for_review, one finding written. While the review
 *                                          instrument does not exist (audit G5) the documented
 *                                          behaviour is instead: run_status stays `ok` (the model
 *                                          call worked), the job goes to manual_review_required
 *                                          because nothing could be released, and last_error says
 *                                          which instrument is missing
 *   fabricated_quote      citation_mismatch -> manual review on the FIRST attempt (terminal - a
 *                                          scanner that fabricated evidence does not get a second
 *                                          chance at automatic release), NOTHING released
 *   unable_to_assess      refusal       -> manual review on the FIRST attempt, no retries
 *   content_filter        content_filter -> manual review on the first attempt
 *   timeout               timeout       -> requeued with backoff, not given up on yet
 *
 * Runs in scan-mock-mode, which swaps only the caller: schema validation and byte-exact quote
 * verification run exactly as they would against a real model. That is what makes fabricated_quote
 * a real test rather than a comment.
 *
 * Cleans up every row it writes, and reports what the database says rather than what a loop counted.
 */

$PID = (int) ($argv[1] ?? 257);
$RECORD = (string) ($argv[2] ?? '2');

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

use Stanford\MICA\MICAQuery;
use Stanford\MICA\RedcapScanQueueStore;
use Stanford\MICA\ScanJobStateMachine as SM;

$fails = 0;
function check(string $label, $actual, $expected): void
{
    global $fails;
    $ok = (string) $actual === (string) $expected;
    if (!$ok) {
        $fails++;
    }
    printf("    [%s] %-46s got: %-24s want: %s\n", $ok ? 'ok' : 'FAIL', $label, (string) $actual, (string) $expected);
}
function note(string $label, $value): void
{
    printf("    [--] %-46s %s\n", $label, (string) $value);
}

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

// Turn mock mode on for the duration, and put it back exactly as it was.
$originalMock = $module->getProjectSetting('scan-mock-mode', $PID);
$module->setProjectSetting('scan-mock-mode', true, $PID);

$participant = '__verify_scan__';
$writtenLogs = [];

/** Seed one turn the way callAI writes it, with a fixture marker in the participant's message. */
function seed($module, string $participant, string $fixture, string $said): array
{
    $ids = [];

    $q = new MICAQuery($module);
    $q->setValue('mica_id', $participant);
    $q->setValue('message', json_encode([
        'role'    => 'user',
        // The marker is what selects the fixture; it rides in the message text so no configuration
        // has to be threaded through the queue.
        'content' => $said . ' [[scan-fixture:' . $fixture . ']]',
    ]));
    $q->save();
    $ids[] = (int) $q->getId();

    $q = new MICAQuery($module);
    $q->setValue('mica_id', $participant);
    $q->setValue('message', json_encode([
        'response' => ['role' => 'assistant', 'content' => 'Tell me more about that.'],
        'query'    => ['role' => 'user', 'content' => $said],
        'model'    => 'claude-opus-4-7',
    ]));
    $q->save();
    $ids[] = (int) $q->getId();

    return $ids;
}

// self_harm_critical's evidence quote must appear byte-for-byte in this text, or verification
// correctly rejects it - which would make the release path untestable.
$SAID = 'I have been drinking more and some nights I think about not waking up';

$cases = [
    'no_supported_concern' => ['expect' => SM::READY_FOR_REVIEW, 'run' => 'ok', 'findings' => 0],
    'self_harm_critical'   => ['expect' => SM::READY_FOR_REVIEW, 'run' => 'ok', 'findings' => 1],
    // citation_mismatch is TERMINAL: a scanner that fabricated evidence does not get a second
    // chance at automatic release, so this goes to a human on the first attempt.
    'fabricated_quote'     => ['expect' => SM::MANUAL_REVIEW_REQUIRED, 'run' => 'citation_mismatch',
                               'findings' => 0],
    'unable_to_assess'     => ['expect' => SM::MANUAL_REVIEW_REQUIRED, 'run' => 'refusal', 'findings' => 0],
    'content_filter'       => ['expect' => SM::MANUAL_REVIEW_REQUIRED, 'run' => 'content_filter', 'findings' => 0],
    'timeout'              => ['expect' => SM::QUEUED, 'run' => 'timeout', 'findings' => 0],
];

$queueStore = new RedcapScanQueueStore($module);
$instrumentExists = (new \Stanford\MICA\RedcapScanResultStore($module))->findingInstrumentExists((string) $PID);

echo "SafetyScan pipeline against live REDCap (pid $PID, record $RECORD, mock mode ON)\n";
note('mica_safety_finding instrument', $instrumentExists ? 'exists' : 'ABSENT (audit G5)');

foreach ($cases as $fixture => $expect) {
    printf("\n  %s\n", $fixture);

    try {
        $writtenLogs = array_merge($writtenLogs, seed($module, $participant, $fixture, $SAID));

        $result = $module->transcriptFinalizer()->finalize(
            (string) $PID,
            $RECORD,
            $participant,
            1,
            0,
            'baseline',
            'emergency_department'
        );
        $writtenLogs[] = $result->transcriptLogId;

        $runsBefore = (int) $module->query('SELECT COUNT(*) n FROM redcap_entity_mica_scan_run', [])
            ->fetch_assoc()['n'];

        $module->micaScanWorkerCron([]);

        $job = $queueStore->findJob($result->jobId);
        $run = $module->query(
            'SELECT run_status, resolved_model, prompt_sha256, attempt FROM redcap_entity_mica_scan_run '
            . 'WHERE job_id = ? ORDER BY id DESC LIMIT 1',
            [$result->jobId]
        )->fetch_assoc();

        $runsAfter = (int) $module->query('SELECT COUNT(*) n FROM redcap_entity_mica_scan_run', [])
            ->fetch_assoc()['n'];

        check('a scan_run row was written', $runsAfter - $runsBefore, 1);
        check('run_status', $run['run_status'] ?? 'NONE', $expect['run']);

        // The release path needs the review instrument. Where it is absent, the *documented*
        // behaviour is to fail rather than release - so assert that instead of pretending.
        //
        // Note which field says what, because it is genuinely subtle and the first version of this
        // script got it wrong: `run_status` describes the MODEL CALL (answered, schema-valid, quotes
        // verified) and stays `ok`. Whether anything was RELEASED is the job's status, plus
        // last_error for the reason. Two questions, two fields.
        $expectedStatus = $expect['expect'];
        if ($expect['findings'] > 0 && !$instrumentExists) {
            // manual_review_required, not queued: the runner marks a missing review instrument as
            // terminal, so the job goes straight to a human rather than re-calling (and re-paying
            // for) the model twice more against a fault that cannot resolve between attempts.
            $expectedStatus = SM::MANUAL_REVIEW_REQUIRED;
            note('note', 'findings cannot be written (no instrument), so a failure is CORRECT here');
            check('the model call itself still succeeded', $run['run_status'] ?? 'NONE', 'ok');
            check(
                'the job explains why nothing was released',
                str_contains((string) ($job['last_error'] ?? ''), 'mica_safety_finding') ? 'yes' : 'no',
                'yes'
            );
        }

        check('job status', $job['status'] ?? 'NONE', $expectedStatus);

        if ($expect['run'] === 'citation_mismatch') {
            check(
                'nothing released on a bad quote',
                (int) $module->query(
                    'SELECT COUNT(*) n FROM ' . \Records::getDataTable($PID)
                    . ' WHERE project_id = ? AND record = ? AND field_name = ?',
                    [$PID, $RECORD, 'finding_id']
                )->fetch_assoc()['n'],
                0
            );
        }

        if (in_array($expect['run'], ['refusal', 'content_filter'], true)) {
            check('gave up on the first attempt', $job['attempts'] ?? '-', 1);
        }

        if ($expect['run'] === 'timeout') {
            check('requeued rather than given up on', $job['status'] ?? '-', SM::QUEUED);
            check(
                'backing off',
                ((int) $job['next_attempt_at']) > time() ? 'yes' : 'no',
                'yes'
            );
        }
    } catch (\Throwable $e) {
        $fails++;
        printf("    [FAIL] %s: %s\n", get_class($e), $e->getMessage());
    }

    // Each case is independent: clear the queue so the next fixture's job is the only one due.
    $module->query('DELETE FROM redcap_entity_mica_scan_run', []);
    $module->query('DELETE FROM redcap_entity_mica_scan_job', []);
}

echo "\n  cleanup\n";

$module->setProjectSetting('scan-mock-mode', $originalMock, $PID);
note('scan-mock-mode restored to', $originalMock ? 'on' : 'off');

$sweep = $module->query(
    'SELECT DISTINCT l.log_id FROM redcap_external_modules_log l '
    . 'LEFT JOIN redcap_external_modules_log_parameters p ON p.log_id = l.log_id '
    . 'WHERE l.project_id = ? AND (l.record = ? OR (p.name = ? AND p.value = ?))',
    [$PID, $participant, 'mica_id', $participant]
);
while ($row = $sweep->fetch_assoc()) {
    $writtenLogs[] = (int) $row['log_id'];
}

// Transcript rows are written under the real record, so find them by log_type + record.
$sweep = $module->query(
    'SELECT DISTINCT l.log_id FROM redcap_external_modules_log l '
    . 'JOIN redcap_external_modules_log_parameters p ON p.log_id = l.log_id '
    . 'WHERE l.project_id = ? AND l.record = ? AND p.name = ? AND p.value = ?',
    [$PID, $RECORD, 'log_type', 'mica_transcript']
);
while ($row = $sweep->fetch_assoc()) {
    $writtenLogs[] = (int) $row['log_id'];
}

foreach (array_unique($writtenLogs) as $logId) {
    try {
        $module->removeLogs('log_id = ? and project_id = ?', [$logId, $PID]);
    } catch (\Throwable $e) {
        printf("    [WARN] log row %d: %s\n", $logId, $e->getMessage());
    }
}

$left = (int) $module->query(
    'SELECT COUNT(*) n FROM redcap_external_modules_log WHERE log_id IN ('
    . implode(',', array_map('intval', array_unique($writtenLogs) ?: [0])) . ')',
    []
)->fetch_assoc()['n'];

check('log rows left behind', $left, 0);
check(
    'scan jobs left behind',
    (int) $module->query('SELECT COUNT(*) n FROM redcap_entity_mica_scan_job', [])->fetch_assoc()['n'],
    0
);

echo "\n" . ($fails === 0 ? "PASS - every scan branch behaved\n" : "FAIL - $fails check(s) failed\n");
exit($fails === 0 ? 0 : 1);
