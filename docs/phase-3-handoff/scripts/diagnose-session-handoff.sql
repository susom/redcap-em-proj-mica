-- Why is a participant seeing "Someone from the study team will be with you shortly to continue."?
--
--   mysql --default-character-set=utf8mb4 -u… -p… redcap --table < diagnose-session-handoff.sql
--
-- READ-ONLY. Every statement is a SELECT (plus PREPARE/EXECUTE, which only reads). Safe on prod.
--
-- This is the no-deployment twin of diagnose-session-handoff.php, for a REDCap admin who can run
-- SQL but cannot drop a PHP file on the server. The PHP version says more about a single record;
-- this one finds the cause, which is almost always section 3.
--
-- THE MESSAGE IS NOT AN ERROR. It is pages/sessionHandoff.php with state=pending, which MICA.php:908
-- writes into `ed_session_url` whenever EdSessionLink::resolve() cannot find a session - in
-- practice, whenever the record has no usable `study_group`. Before that page existed the same
-- condition produced redirect('') and a blank screen, so a deploy that introduces the message is
-- surfacing an older problem, not causing one.

SET @pid = 35968;   -- <<< CHANGE ME

-- ---------------------------------------------------------------------------------------------
SELECT '1. PROJECT' AS section;
SELECT project_id, app_title,
       CASE status WHEN 0 THEN 'Development' WHEN 1 THEN 'Production'
                   WHEN 2 THEN 'Inactive'    WHEN 3 THEN 'Completed' END AS project_status,
       randomization AS randomization_enabled
FROM redcap_projects WHERE project_id = @pid;

-- ---------------------------------------------------------------------------------------------
SELECT '2. RANDOMIZATION SETUP' AS section;
SELECT rid, target_field, target_event, trigger_option, trigger_instrument, trigger_logic,
       group_by AS dag_grouped,
       CONCAT_WS(',', source_field1, source_field2, source_field3) AS stratified_by
FROM redcap_randomization WHERE project_id = @pid;

-- ---------------------------------------------------------------------------------------------
-- REDCap only ever reads allocations whose project_status matches the project's CURRENT status:
--     Randomization.php:2173   inner join redcap_projects p on ra.project_status = p.status
-- So a project promoted Development -> Production stops seeing every allocation uploaded while it
-- was in Development. If no production table was uploaded, it randomizes nobody, silently.
SELECT '3. ALLOCATIONS  <-- the answer is usually here' AS section;
SELECT CASE a.project_status WHEN 0 THEN 'Development' WHEN 1 THEN 'Production' END AS belongs_to,
       COUNT(*)                  AS rows_total,
       SUM(a.is_used_by IS NULL) AS unused,
       IF(a.project_status = (SELECT status FROM redcap_projects WHERE project_id = @pid),
          '<<< REDCap reads THIS set', 'invisible to REDCap right now') AS in_use
FROM redcap_randomization_allocation a
JOIN redcap_randomization r ON r.rid = a.rid
WHERE r.project_id = @pid
GROUP BY a.project_status;
-- No rows at all above  -> no allocation table has ever been uploaded.
-- Rows only under the OTHER status -> upload one while the project is in its current status.
-- unused = 0                       -> exhausted; every new participant fails from here on.

-- ---------------------------------------------------------------------------------------------
SELECT '4. HOW MANY PARTICIPANTS ARE STUCK' AS section;
-- The data table differs per project (redcap_data, redcap_data7, …). Resolve it rather than
-- hardcoding: querying the wrong table reports zeros, which reads like good news.
SELECT data_table   INTO @dt FROM redcap_projects      WHERE project_id = @pid;
SELECT target_field INTO @tf FROM redcap_randomization WHERE project_id = @pid;
SELECT CONCAT('data table = ', @dt, ' , target field = ', @tf) AS resolved;

SET @s = CONCAT(
 'SELECT (SELECT COUNT(DISTINCT record) FROM ', @dt, ' WHERE project_id=', @pid, ') AS total_records,',
 ' (SELECT COUNT(DISTINCT record) FROM ', @dt, ' WHERE project_id=', @pid,
   ' AND field_name=''', @tf, ''' AND value<>'''') AS randomized,',
 ' (SELECT COUNT(DISTINCT record) FROM ', @dt, ' WHERE project_id=', @pid,
   ' AND field_name=''ed_session_url'' AND value LIKE ''%sessionHandoff%'') AS seeing_the_message');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;

-- ---------------------------------------------------------------------------------------------
SELECT '5. THE STUCK RECORDS THEMSELVES' AS section;
SET @s = CONCAT(
 'SELECT record, event_id, LEFT(value,90) AS ed_session_url FROM ', @dt,
 ' WHERE project_id=', @pid, ' AND field_name=''ed_session_url'' AND value LIKE ''%sessionHandoff%''',
 ' ORDER BY CAST(record AS UNSIGNED) LIMIT 20');
PREPARE q FROM @s; EXECUTE q; DEALLOCATE PREPARE q;
-- state=pending -> not randomized (this is the problem)
-- state=done    -> Standard Care, correct: that arm genuinely has no session
