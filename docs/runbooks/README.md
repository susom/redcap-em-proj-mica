# Prod runbooks

What to do on production (PID 35968), as REDCap UI steps. Each one was rehearsed on a local copy first.

| Doc | What it is |
| --- | --- |
| [30-go-live-readiness.md](30-go-live-readiness.md) | What is left before prod can go live, assessed 2026-09-28 from the prod export. Start here for a go-live. |
| [25-prod-runbook-ed-session.md](25-prod-runbook-ed-session.md) | The ED Day-1 session link field and randomization setup, in the order to redo them on prod (from PID 257, 2026-08-28). |
| [26-prod-runbook-day1-flow.md](26-prod-runbook-day1-flow.md) | The Day-1 flow, CEMI handoff and booster context changes. Do it after 25. |
| [32-prod-runbook-crc-notify.md](32-prod-runbook-crc-notify.md) | Settings so a critical finding reaches the CRC within 5 minutes of End Session. Verified on PID 271; ends with a six-message test session. |
| [arm3-weekly-sms-prod.md](arm3-weekly-sms-prod.md) | Building the Arm 3 weekly SMS (an ASI sent by Enhanced SMS Conversation) on prod, step by step. Verified on PID 271. |
| [alerts-prod-promotion-fix.md](alerts-prod-promotion-fix.md) | Applying the alert event-prefix fixes on prod. Written 2026-09-21; prod has not been checked from here. |
