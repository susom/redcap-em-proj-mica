<?php
/**
 * Create (or update) the sender for the Arm-3 weekly SMS: one recurring ASI on `sunday`.
 *
 *   php apply-weekly-sms-asi.php [pid] [--activate]
 *
 * Defaults: pid=268, created INACTIVE. Nothing sends until you pass --activate (or tick the ASI in
 * Survey Distribution Tools). That default is deliberate: Twilio is live and the recipient is a
 * real handset.
 *
 * WHY ONE ASI AND NOT TWELVE. ASPIRE (PID 192) has twelve `Week N SMS` events with one ASI each.
 * MICA arm 3 has a single `Weeks 1-12` event, and the decision taken 2026-09-21 was to make that
 * event repeat rather than create twelve events. REDCap's recurring-ASI feature covers this: it
 * requires the event/form to be repeating (`Classes/SurveyScheduler.php:1359-1362`) and allocates
 * the next instance for each recurrence (`:1440`). `redcap_events_repeat` must already carry
 * (1098, 'sunday') - apply-weekly-sms-branching.php's sibling step, done by hand on 268.
 *
 * WHY `delivery_type = EMAIL` ON AN SMS FEATURE. It is not an email. The Enhanced SMS Conversation
 * module hooks `redcap_email`, greps the subject for `@ESMS`, returns false so the mail is never
 * sent, and runs the instrument as a text conversation over its own Twilio credentials
 * (`EnhancedSMSConversation.php:67-69`, `classes/TwilioManager.php:30-32`). The project's own
 * `twilio_*` columns play no part.
 *
 * WHY `reeval_before_send = 1` IS NOT OPTIONAL. `SurveyScheduler.php:2226` only treats an ASI as
 * repeating when `num_recurrence > 0` AND `reeval_before_send = '1'` AND `condition_logic != ''`.
 * Drop any one of the three and you get a single invitation instead of twelve. It also buys the
 * self-cancelling behaviour: the condition is re-evaluated at send time, so a withdrawal or an SMS
 * opt-out part-way through the cadence stops the remaining weeks.
 *
 * TIMING. `first_monday_1200` is the Monday noon after randomization; ASPIRE sends Sunday, which is
 * that anchor + 6 days, then every 7 days for 12 weeks. The anchor is read cross-event from
 * `day_1_ed_arm_1` because `admin` is where the CRC fills it and arm 1's Day-1 event is MICA's
 * enrollment event for every participant regardless of arm.
 */

$pid      = (int) ($argv[1] ?? 268);
$activate = in_array('--activate', array_slice($argv, 1), true);

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

const FORM        = 'sunday';
const WEEKS_EVENT = 'weeks_112_arm_3';
const ENROL_EVENT = 'day_1_ed_arm_1';
const WEEKS       = 12;

$Proj = new Project($pid, true);

echo "\n=== weekly-SMS ASI for project $pid ===\n";

// ---- prerequisites -------------------------------------------------------------------------
$eventIds = array_flip(\REDCap::getEventNames(true, false));
$eventId  = $eventIds[WEEKS_EVENT] ?? null;
if (!$eventId) { fwrite(STDERR, "  [!!] no event named " . WEEKS_EVENT . "\n"); exit(1); }

$surveyId = null;
foreach ($Proj->surveys as $sid => $s) if ($s['form_name'] === FORM) $surveyId = $sid;
if (!$surveyId) { fwrite(STDERR, "  [!!] `" . FORM . "` is not enabled as a survey\n"); exit(1); }

if (!$Proj->isRepeatingForm($eventId, FORM)) {
    fwrite(STDERR, "  [!!] `" . FORM . "` is not repeating at event $eventId. A recurring ASI needs a\n"
                 . "       redcap_events_repeat row (SurveyScheduler.php:1359-1362). Aborting.\n");
    exit(1);
}
printf("  survey_id %s, event_id %s (%s), repeating: yes\n", $surveyId, $eventId, WEEKS_EVENT);

// ---- the definition ------------------------------------------------------------------------
$e = '[' . ENROL_EVENT . ']';

// calc_esms_valid already requires dummy_email / randomization_date / phonen / study_group / s_sex
// and not-withdrawn / not-opted-out. The two suppression clauses are repeated explicitly so the
// send-time re-evaluation still cancels the remaining weeks even if the calc has not been
// refreshed on the record yet.
// Built by concatenation, not interpolation: "$e[sms_stop(1)]" inside a double-quoted string is
// parsed by PHP as an array subscript and the `(1)` is a syntax error.
$condition = '(' . $e . "[calc_esms_valid] = '1') and"
    . "\n('1' <> " . $e . '[study_withdrawn(1)]) and'
    . "\n('1' <> " . $e . '[sms_stop(1)]) and'
    . "\n(" . $e . "[study_group] = '3')";

if (!\LogicTester::isValid($condition)) {
    fwrite(STDERR, "  [!!] condition is not valid REDCap logic:\n$condition\n"); exit(1);
}

$subject = '@ESMS MICA weekly check-in';
$body    = "<p>This invitation is delivered as an SMS conversation by the Enhanced SMS "
         . "Conversation module; the subject tag @ESMS is what routes it. If you are reading this "
         . "as an email, the module did not pick it up.</p><p>[survey-link]</p>";

$fields = [
    'survey_id'                        => $surveyId,
    'event_id'                         => $eventId,
    'instance'                         => 'FIRST',
    'num_recurrence'                   => 7,
    'units_recurrence'                 => 'DAYS',
    'max_recurrence'                   => WEEKS,
    'active'                           => $activate ? 1 : 0,
    'email_subject'                    => $subject,
    'email_content'                    => $body,
    'email_sender'                     => 'micastudy@stanford.edu',
    'email_sender_display'             => 'MICA Study',
    // MUST be 'AND'. `condition_andor` is nullable, and a NULL silently disables the whole ASI:
    // checkConditionsOfRecordToSchedule() seeds both pass-flags from `== 'AND'`, so on NULL they
    // start false, the guard around evaluateLogicSingleRecord() is never entered - the condition is
    // not even evaluated - and the function returns `false && false`
    // (SurveyScheduler.php:2053, 2069-2074, 2099). Nothing queues and nothing is logged.
    // ASPIRE's twelve reference ASIs all carry 'AND'.
    'condition_andor'                  => 'AND',
    'condition_logic'                  => $condition,
    'condition_send_time_option'       => 'TIME_LAG',
    'condition_send_time_lag_days'     => 6,
    'condition_send_time_lag_hours'    => 0,
    'condition_send_time_lag_minutes'  => 0,
    'condition_send_time_lag_field'    => $e . '[first_monday_1200]',
    'condition_send_time_lag_field_after' => 'after',
    'condition_reevaluate_send_time'   => 0,
    'reminder_num'                     => 0,
    'delivery_type'                    => 'EMAIL',   // hijacked by @ESMS - see the docblock
    'reeval_before_send'               => 1,
];

// ---- upsert --------------------------------------------------------------------------------
$existing = db_result(db_query(
    "select ss_id from redcap_surveys_scheduler where survey_id = $surveyId and event_id = $eventId"), 0);

$cols = array_keys($fields);
$vals = array_values($fields);

if ($existing) {
    $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
    db_query("update redcap_surveys_scheduler set $set where ss_id = $existing", $vals);
    $ssId = $existing;
    echo "  updated existing ASI ss_id $ssId\n";
} else {
    $ph = implode(', ', array_fill(0, count($cols), '?'));
    db_query("insert into redcap_surveys_scheduler (" . implode(', ', $cols) . ") values ($ph)", $vals);
    $ssId = db_insert_id();
    echo "  created ASI ss_id $ssId\n";
}

// ---- report --------------------------------------------------------------------------------
$row = db_fetch_assoc(db_query(
    "select active, delivery_type, email_subject, num_recurrence, units_recurrence, max_recurrence,
            condition_send_time_option, condition_send_time_lag_days, condition_send_time_lag_field,
            reeval_before_send
       from redcap_surveys_scheduler where ss_id = $ssId"));

echo "\n--- as stored ---\n";
foreach ($row as $k => $v) printf("  %-32s %s\n", $k, $v);
printf("  %-32s %s\n", 'condition', str_replace("\n", ' ', $condition));

echo "\n  " . ($row['active'] ? '*** ACTIVE - this will send to a real handset ***'
                              : 'INACTIVE - re-run with --activate when ready to send') . "\n";
echo "\n*** DONE ***\n";
