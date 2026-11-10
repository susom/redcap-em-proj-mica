<?php
/**
 * Run the transcript copy (EM log -> `mica_transcript` instrument) now, instead of waiting for the
 * 15-minute `mica_transcript_records` cron. Local checks only; on prod the cron does this.
 *
 *   php sync-transcript-records.php <pid> [record]     one project (optionally one record)
 *   php sync-transcript-records.php --cron             the cron method itself, every project
 *
 * Prints the summary: sessions seen, statements written/cleared, skips and errors. Never prints
 * transcript text. See docs/topics/safetyscan/33-transcript-export-field.md.
 */

$cron = in_array('--cron', $argv, true);
$pid = $cron ? 0 : (int) ($argv[1] ?? 0);
$record = $cron ? null : ($argv[2] ?? null);

if (!$cron && $pid <= 0) {
    fwrite(STDERR, "usage: php sync-transcript-records.php <pid> [record] | --cron\n");
    exit(2);
}

if ($pid > 0) {
    $_GET['pid'] = $pid;
}
define('NOAUTH', true);
require_once find_connect();

function find_connect(): string
{
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) {
        return "$env/redcap_connect.php";
    }
    for ($d = __DIR__, $i = 0; $i < 10; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) {
            return "$d/redcap_connect.php";
        }
    }
    fwrite(STDERR, "Could not locate redcap_connect.php. Set REDCAP_ROOT.\n");
    exit(1);
}

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');

if ($cron) {
    echo $module->micaTranscriptRecordsCron(), "\n";
    exit(0);
}

$result = $module->syncTranscriptRecords($pid, $record);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit($result['errors'] === [] ? 0 : 1);
