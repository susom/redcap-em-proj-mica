<?php

/**
 * Put one record back to "never chatted", so a manual test can start from the beginning.
 *
 *   docker exec <web> php .../reset-test-record.php <pid> <record> [--dry-run]
 *   docker exec <web> php .../reset-test-record.php 257 2 --dry-run
 *
 * Leaves the record's own study data alone - screening fields, `last_name` (which is the Survey
 * Login credential), randomisation. Only MICA's own traces go.
 *
 * ## Why this is a script and not four DELETE statements
 *
 * Three of the five things it removes are not findable the obvious way:
 *
 *   - **Chat turns live under `record = 'MICAQuery'`.** ASEMLO uses the EM log's `record` column as
 *     an object-name discriminator, and the actual participant is in the `mica_id` *parameter*. So a
 *     `DELETE ... WHERE record = '2'` looks right, runs clean, and removes none of the conversation.
 *   - **Transcripts DO use the real record**, and are identified by the `log_type` parameter rather
 *     than the `message` column, which `queryLogs()` cannot address.
 *   - **`mica_scan_run` has no project or record column.** It hangs off a job, so it scopes through
 *     the join - and must be deleted before its jobs, while there is still something to join to.
 *
 * `SecureChatLog` rows are deliberately untouched: they belong to SecureChatAI, are shared across
 * every project on the server, and are not MICA's to remove.
 */

$PID = (int) ($argv[1] ?? 0);
$RECORD = (string) ($argv[2] ?? '');
$DRY = in_array('--dry-run', $argv, true);

if ($PID <= 0 || $RECORD === '') {
    fwrite(STDERR, "Usage: reset-test-record.php <pid> <record> [--dry-run]\n");
    exit(1);
}

$_GET['pid'] = (string) $PID;
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

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

printf(
    "Reset MICA traces for record %s on project %d%s\n\n",
    $RECORD,
    $PID,
    $DRY ? '  [DRY RUN - nothing will be deleted]' : ''
);

$one = static function (string $sql, array $params) use ($module): int {
    return (int) $module->query($sql, $params)->fetch_assoc()['c'];
};

// ---------------------------------------------------------------- 1. count everything first

// Chat turns: the log row's record column is ASEMLO's object name, not the participant.
$chatIds = [];
$result = $module->query(
    'SELECT l.log_id FROM redcap_external_modules_log l '
    . 'JOIN redcap_external_modules_log_parameters p ON p.log_id = l.log_id '
    . "WHERE l.project_id = ? AND l.record = 'MICAQuery' AND p.name = 'mica_id' AND p.value = ?",
    [$PID, $RECORD]
);
while ($row = $result->fetch_assoc()) {
    $chatIds[] = (int) $row['log_id'];
}

$transcriptIds = [];
$result = $module->query(
    "SELECT log_id FROM redcap_external_modules_log WHERE project_id = ? AND record = ? "
    . "AND message = 'mica_transcript'",
    [$PID, $RECORD]
);
while ($row = $result->fetch_assoc()) {
    $transcriptIds[] = (int) $row['log_id'];
}

$jobs = $one(
    'SELECT COUNT(*) c FROM redcap_entity_mica_scan_job WHERE project_id = ? AND record = ?',
    [$PID, $RECORD]
);
$runs = $one(
    'SELECT COUNT(*) c FROM redcap_entity_mica_scan_run r '
    . 'JOIN redcap_entity_mica_scan_job j ON j.id = r.job_id WHERE j.project_id = ? AND j.record = ?',
    [$PID, $RECORD]
);
$notifications = $one(
    'SELECT COUNT(*) c FROM redcap_entity_mica_notification WHERE project_id = ? AND record = ?',
    [$PID, $RECORD]
);

/** Every field on MICA's own instruments, so the data delete is targeted rather than by prefix. */
$forms = ['mica_ed_session', 'mica_booster_session', 'mica_safety_finding'];
$fields = [];
$result = $module->query(
    'SELECT field_name FROM redcap_metadata WHERE project_id = ? AND form_name IN (?, ?, ?)',
    array_merge([$PID], $forms)
);
while ($row = $result->fetch_assoc()) {
    $fields[] = $row['field_name'];
}

$dataRows = 0;
if ($fields !== []) {
    $dataRows = $one(
        'SELECT COUNT(*) c FROM ' . \Records::getDataTable($PID) . ' WHERE project_id = ? AND record = ? '
        . 'AND field_name IN (' . implode(',', array_fill(0, count($fields), '?')) . ')',
        array_merge([$PID, $RECORD], $fields)
    );
}

printf("  %-34s %d\n", 'chat turn log rows', count($chatIds));
printf("  %-34s %d\n", 'transcript log rows', count($transcriptIds));
printf("  %-34s %d\n", 'scan jobs', $jobs);
printf("  %-34s %d\n", 'scan runs', $runs);
printf("  %-34s %d\n", 'notification rows', $notifications);
printf("  %-34s %d across %d field(s)\n", 'instrument data rows', $dataRows, count($fields));

if ($DRY) {
    echo "\nDry run - nothing deleted.\n";
    exit(0);
}

echo "\nDeleting\n";

// ---------------------------------------------------------------- 2. delete

$deleteLogs = static function (array $ids) use ($module): void {
    foreach (array_chunk($ids, 200) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        // Parameters first: the log row is the parent, and an orphaned parameter row would be
        // invisible to every reader while still holding message text.
        $module->query("DELETE FROM redcap_external_modules_log_parameters WHERE log_id IN ($in)", $chunk);
        $module->query("DELETE FROM redcap_external_modules_log WHERE log_id IN ($in)", $chunk);
    }
};

$deleteLogs($chatIds);
printf("  %-34s %d removed\n", 'chat turns', count($chatIds));

$deleteLogs($transcriptIds);
printf("  %-34s %d removed\n", 'transcripts', count($transcriptIds));

// Runs before jobs - they scope through the join.
$module->query(
    'DELETE r FROM redcap_entity_mica_scan_run r '
    . 'JOIN redcap_entity_mica_scan_job j ON j.id = r.job_id '
    . 'WHERE j.project_id = ? AND j.record = ?',
    [$PID, $RECORD]
);
$module->query(
    'DELETE FROM redcap_entity_mica_scan_job WHERE project_id = ? AND record = ?',
    [$PID, $RECORD]
);
printf("  %-34s %d job(s), %d run(s) removed\n", 'scan queue', $jobs, $runs);

$module->query(
    'DELETE FROM redcap_entity_mica_notification WHERE project_id = ? AND record = ?',
    [$PID, $RECORD]
);
printf("  %-34s %d removed\n", 'notifications', $notifications);

if ($fields !== []) {
    $module->query(
        'DELETE FROM ' . \Records::getDataTable($PID) . ' WHERE project_id = ? AND record = ? '
        . 'AND field_name IN (' . implode(',', array_fill(0, count($fields), '?')) . ')',
        array_merge([$PID, $RECORD], $fields)
    );
    printf("  %-34s %d removed\n", 'instrument data', $dataRows);
}

// ---------------------------------------------------------------- 3. prove it

echo "\nVerifying\n";

$left = [
    'chat turns' => (int) $module->query(
        'SELECT COUNT(*) c FROM redcap_external_modules_log l '
        . 'JOIN redcap_external_modules_log_parameters p ON p.log_id = l.log_id '
        . "WHERE l.project_id = ? AND l.record = 'MICAQuery' AND p.name = 'mica_id' AND p.value = ?",
        [$PID, $RECORD]
    )->fetch_assoc()['c'],
    'transcripts' => (int) $module->query(
        "SELECT COUNT(*) c FROM redcap_external_modules_log WHERE project_id = ? AND record = ? "
        . "AND message = 'mica_transcript'",
        [$PID, $RECORD]
    )->fetch_assoc()['c'],
    'scan jobs' => $one(
        'SELECT COUNT(*) c FROM redcap_entity_mica_scan_job WHERE project_id = ? AND record = ?',
        [$PID, $RECORD]
    ),
];

if ($fields !== []) {
    $left['instrument data'] = $one(
        'SELECT COUNT(*) c FROM ' . \Records::getDataTable($PID) . ' WHERE project_id = ? AND record = ? '
        . 'AND field_name IN (' . implode(',', array_fill(0, count($fields), '?')) . ')',
        array_merge([$PID, $RECORD], $fields)
    );
}

$failed = 0;
foreach ($left as $label => $count) {
    printf("  %-4s %-30s %d\n", $count === 0 ? '[ok]' : '[--]', $label . ' remaining', $count);
    $failed += $count === 0 ? 0 : 1;
}

// The credential the survey link depends on. Reported rather than touched: deleting it is what would
// break the login, and somebody resetting a record for another test almost never wants that.
$cred = $module->query(
    'SELECT value FROM ' . \Records::getDataTable($PID)
    . " WHERE project_id = ? AND record = ? AND field_name = 'last_name' LIMIT 1",
    [$PID, $RECORD]
)->fetch_assoc()['value'] ?? null;

printf("\n  Survey Login credential (last_name) is intact: %s\n", var_export($cred, true));

echo $failed === 0
    ? "\nPASS - record $RECORD can start a session from the beginning.\n"
    : "\nFAIL - $failed thing(s) survived; see above.\n";

exit($failed === 0 ? 0 : 1);
