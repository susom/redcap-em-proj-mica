<?php
/**
 * Why didn't the passcode SMS arrive?
 *
 *   php why-no-passcode-sms.php <pid> [record]
 *
 * Read-only. Nothing is changed, on any project. Safe to run on production.
 *
 * The passcode text is alert 01 ("Phone check - passcode by SMS"). It has seven separate ways to not
 * arrive, and six of them are silent - REDCap does not surface "your alert did not fire" anywhere a
 * coordinator would look. This walks them in the order they break in practice and reports the first
 * one that is actually wrong, rather than making somebody guess.
 *
 * Written after the alert failed to arrive during a live test. Deliberately not specific to that
 * cause: the point is a checklist that answers the question in one run, whichever link is broken.
 */

$PID    = (int) ($argv[1] ?? 0);
$RECORD = (string) ($argv[2] ?? '');

if ($PID <= 0) { fwrite(STDERR, "usage: why-no-passcode-sms.php <pid> [record]\n"); exit(1); }

$_GET['pid'] = (string) $PID;
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

$problems = [];
function ok_(string $k, string $d = ''): void { printf("  [ok]  %-26s %s\n", $k, $d); }
function bad_(string $k, string $d, string $fix = ''): void {
    global $problems; $problems[] = $k;
    printf("  [--]  %-26s %s\n", $k, $d);
    if ($fix !== '') foreach (explode("\n", wordwrap($fix, 86)) as $l) printf("        -> %s\n", $l);
}
function note_(string $k, string $d): void { printf("        %-26s %s\n", $k, $d); }

$Proj = new Project($PID, true);
echo "\n=== why no passcode SMS - project $PID" . ($RECORD !== '' ? ", record $RECORD" : '') . " ===\n";

// ------------------------------------------------------------------------------ 1. does it exist
echo "\n1. The alert\n";

$a = db_fetch_assoc(db_query("select * from redcap_alerts
    where project_id = $PID and alert_title like '01 %' limit 1"));

if (!$a) {
    bad_('alert 01', 'no alert whose title starts with "01 " exists on this project',
        'Either it was never imported, or it is titled differently here. Check Alerts & '
        . 'Notifications; the passcode alert is the one whose message contains [calcrnd].');
    echo "\nNothing further to check.\n"; exit(1);
}
ok_('alert 01', "id {$a['alert_id']}, type " . ($a['alert_type'] ?: '(none)'));

// ------------------------------------------------------------ 2. the single most common cause
echo "\n2. Is it switched on?\n";

(int) $a['email_deleted'] === 0
    ? ok_('activated', 'yes')
    : bad_('DEACTIVATED', 'this alone stops every send, silently',
        'This is the default state the alert ships in, and the most common reason the text never '
        . 'arrives. Activate it in Alerts & Notifications, or: UPDATE redcap_alerts SET '
        . "email_deleted=0 WHERE project_id=$PID AND alert_title LIKE '01 %';");

(string) $a['alert_type'] === 'SMS'
    ? ok_('delivery type', 'SMS')
    : bad_('delivery type', "'{$a['alert_type']}' - not SMS, so no text is sent",
        'Alert 01 must be an SMS alert. An EMAIL alert with a phone number as the recipient sends '
        . 'nothing and reports no error.');

// ------------------------------------------------------------------------------ 3. Twilio
echo "\n3. Twilio\n";

$tw = db_fetch_assoc(db_query("select twilio_enabled, twilio_modules_enabled, twilio_account_sid,
    twilio_from_number from redcap_projects where project_id = $PID"));

(int) ($tw['twilio_enabled'] ?? 0) === 1
    ? ok_('twilio_enabled', 'on for this project')
    : bad_('twilio_enabled', 'OFF - SMS cannot be sent at all',
        'Project Setup -> Enable Twilio for SMS/Voice.');

$mods = (string) ($tw['twilio_modules_enabled'] ?? '');
str_contains($mods, 'ALERTS')
    ? ok_('twilio modules', $mods)
    : bad_('twilio modules', "'$mods' - does NOT include ALERTS",
        'This is the quiet one. Twilio can be fully configured and working for survey invitations '
        . 'while alert_type=SMS sends nothing, because ALERTS is a separate module in that list. It '
        . "must be SURVEYS_ALERTS (or include ALERTS) for a passcode alert to deliver.");

note_('from number', $tw['twilio_from_number'] ?: '(none)');
note_('account sid', $tw['twilio_account_sid'] ? substr((string) $tw['twilio_account_sid'], 0, 8) . '...' : '(none)');

// -------------------------------------------------------------------------- 4. the trigger form
echo "\n4. What triggers it\n";

$form = (string) $a['form_name'];
note_('trigger form', $form ?: '(any form)');

if ($form !== '' && !isset($Proj->forms[$form])) {
    bad_('trigger form', "'$form' does not exist on this project",
        'The alert can never fire. This happens after an instrument is renamed or split - the '
        . 'alert keeps pointing at the old name.');
} elseif ($form !== '') {
    ok_('trigger form exists', $form);
}

// Every [field] the condition depends on must exist, or the logic silently evaluates false.
preg_match_all('/\[([a-z0-9_]+)(?:\(\d+\))?\]/i', (string) $a['alert_condition'], $m);
$missing = [];
foreach (array_unique($m[1]) as $f) {
    if (str_starts_with($f, 'event-name') || str_ends_with($f, '_complete')) {
        // _complete fields are real fields; check them too, but only if the form exists.
        if (str_ends_with($f, '_complete')) {
            $srcForm = substr($f, 0, -9);
            if (!isset($Proj->forms[$srcForm])) $missing[] = "$f (no such form '$srcForm')";
        }
        continue;
    }
    if (!isset($Proj->metadata[$f])) $missing[] = $f;
}
$missing === []
    ? ok_('condition fields', 'every field referenced by the condition exists')
    : bad_('condition fields', 'referenced but missing: ' . implode(', ', $missing),
        'A condition referencing a field that does not exist evaluates FALSE and the alert never '
        . 'fires - with no error anywhere. This is what an instrument rename or split leaves behind.');

echo "        condition:\n";
foreach (explode("\n", wordwrap((string) $a['alert_condition'], 84)) as $l) echo "          $l\n";

// ------------------------------------------------------------------- 5. does it hold for a record
if ($RECORD !== '') {
    echo "\n5. Does the condition hold for record $RECORD?\n";

    foreach ($Proj->eventsForms as $eventId => $forms) {
        if ($form !== '' && !in_array($form, $forms, true)) continue;
        $res = \REDCap::evaluateLogic((string) $a['alert_condition'], $PID, $RECORD, $eventId);
        printf("        event %-6s %s\n", $eventId, $res ? 'TRUE - would fire here' : 'false');
    }

    // The two values the message itself needs.
    foreach (['phonen', 'calcrnd'] as $f) {
        if (!isset($Proj->metadata[$f])) { note_($f, 'field does not exist'); continue; }
        $d = \REDCap::getData(['project_id' => $PID, 'records' => [$RECORD],
                               'fields' => [$f], 'return_format' => 'array']);
        $vals = [];
        foreach (($d[$RECORD] ?? []) as $e => $v) if (($v[$f] ?? '') !== '') $vals[] = "ev$e=" . $v[$f];
        $vals === []
            ? bad_($f, 'EMPTY on every event',
                $f === 'calcrnd'
                    ? 'No passcode was generated, so there is nothing to text. calcrnd is a calc '
                      . 'field - it only populates when the form it lives on is saved. If that form '
                      . 'was recently moved or split, re-save it once for this record.'
                    : 'No phone number, so there is no recipient.')
            : ok_($f, implode(', ', $vals));
    }
}

// ----------------------------------------------------------------------------- 6. did it queue?
echo "\n6. Has it ever actually sent?\n";

$sent = db_fetch_assoc(db_query("select count(*) n, max(time_sent) last
    from redcap_alerts_sent where alert_id = {$a['alert_id']}"));
(int) $sent['n'] > 0
    ? ok_('sent log', "{$sent['n']} send(s), most recent {$sent['last']}")
    : note_('sent log', 'never sent - consistent with any failure above');

$q = db_query("select ol.time_sent, ol.recipients, left(ol.message,48) as msg
    from redcap_outgoing_email_sms_log ol where ol.project_id = $PID and ol.type = 'SMS'
    order by ol.time_sent desc limit 5");
$any = false;
while ($r = db_fetch_assoc($q)) { $any = true; printf("        SMS %s  %s  %s\n", $r['time_sent'], $r['recipients'], $r['msg']); }
if (!$any) note_('outgoing SMS log', 'no SMS of any kind has left this project');

// --------------------------------------------------------------------------------------- verdict
echo "\n";
if ($problems === []) {
    echo "No blocking misconfiguration found." . ($RECORD === ''
        ? " Re-run with a record id to test the condition against real data.\n"
        : " If the condition was TRUE above and nothing sent, the next place to look is Twilio's own\n"
        . "delivery log - the message left REDCap and was rejected or dropped downstream.\n");
    exit(0);
}
printf("%d problem(s): %s\n", count($problems), implode(', ', array_unique($problems)));
exit(1);
