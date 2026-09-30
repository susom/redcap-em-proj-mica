<?php
/**
 * Replay a weekly SMS conversation against the Enhanced SMS Conversation module's REAL timing code
 * (re-prompt and timeout) with a simulated clock, and print what the participant's phone would show.
 *
 *   php simulate-esms-timing.php <pid> "<minute=reply,...>" <until_minute>
 *
 *   e.g. php simulate-esms-timing.php 279 "61=15,62=Yes" 240     # the PI's test: answered just after the Q1 re-prompt
 *        php simulate-esms-timing.php 279 "121=15,122=Yes" 240   # same, answered two hours in
 *        php simulate-esms-timing.php 279 "61=15,62=Yes" 1500    # through a 24 h timeout
 *
 *   docker exec redcap_2023_1_web php /var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts/simulate-esms-timing.php 279 "61=15,62=Yes" 240
 *
 * WHAT IS REAL. Everything except the carrier and the wall clock:
 *   - the conversation starts through REDCap's own `Message::send()` with the `@ESMS` subject, the
 *     call a scheduled `sunday` invitation makes, so `redcap_email` resolves the record, event, form
 *     and instance from the Message object exactly as it does for a live invitation;
 *   - each reply goes through the module's `pages/inbound.php`, the page Twilio's webhook posts to;
 *   - every simulated minute runs `cronScanConversationState()`, the method behind the
 *     `esms_scan_conversation_state` cron, which sends re-prompts and timeouts.
 *
 * WHAT IS SIMULATED.
 *   - Twilio. `Twilio\Rest\Client` is defined in this file before the module's autoloader can load
 *     the SDK, so `TwilioManager` sends into a recorder. The script refuses to run if the class came
 *     from anywhere else. Nothing leaves the machine, for any project the cron loop visits.
 *   - Time. The module compares stored epoch seconds (`reminder_ts`, `expiry_ts`,
 *     `last_response_ts`) with `time()`. Winding every stored value of this conversation back by 60 s
 *     is the same as the clock moving forward 60 s, so each simulated minute is one rewind and one
 *     cron call. Real elapsed seconds are counted too.
 *
 * SIDE EFFECTS, ALL UNDONE ON EXIT. The script:
 *   - parks the live `esms_scan_conversation_state` cron, which would otherwise act on the rewound
 *     rows with real Twilio credentials;
 *   - enables the module on <pid> and turns incoming SMS processing on;
 *   - creates test record ESMS-SIM-<time> with a fictional 555-01xx number, (650) 555-0199.
 * On exit it deletes the record and the module's conversation and message rows for that number, and
 * restores the three settings. It refuses to start if any project has an ACTIVE conversation, because
 * its cron calls would advance that conversation too. Development-status projects only.
 */

namespace Twilio\Rest {
    /** Recorder standing in for the Twilio SDK client. `TwilioManager` only uses `messages->create()`. */
    final class Client {
        public $messages;
        public function __construct($sid = null, $token = null) { $this->messages = new SimMessages(); }
    }
    final class SimMessages {
        /** @var callable */
        public static $sink;
        public function create($to, array $opts) {
            $body = (string) ($opts['body'] ?? '');
            (self::$sink)($to, $body);
            // Twilio answers an empty Body with HTTP 400 / error 21602 and delivers nothing; the SDK throws.
            if ($body === '') throw new \Exception('[HTTP 400] Unable to create record: Message body is required. (21602)');
            return (object) ['sid' => 'SMsim' . bin2hex(random_bytes(8)), 'errorMessage' => null,
                             'errorCode' => null, 'error_code' => null, 'error_message' => null];
        }
    }
}

namespace {

$pid     = (int) ($argv[1] ?? 0);
$plan    = (string) ($argv[2] ?? '');
$until   = (int) ($argv[3] ?? 0);
$replies = [];
foreach (array_filter(array_map('trim', explode(',', $plan)), 'strlen') as $pair) {
    if (!preg_match('/^(\d+)\s*=\s*(.+)$/', $pair, $m)) {
        fwrite(STDERR, "bad reply '$pair' - use minute=text, e.g. 61=15\n"); exit(2);
    }
    $replies[(int) $m[1]] = trim($m[2]);
}
if (!$pid || !$until) {
    fwrite(STDERR, "usage: php simulate-esms-timing.php <pid> \"<minute=reply,...>\" <until_minute>\n");
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

const PREFIX      = 'enhanced_sms_conversation';
const CRON_NAME   = 'esms_scan_conversation_state';
const FORM        = 'sunday';
const WEEKS_EVENT = 'weeks_112_arm_3';
const ENROL_EVENT = 'day_1_ed_arm_1';
const SUBJECT     = '@ESMS MICA weekly check-in';
const PHONE       = '(650) 555-0199';   // NNX-555-0100..0199 is reserved for fiction
const PHONE_E164  = '+16505550199';

$rc = new ReflectionClass(\Twilio\Rest\Client::class);
if ($rc->getFileName() !== __FILE__) {
    fwrite(STDERR, "Twilio client resolved to " . $rc->getFileName() . ", not the recorder. Refusing.\n");
    exit(1);
}

$status = db_result(db_query("select status from redcap_projects where project_id = ?", [$pid]), 0);
if ((string) $status !== '0') { fwrite(STDERR, "pid $pid is not in development status. Refusing.\n"); exit(1); }

$moduleId = (int) db_result(db_query("select external_module_id from redcap_external_modules where directory_prefix = ?",
                                     [PREFIX]), 0);
$active = db_query("select l.project_id, count(*) n from redcap_external_modules_log l
                    join redcap_external_modules_log_parameters p on p.log_id = l.log_id and p.name = 'state'
                    where l.external_module_id = ? and l.message = 'ConversationState' and p.value = 'ACTIVE'
                    group by l.project_id", [$moduleId]);
$busy = [];
while ($r = db_fetch_assoc($active)) $busy[] = "pid {$r['project_id']}: {$r['n']}";
if ($busy) {
    fwrite(STDERR, "ACTIVE conversations exist (" . implode(', ', $busy) . "). The cron calls here would\n"
                 . "advance them too. Refusing.\n");
    exit(1);
}

$eventIds = array_flip(\REDCap::getEventNames(true, false));
$weeksEvent = (int) ($eventIds[WEEKS_EVENT] ?? 0);
$enrolEvent = (int) ($eventIds[ENROL_EVENT] ?? 0);
if (!$weeksEvent || !$enrolEvent) { fwrite(STDERR, "pid $pid lacks " . WEEKS_EVENT . " or " . ENROL_EVENT . "\n"); exit(1); }

$record = 'ESMS-SIM-' . time();
$dupe = \REDCap::getData(['project_id' => $pid, 'return_format' => 'array', 'fields' => ['record_id'],
                          'filterLogic' => '[' . ENROL_EVENT . '][phonen] = "' . PHONE . '"']);
if ($dupe) { fwrite(STDERR, "pid $pid already has a record with " . PHONE . ": " . implode(',', array_keys($dupe)) . "\n"); exit(1); }

// ---- temporary state, restored on any exit ------------------------------------------------------
$cronWas     = (string) db_result(db_query("select cron_enabled from redcap_crons where cron_name = ?", [CRON_NAME]), 0);
$enabledWas  = ExternalModules::getProjectSetting(PREFIX, $pid, 'enabled');
$incomingWas = ExternalModules::getProjectSetting(PREFIX, $pid, 'disable-incoming-sms');
echo "if this run is killed, restore by hand:\n"
   . "  update redcap_crons set cron_enabled = '$cronWas' where cron_name = '" . CRON_NAME . "';\n"
   . "  pid $pid module settings: enabled = " . var_export($enabledWas, true)
   . ", disable-incoming-sms = " . var_export($incomingWas, true) . "; delete record $record\n\n";

$restored = false;
$restore = function () use (&$restored, $pid, $record, $cronWas, $enabledWas, $incomingWas, $moduleId) {
    if ($restored) return;
    $restored = true;
    db_query("update redcap_crons set cron_enabled = ? where cron_name = ?", [$cronWas, CRON_NAME]);
    ExternalModules::setProjectSetting(PREFIX, $pid, 'disable-incoming-sms', $incomingWas);
    if ($enabledWas === true || $enabledWas === 'true') {
        ExternalModules::setProjectSetting(PREFIX, $pid, 'enabled', true);
    } else {
        ExternalModules::setProjectSetting(PREFIX, $pid, 'enabled', false);
    }
    // The module's rows for the fictional number: conversation states and outbound/inbound history.
    $ids = [];
    $q = db_query("select distinct l.log_id from redcap_external_modules_log l
                   join redcap_external_modules_log_parameters p on p.log_id = l.log_id
                   where l.external_module_id = ? and l.project_id = ?
                     and p.name in ('cell_number','to_number','from_number') and p.value in (?, ?)",
                  [$moduleId, $pid, PHONE_E164, PHONE]);
    while ($r = db_fetch_assoc($q)) $ids[] = (int) $r['log_id'];
    foreach ($ids as $id) {
        db_query("delete from redcap_external_modules_log_parameters where log_id = ?", [$id]);
        db_query("delete from redcap_external_modules_log where log_id = ?", [$id]);
    }
    \REDCap::deleteRecord($pid, $record);
    $left = (int) db_result(db_query("select count(*) from " . \Records::getDataTable($pid)
                                     . " where project_id = ? and record = ?", [$pid, $record]), 0);
    printf("\n(cleanup: record %s %s, %d module log row(s) removed, cron %s, settings restored)\n",
           $record, $left ? "STILL HAS $left ROW(S)" : "deleted", count($ids), $cronWas);
};
register_shutdown_function($restore);

db_query("update redcap_crons set cron_enabled = 'DISABLED' where cron_name = ?", [CRON_NAME]);
// enableForProject(), not setProjectSetting('enabled'): the hook dispatcher reads a per-request cache of
// enabled modules that only the enable path refreshes, so the @ESMS send would otherwise skip the module.
ExternalModules::enableForProject(PREFIX, ExternalModules::getModuleVersionByPrefix(PREFIX), $pid);
ExternalModules::setProjectSetting(PREFIX, $pid, 'disable-incoming-sms', false);

// ---- clock and recorder --------------------------------------------------------------------------
$t0 = time();
$rewound = 0;
$now = function () use ($t0, &$rewound): int { return (time() - $t0) + $rewound; };
$clock = function (int $sec): string { return sprintf('+%d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60)); };
$transcript = [];
\Twilio\Rest\SimMessages::$sink = function ($to, $body) use (&$transcript, $now) {
    $transcript[] = [$now(), 'MICA', $to, $body];
};

$conversationLogIds = function () use ($moduleId, $pid): array {
    $ids = [];
    $q = db_query("select l.log_id from redcap_external_modules_log l
                   join redcap_external_modules_log_parameters p on p.log_id = l.log_id and p.name = 'cell_number'
                   where l.external_module_id = ? and l.project_id = ? and l.message = 'ConversationState'
                     and p.value = ?", [$moduleId, $pid, PHONE_E164]);
    while ($r = db_fetch_assoc($q)) $ids[] = (int) $r['log_id'];
    return $ids;
};
$advance = function (int $sec) use (&$rewound, $conversationLogIds) {
    $ids = $conversationLogIds();
    if ($ids) {
        db_query("update redcap_external_modules_log_parameters set value = cast(value as signed) - ?
                  where name in ('reminder_ts','expiry_ts','last_response_ts')
                    and log_id in (" . implode(',', array_fill(0, count($ids), '?')) . ")",
                 array_merge([$sec], $ids));
    }
    $rewound += $sec;
};

$esms = ExternalModules::getModuleInstance(PREFIX);

// ---- the participant ---------------------------------------------------------------------------
$save = \REDCap::saveData($pid, 'array', [$record => [$enrolEvent => [
    'record_id' => $record, 's_sex' => '1', 'first_name' => 'Sim', 'phonen' => PHONE,
]]]);
if (!empty($save['errors'])) { fwrite(STDERR, "could not create $record: " . json_encode($save['errors']) . "\n"); exit(1); }

$settings = [];
foreach (['default-conversation-reminder-minutes', 'default-conversation-expiry-minutes',
          'reminder-text-warning', 'default-expiry-text'] as $k) {
    $settings[$k] = ExternalModules::getProjectSetting(PREFIX, $pid, $k);
}
printf("pid %d  record %s  reminder %s min  expiry %s min  reminder text %s\n", $pid, $record,
       var_export($settings['default-conversation-reminder-minutes'], true),
       var_export($settings['default-conversation-expiry-minutes'], true),
       var_export($settings['reminder-text-warning'], true));
echo "replies at minute: " . ($replies ? json_encode($replies) : 'none') . "   until minute $until\n\n";

// Week 1 goes out the way the scheduled invitation sends it.
$msg = new \Message($pid, $record, $weeksEvent, FORM, 1);
$msg->setTo('sim@example.invalid');
$msg->setFrom('noreply@example.invalid');
$msg->setSubject(SUBJECT);
$msg->setBody('[survey-link]');
$msg->send();
if (!$conversationLogIds()) { fwrite(STDERR, "the @ESMS send did not open a conversation - is the hook firing?\n"); exit(1); }

$inbound = function (string $body) use ($esms, &$transcript, $now) {
    $transcript[] = [$now(), 'YOU', PHONE_E164, $body];
    $settingsPid = $esms->getProjectId();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['From' => PHONE_E164, 'To' => $esms->formatNumber($esms->getProjectSetting('twilio-number', $settingsPid)),
              'Body' => $body];
    $module = $esms;
    ob_start();
    include $esms->getModulePath() . 'pages/inbound.php';
    $twiml = trim(ob_get_clean());
    if ($twiml !== '') $transcript[] = [$now(), 'MICA', PHONE_E164, "[webhook reply] $twiml"];
    $_POST = [];
};

for ($minute = 1; $minute <= $until; $minute++) {
    $advance(60);
    $esms->cronScanConversationState(['cron_description' => 'simulated ' . CRON_NAME]);
    $_GET['pid'] = $pid;   // the cron loop leaves it as it found it; keep project context for replies
    if (isset($replies[$minute])) $inbound($replies[$minute]);
}

// ---- report ------------------------------------------------------------------------------------
echo "--- what the phone shows ---\n";
foreach ($transcript as [$at, $who, , $body]) {
    $text = preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($body)));
    if ($who !== 'YOU' && $body === '') $text = '(empty text - Twilio refuses it, error 21602; nothing arrives)';
    printf("  %7s  %-4s %s\n", $clock($at), $who === 'YOU' ? 'YOU' : 'MICA', $text);
}

$states = [];
$ids = $conversationLogIds();
foreach ($ids as $id) {
    $p = [];
    $q = db_query("select name, value from redcap_external_modules_log_parameters where log_id = ?", [$id]);
    while ($r = db_fetch_assoc($q)) $p[$r['name']] = $r['value'];
    $states[] = "#$id " . ($p['state'] ?? '?') . " at " . ($p['current_field'] ?? '(none)');
}
echo "\nconversation: " . implode('; ', $states) . "\n";

// The module logs these with $CS->getRecordId(), so match on the conversation id, not the record.
echo "REDCap logging for conversation " . implode(',', $ids) . ":\n";
$n = 0;
foreach ($ids as $id) {
    $log = db_query("select description, pk from " . \Logging::getLogEventTable($pid) . "
                     where project_id = ? and (description = ? or description like ?)
                     order by log_event_id", [$pid, "Expired Conversation $id", "Reminder sent for % (#$id)"]);
    while ($r = db_fetch_assoc($log)) {
        printf("  %-40s record: %s\n", $r['description'], $r['pk'] === null || $r['pk'] === '' ? '(none)' : $r['pk']);
        $n++;
    }
}
if (!$n) echo "  (no reminder or expiry entries)\n";

$restore();
}
