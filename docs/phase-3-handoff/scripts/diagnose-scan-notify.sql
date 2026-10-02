-- Why did a MICA session go unscreened, and did anyone get told?  Read-only; no transcript text.
-- Supporting 32-prod-runbook-crc-notify.md, step 6.
--
-- FOUR STANDALONE SELECTs, for REDCap's Control Center > Database Query Tool. That tool runs ONE
-- statement per request and only one that begins with select/show/explain - so there are no
-- variables here: a `SET @record = ...` line is either refused or runs in a request of its own, and
-- the SELECTs then match nothing (every one came back empty that way on 2026-09-28). Paste each query
-- on its own, starting at its `select`, and replace 35968 and '130' with your project and record.
--
-- None of this is visible in the REDCap UI: the dashboard shows only the job's last error, a failed
-- notice is recorded in the module's own table and nowhere else, and SecureChatAI keeps the
-- provider's real error in its log rather than handing it to MICA.

-- 1. The scan job. manual_review_required = gave up after its attempts ("NOT SCREENED").
select j.id as job, j.status, j.attempts, from_unixtime(j.created) as queued_at, from_unixtime(j.updated) as updated_at, left(j.last_error, 120) as last_error from redcap_entity_mica_scan_job j where j.project_id = 35968 and j.record = '130' order by j.id

-- 2. Each attempt. withheld = ["uniqueItems"] means the D23 fix (commit 13ea3cb) ran; NULL on a GPT alias
--    means it did not. latency_ms: ~0 never reached the network, ~2000 is a fast refusal.
--    model_alias is the alias of the project whose scan pass RAN the job - not necessarily this one's
--    (docs 14 D24, fixed 2026-09-28). gemini-2.5-flash = that project's alias was blank (the code's
--    fallback), and on prod 35968 it is not registered, so every scan failed in ~80 ms.
select r.job_id, r.attempt, from_unixtime(r.created) as at, r.run_status, r.latency_ms, r.model_alias, r.resolved_model, json_extract(r.model_output_json, '$.provider_schema_withheld') as withheld, json_extract(r.model_output_json, '$.schema_was_sent') as schema_sent from redcap_entity_mica_scan_run r join redcap_entity_mica_scan_job j on j.id = r.job_id where j.project_id = 35968 and j.record = '130' order by r.id

-- 3. The notices. failed + "No recipient addresses were given" = nobody in the Reviewer role, or (when
--    Reviewer notification addresses is filled in) no valid entry in that list (2026-10-02);
--    sent + recipient_count = how many addresses it went to: the named list when set, otherwise the
--    role's members (check their inboxes and spam).
select n.notification_type, n.status, n.recipient_count, from_unixtime(n.sent_at) as at, left(n.subject, 90) as subject, left(n.error, 150) as error from redcap_entity_mica_notification n where n.project_id = 35968 and n.record = '130' order by n.id

-- 3b. Every notice on the project, newest first. Empty = the notice step has never recorded anything
--     on this server (it records every attempt, sent or failed), so it is failing before it gets that far.
select n.id, n.record, n.notification_type, n.status, n.recipient_count, from_unixtime(n.sent_at) as at, left(n.subject, 70) as subject, left(n.error, 100) as error from redcap_entity_mica_notification n where n.project_id = 35968 order by n.id desc

-- 4. SecureChatAI's own record of the scan's failures: the scan's rows have no session_id (the chat's
--    carry one) and no record id, so they are matched to the job's lifetime. Only the error text is
--    selected - the row also holds the transcript. 295 bytes = the old schema refusal (D23); 988 bytes
--    = Azure's content filter. No rows while query 2 shows service_error = the call "failed" without
--    SecureChatAI ever logging it.
select l.timestamp, json_unquote(json_extract(l.message, '$.error_message')) as error_message from redcap_external_modules_log l join redcap_external_modules m on m.external_module_id = l.external_module_id join (select from_unixtime(min(created)) as first_at, from_unixtime(max(updated)) + interval 1 minute as last_at from redcap_entity_mica_scan_job where project_id = 35968 and record = '130') span where m.directory_prefix = 'secure_chat_ai' and l.record = 'SecureChatLogError' and l.project_id = 35968 and json_valid(l.message) and json_type(json_extract(l.message, '$.session_id')) = 'NULL' and l.timestamp between span.first_at and span.last_at order by l.log_id
