-- rollback for apply-booster-followup-copy.php on pid 271, generated 2026-09-21 16:41:09
UPDATE redcap_alerts SET `email_subject` = 'Your MICA booster session is ready' WHERE alert_id = 5755;  -- 07
UPDATE redcap_alerts SET `alert_message` = '<p>Hi [day_1_ed_arm_1][first_name],</p><p>It\'s time for your 3-month MICA booster session.</p><p>[survey-link:mica_booster_session:Open your booster session]</p><p>— The MICA Study Team</p>' WHERE alert_id = 5755;  -- 07
UPDATE redcap_alerts SET `alert_message` = 'Hi [day_1_ed_arm_1][first_name], it\'s the MICA Team. Your 3-month booster session is ready: [survey-url:mica_booster_session] Reply STOP to opt out.' WHERE alert_id = 5756;  -- 08
UPDATE redcap_alerts SET `email_subject` = 'Your MICA booster session is ready' WHERE alert_id = 5760;  -- 12
UPDATE redcap_alerts SET `alert_message` = '<p>Hi [day_1_ed_arm_1][first_name],</p><p>It\'s time for your 3-month MICA booster session.</p><p>[survey-link:mica_booster_session:Open your booster session]</p><p>— The MICA Study Team</p>' WHERE alert_id = 5760;  -- 12
UPDATE redcap_alerts SET `alert_message` = 'Hi [day_1_ed_arm_1][first_name], it\'s the MICA Team. Your 3-month booster session is ready: [survey-url:mica_booster_session] Reply STOP to opt out.' WHERE alert_id = 5761;  -- 13
