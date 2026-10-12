# Alerts and weekly SMS

Alerts & Notifications, and the Arm 3 weekly SMS (an ASI, not an alert).

| Doc | What it is |
| --- | --- |
| [ALERT_EVENT_PREFIX_BUG.md](ALERT_EVENT_PREFIX_BUG.md) | Every reminder alert read enrolment data at the wrong event, and the fix. Its appendix holds the `contact_info` designation fix (from the former ALERTS_CONTACT_FIELD_BREAKAGE.md). |
| [ARM3_WEEKLY_SMS_REBUILD_271.md](ARM3_WEEKLY_SMS_REBUILD_271.md) | The weekly SMS as rebuilt and verified on PID 271 (2026-09-23). The invitation is inactive. |
| [ESMS_REPROMPT_TIMEOUT.md](ESMS_REPROMPT_TIMEOUT.md) | Weekly SMS: 1-hour re-prompt per question and 24-hour timeout. Fixed locally; prod needs the module update and one setting. |
| [SMS_MESSAGE_ORDER.md](SMS_MESSAGE_ORDER.md) | Weekly SMS messages arriving out of order: texts over 160 characters are split. |
| [CONSENT_PDF_EMAIL.md](CONSENT_PDF_EMAIL.md) | Emailing the signed consent PDF to the participant (alert 15): diagnosis, 2026-09-23. |
| [pi-review/test-6b/README.md](pi-review/test-6b/README.md) | Test 6B on prod: one Sunday text to the PI (2026-09-27). The `pi-review/` folder also holds the PI test manual, the review PDFs and their build scripts. |
