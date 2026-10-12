# Prod runbooks

What to do on production (PID 35968), as REDCap UI steps, each rehearsed on a local copy first.

| Doc | What it is |
| --- | --- |
| [30-go-live-readiness.md](30-go-live-readiness.md) | What is left before prod can go live, assessed 2026-09-28 from the prod export. Start here for a go-live. |
| [ESMS_REPROMPT_TIMEOUT.md](ESMS_REPROMPT_TIMEOUT.md) | Weekly SMS 1-hour re-prompt and 24-hour timeout. Fixed locally; prod still needs the module update and one setting (§5). |
| [32-prod-runbook-crc-notify.md](32-prod-runbook-crc-notify.md) | Settings so a critical finding reaches the CRC within 5 minutes of End Session. Verified on PID 271; ends with a six-message test session. |
