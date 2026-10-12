#!/bin/sh
# =====================================================================
# MICA PID 257 -- drive one row of the channel-split matrix (Tests 2+3)
# =====================================================================
#   ./run_channel_row.sh <label> <choice___1> <choice___2>
#
#   ./run_channel_row.sh A 1 0    # Email only, anchor today-6 -> nothing sent
#   ./run_channel_row.sh B 1 0    # Email only  -> alert 02 (email)
#   ./run_channel_row.sh C 0 1    # SMS only    -> alert 03 (SMS)
#   ./run_channel_row.sh D 1 1    # both ticked -> alert 03 only (SMS wins)
#   ./run_channel_row.sh E 0 0    # neither     -> alert 03 only (SMS fallback)
#
# NOTE: rows C/D/E send a REAL SMS via live Twilio. LOCALHOST ONLY --
# never point this at the Stanford server.
#
# Row A needs randomization_date = today-6; this script hardcodes today-7
# (2026-08-20), so edit the date below for the negative control.
#
# Two things this script gets right that a hand-run sequence won't:
#  * it clears alert 02's redcap_alerts_sent row, so "02 did not send" is
#    attributable to the CONDITION and not to unique-key suppression;
#  * it clears the 60s AlertsNotificationsSender frequency gate, which
#    otherwise silently skips the send and mimics a failure.
# =====================================================================
# $1 = row label, $2 = choice___1, $3 = choice___2
ROW="$1"; C1="$2"; C2="$3"
echo "############ ROW $ROW : choice_fup_delivery___1=$C1  ___2=$C2 ############"

# 1. RESET -- record 3 @ 1008 only. Alert 01 @ 1004 must survive.
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
DELETE l FROM redcap_alerts_sent_log l
  JOIN redcap_alerts_sent s ON s.alert_sent_id=l.alert_sent_id
  JOIN redcap_alerts a ON a.alert_id=s.alert_id
 WHERE a.project_id=257 AND s.record='3' AND s.event_id=1008;
DELETE s FROM redcap_alerts_sent s JOIN redcap_alerts a ON a.alert_id=s.alert_id
 WHERE a.project_id=257 AND s.record='3' AND s.event_id=1008;
DELETE r FROM redcap_alerts_recurrence r JOIN redcap_alerts a ON a.alert_id=r.alert_id
 WHERE a.project_id=257 AND r.record='3' AND r.event_id=1008;" 2>/dev/null

# 2. SAVE (triggers alert evaluation)
docker exec redcap_2023_1_web php -r "
define('NOAUTH',true); \$_GET['pid']=257;
require_once '/var/www/html/redcap_v17.2.3/Config/init_project.php';
\$r = REDCap::saveData(257,'array',['3'=>[1008=>[
  'randomization_date'=>'2026-08-20',
  'choice_fup_delivery___1'=>'$C1',
  'choice_fup_delivery___2'=>'$C2',
]]],'overwrite');
echo 'save errors: ', (empty(\$r['errors'])?'none':json_encode(\$r['errors'])), \"\n\";
" 2>/dev/null

# 3. QUEUE STATE before cron
echo "--- QUEUED (which conditions matched) ---"
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
SELECT r.alert_id, LEFT(a.alert_title,42) AS title, a.alert_type AS typ,
       r.first_send_time, IF(r.first_send_time<=NOW(),'DUE','not due') AS v
  FROM redcap_alerts_recurrence r JOIN redcap_alerts a ON a.alert_id=r.alert_id
 WHERE a.project_id=257 AND r.record='3' AND r.event_id=1008;" 2>/dev/null

# 4. CRON -- clear the 60s frequency gate first so each row is deterministic,
#    not dependent on how long ago the previous row ran.
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
UPDATE redcap_crons SET cron_last_run_start = DATE_SUB(NOW(), INTERVAL 1 HOUR),
                        cron_last_run_end   = DATE_SUB(NOW(), INTERVAL 1 HOUR)
 WHERE cron_name IN ('AlertsNotificationsSender','AlertsNotificationsDatediffChecker2');" 2>/dev/null
docker exec redcap_2023_1_cron wget -q -O- http://web/cron.php >/dev/null 2>&1

# 5. RESULT
echo "--- SENT for 3@1008 ---"
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
SELECT s.alert_id, LEFT(a.alert_title,42) AS title, l.alert_type AS typ, s.last_sent
  FROM redcap_alerts_sent s JOIN redcap_alerts a ON a.alert_id=s.alert_id
  LEFT JOIN redcap_alerts_sent_log l ON l.alert_sent_id=s.alert_sent_id
 WHERE a.project_id=257 AND s.record='3' AND s.event_id=1008;" 2>/dev/null
echo "--- ACTUAL DELIVERY ---"
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
SELECT email_id, type, time_sent, recipients FROM redcap_outgoing_email_sms_log
 WHERE project_id=257 ORDER BY email_id DESC LIMIT 2;" 2>/dev/null
echo "--- Test 1 evidence still present? (expect 5649 @ 1004) ---"
docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "
SELECT alert_id, record, event_id FROM redcap_alerts_sent WHERE record='3' AND event_id=1004;" 2>/dev/null
echo
