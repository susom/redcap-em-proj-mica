<?php
/**
 * Dry-run the Arm-3 weekly SMS conversation and print exactly what the participant would be texted.
 *
 *   php simulate-weekly-sms.php <pid> <record> <instance> <answer1,answer2,...>
 *
 *   e.g. php simulate-weekly-sms.php 271 1 1  "0,Yes,Yes"     -> ar_1, gg_1
 *        php simulate-weekly-sms.php 271 1 2  "3,Yes,No"      -> sd_2, gp_2
 *        php simulate-weekly-sms.php 271 1 12 "6,No"          -> bd_12, no_plan, sun_end_week_12
 *
 * SENDS NOTHING. It drives the Enhanced SMS Conversation module's own FormManager the way
 * EnhancedSMSConversation::redcap_email() (:132-151) does for the opening turn and the inbound
 * branch (:554-604) does for each reply, but never constructs a TwilioManager.
 *
 * LEAVES NOTHING BEHIND. The answers have to be saved for branching to advance (FormManager reads
 * them back), so the script refuses to run if the record already has any data at the weeks event,
 * and deletes every row it wrote there on exit. Re-runnable.
 *
 * Development projects only: it writes and deletes rows in the data table directly.
 */

$pid      = (int) ($argv[1] ?? 0);
$record   = (string) ($argv[2] ?? '');
$instance = (int) ($argv[3] ?? 1);
$answers  = array_values(array_filter(array_map('trim', explode(',', (string) ($argv[4] ?? ''))),
                                      fn($s) => $s !== ''));
if (!$pid || $record === '') {
    fwrite(STDERR, "usage: php simulate-weekly-sms.php <pid> <record> <instance> <answers,...>\n");
    exit(2);
}

$_GET['pid'] = $pid;
define('NOAUTH', true);
define('CRON', true);
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

use ExternalModules\ExternalModules;
use Stanford\EnhancedSMSConversation\FormManager;

const FORM        = 'sunday';
const WEEKS_EVENT = 'weeks_112_arm_3';

$status = db_result(db_query("select status from redcap_projects where project_id = ?", [$pid]), 0);
if ((string) $status !== '0') { fwrite(STDERR, "pid $pid is not in development status. Refusing.\n"); exit(1); }

$eventIds = array_flip(\REDCap::getEventNames(true, false));
$eventId  = (int) ($eventIds[WEEKS_EVENT] ?? 0);
if (!$eventId) { fwrite(STDERR, "no event named " . WEEKS_EVENT . "\n"); exit(1); }

$table = \Records::getDataTable($pid);
$existing = (int) db_result(db_query(
    "select count(*) from $table where project_id = ? and record = ? and event_id = ?",
    [$pid, $record, $eventId]), 0);
if ($existing) {
    fwrite(STDERR, "record $record already has $existing row(s) at event $eventId - refusing, the cleanup\n"
                 . "would delete real weekly data.\n");
    exit(1);
}

$esms = ExternalModules::getModuleInstance('enhanced_sms_conversation');
$esms->setProjectId($pid);

echo "\n=== pid $pid, record $record, week $instance (event $eventId) ===\n";

try {
    $FM = new FormManager($esms, FORM, '', $record, $eventId, $pid, $instance);
    for ($turn = 1; $turn <= 12; $turn++) {
        foreach ($FM->getArrayOfMessagesAndQuestion() as $m) {
            if (trim((string) $m) === '') continue;
            echo "  MICA -> " . preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($m))) . "\n";
        }
        $choices = $FM->getChoices();
        if (!empty($choices)) echo "          (reply options: " . implode(' / ', $choices) . ")\n";

        $current = $FM->getCurrentField();
        if (empty($current)) { echo "  [conversation complete]\n"; break; }

        $reply = array_shift($answers);
        if ($reply === null) { echo "  [waiting on participant for `$current`]\n"; break; }
        echo "  YOU  -> $reply\n";

        $mapped = $reply;
        foreach ($choices as $code => $label) {
            if (strcasecmp(trim($label), $reply) === 0) { $mapped = $code; break; }
        }
        $FM->saveResponseToREDCap($current, $mapped);

        $next = $FM->getNextField();
        if (!$next) { echo "  [conversation complete]\n"; break; }
        $FM = new FormManager($esms, FORM, $next, $record, $eventId, $pid, $instance);
    }
} finally {
    db_query("delete from $table where project_id = ? and record = ? and event_id = ?",
             [$pid, $record, $eventId]);
    printf("  (cleanup: %d row(s) removed from event %d)\n", db_affected_rows(), $eventId);
}
