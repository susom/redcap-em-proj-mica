-- =====================================================================
-- MICA PID 257 — alert testing helpers (localhost only)
-- =====================================================================
-- Run any block with:
--   docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -e "<SQL>"
--
-- Or open a shell and paste:
--   docker exec -it redcap_2023_1_db mysql -uredcap -predcap123 redcap
--
-- NEVER run these against the Stanford server.


-- ---------------------------------------------------------------------
-- W1. THE WATCH QUERY — run this after every step
-- ---------------------------------------------------------------------
-- Three separate things, and the difference between them is the whole game:
--   queue  = the condition matched and a send is scheduled  (redcap_alerts_recurrence)
--   sent   = a send SUCCEEDED                               (redcap_alerts_sent)
--   nothing in either = condition never matched, OR the send failed
--     (a failed email writes NOTHING: Alerts.php:1515 only logs inside the
--      success branch, and Message.php:974 deletes the email-log row on failure)

-- ⚠ Read first_send_time, NOT next_send_time. `next_send_time` is NULL while a row
-- sits IDLE and is only populated when the cron flips it to SENDING
-- (Alerts.php:1006). The due-ness test the cron actually runs (Alerts.php:996) is,
-- for cron_repeat_for=0, simply `first_send_time <= NOW()`. Sorting or judging by
-- next_send_time makes a correctly-scheduled alert look unscheduled.
SELECT '--- QUEUED ---' AS x;
SELECT r.alert_id, LEFT(a.alert_title,40) AS title, a.alert_type AS typ, r.record,
       r.event_id, r.status, r.times_sent, r.first_send_time,
       IF(r.first_send_time <= NOW(),'DUE NOW','not due yet') AS verdict
  FROM redcap_alerts_recurrence r JOIN redcap_alerts a ON a.alert_id=r.alert_id
 WHERE a.project_id=257 ORDER BY r.first_send_time;

SELECT '--- SENT ---' AS x;
SELECT s.alert_id, LEFT(a.alert_title,40) AS title, l.alert_type AS typ, s.record,
       s.event_id, s.last_sent, IFNULL(l.email_to,'') AS to_email,
       LEFT(IFNULL(l.subject,''),34) AS subj
  FROM redcap_alerts_sent s JOIN redcap_alerts a ON a.alert_id=s.alert_id
  LEFT JOIN redcap_alerts_sent_log l ON l.alert_sent_id=s.alert_sent_id
 WHERE a.project_id=257 ORDER BY s.last_sent DESC;

-- ⚠ DO NOT read the recipient from redcap_alerts_sent_log.phone_number_to.
-- addRecordSent() (Alerts.php:1833) copies that column straight from the ALERT
-- DEFINITION, unpiped, so it always reads the literal '[phonen]'. It is NOT
-- evidence the piping failed. The real recipient (piped at Alerts.php:4442)
-- is only in redcap_outgoing_email_sms_log:
SELECT '--- ACTUAL RECIPIENTS (the authoritative view) ---' AS x;
SELECT email_id, type, category, time_sent, sender, recipients,
       LEFT(IFNULL(email_subject,''),30) AS subj
  FROM redcap_outgoing_email_sms_log
 WHERE project_id=257 ORDER BY email_id DESC LIMIT 10;

SELECT '--- FULL BODY OF THE MOST RECENT SEND (check piping resolved) ---' AS x;
SELECT l.alert_type, l.email_to, l.phone_number_to, l.subject, l.message
  FROM redcap_alerts_sent_log l JOIN redcap_alerts_sent s ON s.alert_sent_id=l.alert_sent_id
  JOIN redcap_alerts a ON a.alert_id=s.alert_id
 WHERE a.project_id=257 ORDER BY l.alert_sent_log_id DESC LIMIT 1;


-- ---------------------------------------------------------------------
-- S1. SAFETY — deactivate every alert (do this before controlled testing)
-- ---------------------------------------------------------------------
UPDATE redcap_alerts SET email_deleted=1 WHERE project_id=257;

-- S2. Activate ONE alert by title prefix, e.g. '01 '
UPDATE redcap_alerts SET email_deleted=0 WHERE project_id=257 AND alert_title LIKE '01 %';

-- S3. What is active right now?
SELECT alert_id, LEFT(alert_title,50) AS title, alert_type,
       IF(email_deleted=1,'deactivated','** ACTIVE **') AS state
  FROM redcap_alerts WHERE project_id=257 ORDER BY alert_order;

-- S4. DONE 2026-08-27: duplicate alert 5670 deleted. Kept for reference.
--     Removes the duplicate created by the UI "Copy alert" button
--     (5670 is byte-identical to 5668). Confirm the pair first, then delete.
SELECT alert_id, alert_order, alert_title FROM redcap_alerts
 WHERE project_id=257 AND alert_title LIKE '20 CRC%';
-- DELETE FROM redcap_alerts WHERE alert_id=5670;


-- ---------------------------------------------------------------------
-- T1. TIME TRAVEL — the only practical way to test a +7/+14/+92/+105 ladder
-- ---------------------------------------------------------------------
-- Every scheduled alert is TIME_LAG off admin.randomization_date. Backdate that
-- field and the send becomes due immediately.
--
-- ORDER MATTERS. reevaluate_send_time = 0 on all these alerts, so the send time
-- is computed ONCE, when the logic first evaluates true. Changing
-- randomization_date afterwards does NOT reschedule an already-queued row.
-- So: (1) clear the queue, (2) backdate, (3) re-save a form to re-evaluate.

-- (1) clear the queue for PID 257
DELETE r FROM redcap_alerts_recurrence r
  JOIN redcap_alerts a ON a.alert_id=r.alert_id WHERE a.project_id=257;

-- (2) backdate randomization_date. Set @rec / @ev / @days first.
--     @days: 7 = ED nudge due, 14 = ED escalation, 92/98/105 = booster rungs.
--     @ev is the record's Day 1 event: 1004 arm1 / 1008 arm2 / 1012 arm3.
SET @rec='TEST01', @ev=1008, @days=7;
UPDATE redcap_data SET value = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL @days DAY), '%Y-%m-%d')
 WHERE project_id=257 AND record=@rec AND event_id=@ev AND field_name='randomization_date';

-- (3) then re-save ANY form on that record in the UI so the logic re-evaluates,
--     and wait ~70s (or force the cron — see the C1 note in TEST_PLAN_LOCALHOST.md).

-- T2. Check what the anchor currently is
SELECT record, event_id, field_name, value FROM redcap_data
 WHERE project_id=257 AND field_name IN ('randomization_date','study_group','calcrnd','phonen','email','choice_fup_delivery')
 ORDER BY record, event_id, field_name;


-- ---------------------------------------------------------------------
-- R1. RESET between test runs (keeps the record, forgets the sends)
-- ---------------------------------------------------------------------
-- redcap_alerts_sent has a unique key per (alert, record, event, instrument,
-- instance), and RECORD-scoped alerts will never re-send while that row exists.
-- Clear all three to make a record "fresh" again.
--
-- ⚠ ALWAYS scope this to ONE record AND ONE event. An unqualified reset wipes the
-- evidence that earlier tests passed — record 3's Test 1 SMS row (alert_sent_id
-- 132, event 1004) is the only proof the passcode flow worked, and record 3 now
-- ALSO holds arm-2 data at event 1008. A record-only scope would delete it.
--
-- @ev: 1004 arm1 Day1 / 1008 arm2 Day1 / 1012 arm3 Day1.
SET @rec='3', @ev=1008;

DELETE l FROM redcap_alerts_sent_log l
  JOIN redcap_alerts_sent s ON s.alert_sent_id=l.alert_sent_id
  JOIN redcap_alerts a ON a.alert_id=s.alert_id
 WHERE a.project_id=257 AND s.record=@rec AND s.event_id=@ev;
DELETE s FROM redcap_alerts_sent s
  JOIN redcap_alerts a ON a.alert_id=s.alert_id
 WHERE a.project_id=257 AND s.record=@rec AND s.event_id=@ev;
DELETE r FROM redcap_alerts_recurrence r
  JOIN redcap_alerts a ON a.alert_id=r.alert_id
 WHERE a.project_id=257 AND r.record=@rec AND r.event_id=@ev;

-- Confirm Test 1's row survived (must still return alert_sent_id 132):
SELECT alert_sent_id, alert_id, record, event_id, last_sent
  FROM redcap_alerts_sent WHERE record='3' AND event_id=1004;

-- email_sent / email_timestamp_sent are per-ALERT, not per-record, so they
-- cannot be scoped. They are only used for display, not for send suppression,
-- so leaving them alone is safe -- and safer than a blanket UPDATE.


-- ---------------------------------------------------------------------
-- D1. DIAGNOSTICS when an alert did not fire
-- ---------------------------------------------------------------------
-- The gates most likely to be the reason, all in one row per record/event:
SELECT d.record, d.event_id,
  MAX(IF(d.field_name='consent_complete',       d.value,NULL)) AS consent,
  MAX(IF(d.field_name='baseline1_complete',     d.value,NULL)) AS baseline1,
  MAX(IF(d.field_name='close_complete',         d.value,NULL)) AS close_,
  MAX(IF(d.field_name='randomization_date',     d.value,NULL)) AS rand_date,
  MAX(IF(d.field_name='study_group',            d.value,NULL)) AS grp,
  MAX(IF(d.field_name='phonen',                 d.value,NULL)) AS phone,
  MAX(IF(d.field_name='email',                  d.value,NULL)) AS email,
  MAX(IF(d.field_name='calcrnd',                d.value,NULL)) AS calcrnd,
  MAX(IF(d.field_name='mica_ed_session_complete',     d.value,NULL)) AS ed_sess,
  MAX(IF(d.field_name='mica_booster_session_complete',d.value,NULL)) AS boost_sess,
  MAX(IF(d.field_name='study_withdrawn',        d.value,NULL)) AS withdrawn,
  MAX(IF(d.field_name='sms_stop',               d.value,NULL)) AS sms_stop
 FROM redcap_data d WHERE d.project_id=257
 GROUP BY d.record, d.event_id ORDER BY d.record, d.event_id;

-- Remember: EVERY gate is written against '2' (Complete). A form left at
-- '1' (Unverified) will silently satisfy nothing. Records 1 and 2 both sit at
-- baseline1_complete='1', which is why they look like broken alerts.

-- Twilio failures (SMS never arrives but the alert says sent):
SELECT * FROM redcap_twilio_error_log ORDER BY 1 DESC LIMIT 10;

-- Outgoing email/SMS log. NOTE: rows are DELETED on send failure
-- (Message.php:974), so "no row" does not distinguish "never tried" from "failed".
SELECT email_id, type, category, time_sent, sender, recipients, LEFT(email_subject,40) AS subj
  FROM redcap_outgoing_email_sms_log WHERE project_id=257 ORDER BY email_id DESC LIMIT 15;
