<?php
/**
 * Read-only: mint (or reuse) the `sunday` survey link for an arm-3 record at Weeks 1-12 (ev 1013),
 * so the weekly-SMS survey can be rendered exactly as a participant would receive it.
 *
 *   docker exec <web> php sunday-link.php [pid] [record] [event_id]
 */
$PID    = (int) ($argv[1] ?? 257);
$RECORD = (string) ($argv[2] ?? '4');
$EVENT  = (int) ($argv[3] ?? 1013);

$_GET['pid'] = (string) $PID;
define('NOAUTH', true);

function mica_find_redcap_connect(): string {
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) return "$env/redcap_connect.php";
    foreach (['/var/www/html'] as $g) if (is_file("$g/redcap_connect.php")) return "$g/redcap_connect.php";
    fwrite(STDERR, "no redcap_connect.php\n");
    exit(1);
}
require_once mica_find_redcap_connect();

$link = \REDCap::getSurveyLink($RECORD, 'sunday', $EVENT, 1, $PID);
echo "record=$RECORD event=$EVENT\n";
echo "link=" . ($link ?: '(NULL - survey not reachable for this record/event)') . "\n";
