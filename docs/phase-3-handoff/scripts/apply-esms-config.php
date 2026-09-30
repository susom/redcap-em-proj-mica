<?php
/**
 * Enable and configure the Enhanced SMS Conversation module on a MICA project.
 *
 *   php apply-esms-config.php [pid]      # default 271
 *
 * Idempotent. Re-running reports "already ok" for every unchanged setting.
 *
 * ENABLING THIS MODULE SENDS NOTHING BY ITSELF. The module is inert until an ASI whose email
 * subject contains `@ESMS` fires: `redcap_email` greps the subject, returns false so the mail is
 * never sent, and runs the instrument as a text conversation over the module's *own* Twilio
 * credentials (`EnhancedSMSConversation.php:67-69`, `classes/TwilioManager.php:30-32`). PID 271 has
 * no ASIs at all, so there is no sender. This script only makes the module ready.
 *
 * IT ALSO HAS NOTHING TO DO WITH ALERTS 01-22. Those go through REDCap's own Twilio plumbing,
 * gated on `redcap_projects.twilio_modules_enabled`. The two mechanisms share a vendor and nothing
 * else.
 *
 * WHY THE EVENT IDS ARE ARM 1's DAY 1. `phone-field-event-id` and `sms-opt-out-field-event-id` are
 * where the module reads `phonen` and `sms_stop`. MICA enrols every participant at arm 1's Day 1
 * and materializes only `study_group` into the assigned arm, so contact data is there and nowhere
 * else - see docs/alerts/ALERT_EVENT_PREFIX_BUG.md section 3.
 *
 * WHY `define('CRON', true)`. `ExternalModules::ensureSetSettingIsAllowed()` skips its user-based
 * permission check only when CRON is defined (`ExternalModules.php:2469-2471`), and a CLI run has
 * no session user.
 */

$pid = (int) ($argv[1] ?? 271);

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

const PREFIX = 'enhanced_sms_conversation';

/** Arm-1 Day-1 event: the one event every record passes through before randomization. */
function mica_enrolment_event(int $pid): int {
    $sql = "SELECT em.event_id FROM redcap_events_metadata em
            JOIN redcap_events_arms ea ON ea.arm_id = em.arm_id
            WHERE ea.project_id = ? AND ea.arm_num = 1
            ORDER BY em.day_offset, em.event_id LIMIT 1";
    $q = db_query($sql, [$pid]);
    $row = db_fetch_assoc($q);
    if (!$row) { fwrite(STDERR, "pid $pid has no arm 1 events\n"); exit(1); }
    return (int) $row['event_id'];
}

function mica_unique_event_name(int $pid, int $event_id): string {
    static $proj = null;
    if ($proj === null) $proj = new \Project($pid);
    return (string) $proj->getUniqueEventNames($event_id);
}

$enrolEvent = mica_enrolment_event($pid);

// Twilio credentials: reuse the project's own row so texts come from the number the project is
// already provisioned with, rather than introducing a second sender.
$q = db_query("SELECT twilio_account_sid, twilio_auth_token, twilio_from_number
               FROM redcap_projects WHERE project_id = ?", [$pid]);
$tw = db_fetch_assoc($q);
if (empty($tw['twilio_account_sid']) || empty($tw['twilio_auth_token'])) {
    fwrite(STDERR, "pid $pid has no Twilio credentials in redcap_projects; set them first\n");
    exit(1);
}
$fromNumber = preg_replace('/[^0-9]/', '', (string) $tw['twilio_from_number']);
if ($fromNumber === '') {
    fwrite(STDERR, "pid $pid has no twilio_from_number; the module needs one in E.164\n");
    exit(1);
}
if (strlen($fromNumber) === 10) $fromNumber = '1' . $fromNumber;   // NANP without country code
$fromNumber = '+' . $fromNumber;

$settings = [
    // --- who to text, and where that field lives ---
    'phone-field'                           => 'phonen',
    'phone-field-event-id'                  => (string) $enrolEvent,

    // --- the module's own Twilio credentials (it never reads the project's) ---
    'twilio-number'                         => $fromNumber,
    'twilio-sid'                            => $tw['twilio_account_sid'],
    'twilio-token'                          => $tw['twilio_auth_token'],

    // --- conversation pacing: the PI's values (2026-09-30), "Re-prompts should be 1 hour. Timeout
    //     message at 24 hours". The re-prompt re-arms on every reply, so each unanswered question gets
    //     one. The timeout counts from the first text. ASPIRE PID 192 uses 60 / 180. See
    //     docs/alerts/ESMS_REPROMPT_TIMEOUT.md.
    'default-conversation-reminder-minutes' => '60',
    'reminder-text-warning'                 => 'We missed your response. ',
    'default-conversation-expiry-minutes'   => '1440',
    'default-expiry-text'                   => 'You must be busy. We will check in again later.',
    'nonsense-text-warning'                 => "We don't understand.",
    'no-open-conversation-message'          => 'Thanks! We will check in soon.',

    // --- suppression ---
    'sms-opt-out-field'                     => 'sms_stop',
    'sms-opt-out-field-event-id'            => (string) $enrolEvent,
    'study-withdrawn-logic'                 => '[' . mica_unique_event_name($pid, $enrolEvent) . '][study_withdrawn(1)]="1"',

    // --- inbound stays off: no public tunnel is pointed at this instance, so a reply would be
    //     answered by a module that cannot reach REDCap. Flip when the webhook is repointed.
    'disable-incoming-sms'                  => true,
    'disable-outgoing-sms'                  => false,   // declared in config.json:171 but never read

    'enable-project-debug-logging'          => true,
];

$mod = \ExternalModules\ExternalModules::getModuleInstance(PREFIX);
if (!$mod) { fwrite(STDERR, "module " . PREFIX . " is not instantiable on this instance\n"); exit(1); }

echo "pid $pid  enrolment event $enrolEvent (" . mica_unique_event_name($pid, $enrolEvent) . ")\n\n";

// Enable for the project first; setting writes on a disabled module are accepted but the module
// will not run.
$wasEnabled = \ExternalModules\ExternalModules::getProjectSetting(PREFIX, $pid, 'enabled');
if ($wasEnabled === true || $wasEnabled === 'true') {
    echo "  enabled                                already ok\n";
} else {
    \ExternalModules\ExternalModules::enableForProject(PREFIX, \ExternalModules\ExternalModules::getModuleVersionByPrefix(PREFIX), $pid);
    echo "  enabled                                false -> true\n";
}

$changed = 0; $ok = 0;
foreach ($settings as $key => $want) {
    $have = \ExternalModules\ExternalModules::getProjectSetting(PREFIX, $pid, $key);
    $same = is_bool($want) ? ((bool) $have === $want) : ((string) $have === (string) $want);
    if ($same) { printf("  %-38s already ok\n", $key); $ok++; continue; }
    \ExternalModules\ExternalModules::setProjectSetting(PREFIX, $pid, $key, $want);
    $show = is_bool($want) ? var_export($want, true) : $want;
    if ($key === 'twilio-token') $show = substr((string) $want, 0, 6) . '...';
    printf("  %-38s set -> %s\n", $key, $show);
    $changed++;
}

echo "\nsettings changed $changed / already ok $ok\n";

// --- verification against a real record, the way the module itself resolves things -------------
echo "\nverification\n";
$q = db_query("SELECT dt.record FROM " . \Records::getDataTable($pid) . " dt
               WHERE dt.project_id = ? AND dt.event_id = ? AND dt.field_name = 'phonen' AND dt.value <> ''
               ORDER BY CAST(dt.record AS UNSIGNED) LIMIT 1", [$pid, $enrolEvent]);
$row = db_fetch_assoc($q);
if (!$row) {
    echo "  no record in pid $pid has phonen at event $enrolEvent - cannot verify resolution\n";
} else {
    $rec = $row['record'];
    echo "  record $rec\n";
    echo "    getRecordPhoneNumber    " . var_export($mod->getRecordPhoneNumber($rec, $pid), true) . "\n";
    echo "    isWithdrawn             " . var_export($mod->isWithdrawn($rec, $pid), true) . "\n";
    echo "    getRecordOptOutStatus   " . var_export($mod->getRecordOptOutStatus($rec, $pid), true) . "\n";
}
