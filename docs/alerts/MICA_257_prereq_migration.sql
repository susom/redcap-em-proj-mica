-- =====================================================================
-- MICA (PID 257) -- prerequisites for the Alerts & Notifications build
-- =====================================================================
--
-- Run BEFORE importing docs/alerts/MICA_257_alerts_import.csv.
-- Six changes, all approved 2026-08-26. None of them travel with an alert
-- export, so they must be re-applied by hand on the Stanford server -- see the
-- production-replication checklist in docs/ALERTS_AUDIT_257_vs_262.md.
--
--   P1  twilio_modules_enabled -> SURVEYS_ALERTS   (unblocks alert_type='SMS')
--   P2  fix the calcrnd formula                    (8-digit -> 4-digit passcode)
--   P3  add the missing dummy_email field          (unbreaks SMS survey ASIs)
--   P4  "ASPIRE study"  -> "MICA study"            (participant-facing text)
--   P5  "TRAM trial"    -> "MICA study"            (participant-facing text)
--   P6  map sms_code_check onto the Day 1 events
--
-- Apply with:
--   docker exec -i redcap_2023_1_db mysql -uredcap -predcap123 redcap \
--     < MICA_257_prereq_migration.sql
--
-- PID 257 is status=0 (development), so metadata edits land directly in
-- redcap_metadata and no draft-mode reconciliation is required. On a project in
-- production these same edits MUST go through Draft Mode / the Online Designer
-- instead of raw SQL.

START TRANSACTION;

-- ---------------------------------------------------------------- P1
-- redcap_projects.twilio_modules_enabled is ENUM('SURVEYS','ALERTS','SURVEYS_ALERTS').
-- It is currently 'SURVEYS', which permits Twilio for surveys only. alert_type
-- accepting 'SMS' at the schema level is a separate gate from the project being
-- allowed to send it, so alert 01 (and 03/05/08/10) cannot deliver until this flips.
UPDATE redcap_projects
   SET twilio_modules_enabled = 'SURVEYS_ALERTS'
 WHERE project_id = 257;

-- ---------------------------------------------------------------- P2
-- calcrnd was `rounddown(([rnd] * 10000 * 0.8999), 0) + 1000`, which assumes
-- [rnd] is a float in [0,1) -- true for ASPIRE's intended `Math.random()`, which
-- REDCap rejected as invalid calc syntax and commented out. MICA replaced [rnd]
-- with `if([rnd]='', random(1000,9999), [rnd])`, a valid 4-digit INTEGER, so the
-- old multiplication now yields 8 digits (observed: 27798911, 80335073).
-- [rnd] is already exactly the wanted range (1000-9999, no leading zero).
UPDATE redcap_metadata
   SET element_enum = '[rnd]'
 WHERE project_id = 257
   AND field_name = 'calcrnd'
   AND element_type = 'calc';

-- ---------------------------------------------------------------- P3
-- The sms_code_check and sunday surveys both declare
-- email_participant_field='dummy_email', but no such field exists in 257, so
-- neither survey can resolve a recipient and arm 3's weekly SMS is dead.
-- Row copied from ASPIRE's check.dummy_email; placed on baseline1 next to
-- email/phonen (MICA's analogue of ASPIRE's `check` form).
UPDATE redcap_metadata
   SET field_order = field_order + 1
 WHERE project_id = 257
   AND field_order >= 64;

INSERT INTO redcap_metadata
  (project_id, field_name, form_name, field_order, element_type, element_label,
   element_note, element_validation_type, element_validation_checktype,
   field_req, edoc_display_img, grid_rank, misc, video_display_inline)
VALUES
  (257, 'dummy_email', 'baseline1', 64, 'text',
   'This dummy email field is used so ASI''s trigger',
   'Used for SMS ASI alerts as required email field',
   'email', 'soft_typed', 0, 0, 0,
   '@DEFAULT="noreply@stanford.edu" @HIDDEN-SURVEY', 0);

-- ---------------------------------------------------------------- P4 / P5
-- Copy-paste leftovers from the two projects MICA was cloned through. Both are
-- participant-facing: P4 is the body of the passcode SMS survey, P5 is what a
-- participant sees after texting STOP.
UPDATE redcap_metadata
   SET element_label = 'Your passcode for the MICA study is [calcrnd].'
 WHERE project_id = 257
   AND field_name = 'desc_sms_code_check';

UPDATE redcap_metadata
   SET element_label = 'OK. You will no longer receive texts from the MICA study.'
 WHERE project_id = 257
   AND field_name = 'desc_sms_optout';

-- ---------------------------------------------------------------- P6
-- sms_code_check sat only on event 1013 (Weeks 1-12, arm 3), but the phone check
-- belongs at Day 1 for every arm -- and calcrnd/phonen live on baseline1, which
-- is a Day 1 instrument. Add the three Day 1 events; 1013 is left in place so
-- arm 3 can re-verify a phone mid-study.
INSERT INTO redcap_events_forms (event_id, form_name)
SELECT e.event_id, 'sms_code_check'
  FROM redcap_events_metadata e
  JOIN redcap_events_arms a ON a.arm_id = e.arm_id
 WHERE a.project_id = 257
   AND e.event_id IN (1004, 1008, 1012)
   AND NOT EXISTS (
        SELECT 1 FROM redcap_events_forms f
         WHERE f.event_id = e.event_id AND f.form_name = 'sms_code_check');

-- ---------------------------------------------------------------- P7
-- baseline1.phonen had NO validation type in MICA (element_validation_type NULL)
-- while ASPIRE's check.phonen is validated as 'phone'. A clone regression.
--
-- This is not cosmetic. Alerts::getPhoneFieldsList() builds the legal
-- phone-number-to whitelist from fields whose validation data_type is 'phone'
-- (plus 'int'), so an unvalidated phonen cannot be an SMS recipient at all --
-- the alert importer rejects [phonen] with "Please either correct or remove any
-- invalid phone numbers." Every SMS alert in this build depends on it.
UPDATE redcap_metadata
   SET element_validation_type = 'phone',
       element_validation_checktype = 'soft_typed'
 WHERE project_id = 257
   AND field_name = 'phonen';

-- ---------------------------------------------------------------- P8
-- The `close` form -- MICA's end-of-baseline / end-of-follow-up marker -- had all
-- four of its descriptives branched on ASPIRE event names, so it rendered as a
-- completely BLANK survey at every Day 1 event, and for arms 2 & 3 at every
-- follow-up event too:
--
--   desc_baseline_end  ->  [event-name]='baseline_arm_1'   <- no such event in MICA
--   desc_3m_end        ->  [event-name]='month_3_arm_1'    <- arm 1 only
--   desc_6m_end        ->  [event-name]='month_6_arm_1'    <- arm 1 only
--   desc_12m_end       ->  [event-name]='month_12_arm_1'   <- arm 1 only
--
-- Rewritten against MICA's real event names across all arms (PI direction
-- 2026-08-27: "MICA has correct events, no need to match ASPIRE"). This is what
-- shows the participant "You have completed the baseline assessment", and it is
-- also the instrument alert 01 and alerts 18-20 key off.
UPDATE redcap_metadata SET branching_logic =
  "[event-name]='day_1_ed_arm_1' or [event-name]='day_1_ed_arm_2' or [event-name]='day_1_ed_arm_3'"
 WHERE project_id = 257 AND field_name = 'desc_baseline_end';

UPDATE redcap_metadata SET branching_logic =
  "[event-name]='month_3_arm_1' or [event-name]='month_3_arm_2' or [event-name]='month_3_arm_3'"
 WHERE project_id = 257 AND field_name = 'desc_3m_end';

UPDATE redcap_metadata SET branching_logic =
  "[event-name]='month_6_arm_1' or [event-name]='month_6_arm_2' or [event-name]='month_6_arm_3'"
 WHERE project_id = 257 AND field_name = 'desc_6m_end';

UPDATE redcap_metadata SET branching_logic =
  "[event-name]='month_12_arm_1' or [event-name]='month_12_arm_2' or [event-name]='month_12_arm_3'"
 WHERE project_id = 257 AND field_name = 'desc_12m_end';

-- ---------------------------------------------------------------- P9
-- `calc_esms_valid` is element_type='calc' with a NULL equation. REDCap tolerates
-- that in the DB but the Data Dictionary importer rejects the whole file:
--   "Each 'calc' field must have an equation in column F ... F224"
-- ASPIRE has a real formula here; MICA lost it in the clone. Restored, with
-- ASPIRE's [group] mapped to MICA's [study_group]. Every referenced field lives
-- on a Day 1 event, same as calc_esms_valid itself, so no event prefixes needed.
--
-- Nothing in the alert build depends on this field (the build deliberately gates
-- on real instrument-status fields instead -- see the audit). It is restored
-- because an empty calc blocks every future DD import, and because arm 3's weekly
-- `sunday` SMS will need exactly this gate if ASIs are added later.
UPDATE redcap_metadata SET element_enum =
  "if([dummy_email] <> '' AND [randomization_date] <> '' AND [phonen] <> '' AND [study_group] <> '' AND [birth_sex] <> '' AND [study_withdrawn(1)] = 0 AND [sms_stop(1)] = 0, 1, 0)"
 WHERE project_id = 257 AND field_name = 'calc_esms_valid';

-- ---------------------------------------------------------------- P10
-- Pre-existing DATA CORRUPTION, not a display artifact. A past Data Dictionary
-- import split this field note on its embedded comma and shifted the tail one
-- column left, into element_validation_type:
--   element_note            = 'As above: a recommendation'
--   element_validation_type = ' not a notification.'
-- A `notes` field carrying a validation type makes the DD importer reject the file:
--   "Only 'text' field types may have validation ... H255"
-- Verified as the only instance -- every other non-null validation_type in the
-- project is a real validation name.
UPDATE redcap_metadata
   SET element_note = 'As above: a recommendation, not a notification.',
       element_validation_type = NULL
 WHERE project_id = 257 AND field_name = 'finding_rec_targets_json';

COMMIT;

-- =====================================================================
-- POST-MIGRATION, NOT COVERED BY THIS SCRIPT
-- =====================================================================
-- 1. P2 changes the FORMULA only. The three existing test records still hold
--    the old 8-digit calcrnd values. Recompute them via
--    Data Quality -> rule H ("Incorrect values for calculated fields")
--    -> Fix all, or by re-saving each baseline1 form.
--
-- 2. Both existing test records have baseline1_complete = 1 (Unverified), not 2.
--    Every gate below is written against '2' (Complete), so nothing will fire on
--    the current test data until those are set to Complete. This looks exactly
--    like a broken alert; it is not.
--
-- 3. Test-data caveat: record 1 holds baseline1/admin data at BOTH event 1004
--    (arm 1 Day 1) and 1008 (arm 2 Day 1), including two different calcrnd
--    values. A real record lives in one arm, so this is a fixture artifact --
--    but it will make [event-name]-pinned conditions look inconsistent while
--    testing. Prefer a freshly created single-arm record for end-to-end runs.

-- =====================================================================
-- VERIFICATION (run after COMMIT)
-- =====================================================================
-- SELECT project_id, twilio_modules_enabled FROM redcap_projects WHERE project_id=257;
-- SELECT field_name, element_enum FROM redcap_metadata WHERE project_id=257 AND field_name='calcrnd';
-- SELECT field_order, form_name, field_name FROM redcap_metadata WHERE project_id=257 AND field_order BETWEEN 62 AND 68 ORDER BY field_order;
-- SELECT field_name, element_label FROM redcap_metadata WHERE project_id=257 AND field_name IN ('desc_sms_code_check','desc_sms_optout');
-- SELECT event_id, form_name FROM redcap_events_forms WHERE form_name='sms_code_check' ORDER BY event_id;
-- -- field_order must remain a gapless 1..N sequence:
-- SELECT COUNT(*) AS n, MIN(field_order) AS lo, MAX(field_order) AS hi, COUNT(DISTINCT field_order) AS distinct_orders
-- FROM redcap_metadata WHERE project_id=257;

-- =====================================================================
-- ROLLBACK
-- =====================================================================
-- UPDATE redcap_projects SET twilio_modules_enabled='SURVEYS' WHERE project_id=257;
-- UPDATE redcap_metadata SET element_enum='rounddown(([rnd] * 10000 * 0.8999), 0) + 1000'
--   WHERE project_id=257 AND field_name='calcrnd';
-- DELETE FROM redcap_events_forms WHERE form_name='sms_code_check' AND event_id IN (1004,1008,1012);
-- DELETE FROM redcap_metadata WHERE project_id=257 AND field_name='dummy_email';
-- UPDATE redcap_metadata SET field_order=field_order-1 WHERE project_id=257 AND field_order>=65;
-- UPDATE redcap_metadata SET element_label='Your passcode for the ASPIRE study is [calcrnd].'
--   WHERE project_id=257 AND field_name='desc_sms_code_check';
-- UPDATE redcap_metadata SET element_label='OK. You will no longer receive texts from the TRAM trial.'
--   WHERE project_id=257 AND field_name='desc_sms_optout';
-- UPDATE redcap_metadata SET element_validation_type=NULL WHERE project_id=257 AND field_name='phonen';
-- -- and to remove the 23 alerts this build created:
-- DELETE FROM redcap_alerts WHERE project_id=257;
