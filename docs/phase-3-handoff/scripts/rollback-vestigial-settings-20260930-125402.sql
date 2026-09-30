-- Rollback for the 2026-09-30 config.json cleanup: the stored proj_mica rows of the 10 settings
-- undeclared then (pilot cadence contexts, session_length_days, number_session_callback, and the
-- never-delivered chatbot_intro_text / chatbot_end_session_text). Taken BEFORE the purge, on the
-- local docker instance (external_module_id 93). Restoring the rows alone shows nothing in the
-- dialog - the keys must also be re-declared in config.json.
--   docker exec -i redcap_2023_1_db mysql -uredcap -predcap123 redcap < docs/phase-3-handoff/scripts/rollback-vestigial-settings-20260930-125402.sql
REPLACE INTO `redcap_external_module_settings` VALUES (93,257,'chatbot_end_session_text','string','When you are finished, please click End Session.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,268,'chatbot_end_session_text','string','When you are finished, please click End Session.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,271,'chatbot_end_session_text','string','When you are finished, please click End Session.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,279,'chatbot_end_session_text','string','When you are finished, please click End Session.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,257,'chatbot_intro_text','string','Welcome to MICA. This is a development environment used for testing.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,268,'chatbot_intro_text','string','Welcome to MICA. This is a development environment used for testing.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,271,'chatbot_intro_text','string','Welcome to MICA. This is a development environment used for testing.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,279,'chatbot_intro_text','string','Welcome to MICA. This is a development environment used for testing.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,257,'chatbot_system_context_session_2','string','Session 2. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,268,'chatbot_system_context_session_2','string','Session 2. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,271,'chatbot_system_context_session_2','string','Session 2. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,279,'chatbot_system_context_session_2','string','Session 2. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,257,'chatbot_system_context_session_3','string','Session 3. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,268,'chatbot_system_context_session_3','string','Session 3. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,271,'chatbot_system_context_session_3','string','Session 3. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,279,'chatbot_system_context_session_3','string','Session 2. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,257,'chatbot_system_context_session_4','string','Session 4. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,268,'chatbot_system_context_session_4','string','Session 4. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,271,'chatbot_system_context_session_4','string','Session 4. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,279,'chatbot_system_context_session_4','string','Session 2. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,257,'chatbot_system_context_session_5','string','Session 5. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,268,'chatbot_system_context_session_5','string','Session 5. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,271,'chatbot_system_context_session_5','string','Session 5. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,279,'chatbot_system_context_session_5','string','Session 2. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,257,'chatbot_system_context_session_6','string','Session 6. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,268,'chatbot_system_context_session_6','string','Session 6. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,271,'chatbot_system_context_session_6','string','Session 6. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,279,'chatbot_system_context_session_6','string','Session 2. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,257,'chatbot_system_context_session_7','string','Session 7. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,268,'chatbot_system_context_session_7','string','Session 7. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,271,'chatbot_system_context_session_7','string','Session 7. DEV PLACEHOLDER.');
REPLACE INTO `redcap_external_module_settings` VALUES (93,279,'chatbot_system_context_session_7','string','Session 2. DEV PLACEHOLDER.');
