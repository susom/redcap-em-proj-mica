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
- [23-safetyscan-payload.md](reference/23-safetyscan-payload.md): The SafetyScan request body with the pinned prompt and a study addendum, generated from live code.

## Defects — [`defects/`](defects/)

- [14-live-defects.md](defects/14-live-defects.md): D1–D25 from the 2026-08-18 SecureChatAI audit: a status table, and the detail in three parts (chat and security, SafetyScan, functional). Code comments cite these as "docs 14, Dnn".

## Topics

### Alerts and weekly SMS — [`topics/alerts/`](topics/alerts/)

- [ALERT_EVENT_PREFIX_BUG.md](topics/alerts/ALERT_EVENT_PREFIX_BUG.md): Every reminder alert read enrolment data at the wrong event, and the fix. Its appendix holds the `contact_info` designation fix.
- [ARM3_WEEKLY_SMS_REBUILD_271.md](topics/alerts/ARM3_WEEKLY_SMS_REBUILD_271.md): The weekly SMS as rebuilt and verified on PID 271 (2026-09-23). The invitation is inactive.
- [ESMS_REPROMPT_TIMEOUT.md](topics/alerts/ESMS_REPROMPT_TIMEOUT.md): Weekly SMS: 1-hour re-prompt per question and 24-hour timeout. Fixed locally; prod needs the module update and one setting.
- [SMS_MESSAGE_ORDER.md](topics/alerts/SMS_MESSAGE_ORDER.md): Weekly SMS messages arriving out of order: texts over 160 characters are split.

### Participant authentication — [`topics/auth/`](topics/auth/)

- [08-auth-discovery.md](topics/auth/08-auth-discovery.md): The 2FA replacement: TL;DR, the PI-facing summary and the evidence index (2026-08-17).
- [10-auth-implementation-pid257.md](topics/auth/10-auth-implementation-pid257.md): Option B as configured: Survey Login, the credential event, host instruments.

### Participant chatbot — [`topics/chatbot/`](topics/chatbot/)

- [28-model-alias-not-registered.md](topics/chatbot/28-model-alias-not-registered.md): "The configured AI model … is not available": the alias was missing from SecureChatAI's registry. Fixed 2026-09-22.
- [21-chatbot-mount-collision.md](topics/chatbot/21-chatbot-mount-collision.md): The chat would not open: a DOM id shared with another module. Fixed.

### Sessions and arms — [`topics/session/`](topics/session/)

- [session-lifecycle.md](topics/session/session-lifecycle.md): Ending a session, and why the Back button used to reopen it. Fixed.
- [24-ed-session-handoff.md](topics/session/24-ed-session-handoff.md): Handing the participant to the ED Day-1 session link at randomization.
- [15-arm-materialization.md](topics/session/15-arm-materialization.md): Automatic arm placement: `study_group` → the assigned arm.
- [27-arm1-redirect-loop.md](topics/session/27-arm1-redirect-loop.md): The arm-1 `ERR_TOO_MANY_REDIRECTS` at the end of the Day-1 chain. Fixed.
- [29-close-empty-redirect.md](topics/session/29-close-empty-redirect.md): The white page after `close`. Fixed.

### SafetyScan and notification — [`topics/safetyscan/`](topics/safetyscan/)

- [31-critical-finding-crc-notify.md](topics/safetyscan/31-critical-finding-crc-notify.md): The 5-minute requirement for a critical finding to reach the CRC: design and measurements (22–65 s on PID 271). The prod steps are runbook 32.

### Randomization — [`topics/randomization/`](topics/randomization/)

- [README.md](topics/randomization/README.md): How randomization places a record in its arm, verified on PID 268 (2026-09-15).
- [TRIGGER_TSR_COMPLETE_OR_MANUAL.md](topics/randomization/TRIGGER_TSR_COMPLETE_OR_MANUAL.md): Randomize when `tsr` is Complete or on a Randomize click (PID 271).
- [PROD_35968_RANDOMIZATION_CONFIG.md](topics/randomization/PROD_35968_RANDOMIZATION_CONFIG.md): Prod randomizes nobody: trigger mode and a blank stratum (diagnosis from the 2026-09-23 export).
- [ALLOCATION_TABLE_DOES_NOT_COPY.md](topics/randomization/ALLOCATION_TABLE_DOES_NOT_COPY.md): A copied project randomizes nobody, silently, because the allocation table does not copy.

### Screening and consent — [`topics/screening/`](topics/screening/)

- [README.md](topics/screening/README.md): The screening chain, and where prod stands.
- [2026-10-05-survey-queue-econsent-review.md](topics/screening/2026-10-05-survey-queue-econsent-review.md): Prod's survey queue and e-Consent, reviewed from the 2026-10-05 15:42 export.
- [2026-10-05-tsr-opens-chat.md](topics/screening/2026-10-05-tsr-opens-chat.md): TSR opens the MICA chat directly: a configuration-only fix on PID 283, with prod steps.
- [PRE_SCREEN_STOP_ACTIONS.md](topics/screening/PRE_SCREEN_STOP_ACTIONS.md): Pre-screen stop actions must end the flow. Applied on prod 2026-09-30.
- [ELIGIBILITY_CALC_PI_XML_2026-09-25.md](topics/screening/ELIGIBILITY_CALC_PI_XML_2026-09-25.md): `calc_screen_result` for the PI's 2026-09-25 workflow.

### Project build and reviews — [`topics/project/`](topics/project/)

- [33-xml-review-2026-10-02.md](topics/project/33-xml-review-2026-10-02.md): Review of the prod `MICA-ED (R01)` XML export of 2026-10-02: instruments, events, surveys, alerts, randomization.
- [19-admin-form-logic-errors.md](topics/project/19-admin-form-logic-errors.md): The `admin` form's "syntactical errors" banner. Bucket A applied; B and C still open.

## Phase-3 implementation plan — [`plan/06-implementation-plan/`](plan/06-implementation-plan/)

- [README.md](plan/06-implementation-plan/README.md): The per-stage work breakdown and stage tracker. Kept because code cites its stages ("stage-4 §4.1", "stage-6 §6.3").
