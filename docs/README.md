# MICA docs

Where everything is. Each folder has its own README with the same list.

| If you want to | Go to |
| --- | --- |
| do something on production | [runbooks/](runbooks/README.md) |
| test locally | [testing/](testing/README.md), and the Playwright specs in [`../e2e/README.md`](../e2e/README.md) |
| understand how it works | [reference/](reference/README.md) |
| look up a known defect (`docs 14, Dnn` in code) | [defects/14-live-defects.md](defects/14-live-defects.md) |
| find what happened in one area | [topics](#topics) |
| look up a phase-3 stage cited in code (`stage-N §x`) | [plan/06-implementation-plan/](plan/06-implementation-plan/README.md) |
| run a setup or verify script | [`phase-3-handoff/scripts/`](phase-3-handoff/scripts/) (unchanged location; the E2E specs depend on it) |

Doc numbers (`14`, `31`, `32` …) are IDs: code comments cite them, so they stay in the filenames.

Superseded docs (PID 257/268 builds, the phase-3 plan, sent emails, done runbooks) were removed on 2026-10-12 and are in git history: `git log --diff-filter=D --name-only -- docs`.

`token-policy.md` (standard / high-threshold AI models) arrives at the top of this folder with PR #14.

## Prod runbooks — [`runbooks/`](runbooks/)

- [30-go-live-readiness.md](runbooks/30-go-live-readiness.md): What is left before prod can go live, assessed 2026-09-28 from the prod export. Start here for a go-live.
- [ESMS_REPROMPT_TIMEOUT.md](runbooks/ESMS_REPROMPT_TIMEOUT.md): Weekly SMS 1-hour re-prompt and 24-hour timeout. Fixed locally; prod still needs the Enhanced SMS Conversation update and one setting (§5).
- [32-prod-runbook-crc-notify.md](runbooks/32-prod-runbook-crc-notify.md): Settings so a critical finding reaches the CRC within 5 minutes of End Session. Verified on PID 271; ends with a six-message test session.

## Testing — [`testing/`](testing/)

- [22-minimum-test-path.md](testing/22-minimum-test-path.md): The shortest end-to-end path through the chatbot and the safety review on localhost.
- [17-safety-finding-manual-test.md](testing/17-safety-finding-manual-test.md): Producing, reviewing and acting on a safety finding by hand, with a preflight.
- [PID271_ALERT_TEST_MATRIX.md](testing/PID271_ALERT_TEST_MATRIX.md): Every alert on PID 271 tested on four attributes, before and after the event-prefix fix (2026-09-21).
- [11-auth-manual-test-guide.md](testing/11-auth-manual-test-guide.md): Testing Survey Login on the chat surveys by hand (the procedure behind `manual-test-auth.php`).

## Reference — [`reference/`](reference/)

- [01-architecture.md](reference/01-architecture.md): The building blocks: services under `classes/`, the no-auth participant chat, the authenticated review dashboard, SecureChatAI for every LLM call.
- [02-data-model.md](reference/02-data-model.md): Where data lives: REDCap fields, the EM log and entity tables (revised 2026-08-11).
- [13-securechatai-current-state-delta.md](reference/13-securechatai-current-state-delta.md): SecureChatAI's consumer contract as verified on 2026-08-18: agent loop, hooks, response shape.
- [20-llm-request-capture.md](reference/20-llm-request-capture.md): The exact JSON body MICA sends to the LLM, and how to replay it with curl.
- [ALERT_EVENT_PREFIX_BUG.md](reference/ALERT_EVENT_PREFIX_BUG.md): Why a REDCap alert silently never fires when it reads data at the wrong event, and the fix applied to alerts 02–14. Appendix: the `contact_info` designation fix.
- [23-safetyscan-payload.md](reference/23-safetyscan-payload.md): The SafetyScan request body with the pinned prompt and a study addendum, generated from live code.

## Defects — [`defects/`](defects/)

- [14-live-defects.md](defects/14-live-defects.md): D1–D25 from the 2026-08-18 SecureChatAI audit: a status table, and the detail in three parts (chat and security, SafetyScan, functional). Code comments cite these as "docs 14, Dnn".

## Topics

- [README.md](plan/06-implementation-plan/README.md): The per-stage work breakdown and stage tracker. Kept because code cites its stages ("stage-4 §4.1", "stage-6 §6.3").
