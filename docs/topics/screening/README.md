# Screening: `pre_screen` → `auditc` → `screen2` → `screen_eligibility` → `consent`

The Day-1 (ED) chain that decides who is eligible and who goes on to consent. In the intended
design every link auto-continues and the only eligibility condition is on `screen_eligibility`:
`[calc_screen_result] = '1'`. **Prod's 10-05 export is not there yet** (no Screen Eligibility on the
event, a condition on `auditc`); see the 10-05 doc.
**PID 279 and prod 35968 also have one on `pre_screen` (2026-09-30), so a stop action there really
ends the flow.**

| Doc | Covers |
|---|---|
| [2026-10-05-tsr-opens-chat.md](2026-10-05-tsr-opens-chat.md) | **Current, local 283 / prod steps.** Finishing TSR showed a blank Close page before the chat. The arm-2/3 queue entries waited on TSR at their own event, which nobody completes, and Close's messages branch on events that don't exist. Fixed and verified on 283 with 3 queue entries, alert 17 and Close's branching (no code), with prod steps and the CSV. |
| [2026-10-05-survey-queue-econsent-review.md](2026-10-05-survey-queue-econsent-review.md) | **Current, prod 15:42 export.** Auto-continue replaced by a survey queue, e-Consent on Consent, consent copy by link. The queue dropped the eligibility gates (prisoners, minors, no phone, pregnant all reach consent). An arm-2 Day-1 entry hijacks the Month-3 follow-up. The tested queue conditions, and six questions for the PI. |
| [2026-10-05-cannabis-box-and-consent-pdf.md](2026-10-05-survey-queue-econsent-review.md) | **Superseded by the 15:42 review; don't apply its steps.** 12:45 export. Why box #2 (cannabis), and in fact all three Screen Eligibility boxes, never show on prod: the instrument isn't on the event, and `auditc` now continues only when AUDIT-C is positive. The tested fix (doc 33 §10 + three additions), in prod order. Also the signed consent: a PDF Snapshot into `[consent_pdf]`, alert 15 logic-only, verified to the participant's inbox, and how to backfill people already consented. |
| 2026-09-30-pi-reply-stop-actions-and-config.md (removed 2026-10-12) | The reply to the PI: the pre-screen fix and its prod step, the blank completion page, the question whether `screen2` not being a survey is intended, and the settings cleanup. |
| [PRE_SCREEN_STOP_ACTIONS.md](PRE_SCREEN_STOP_ACTIONS.md) | **2026-09-30.** "End Survey" on the pre-screen (not English, in prison, not a good candidate, not interested) still carried people through AUDIT-C to consent. It is fixed on 279 with a `pre_screen` auto-continue condition and verified in the browser, desktop and phone. Includes the one-line prod step, and the blank page a stopped person now lands on. |
| [ELIGIBILITY_CALC_PI_XML_2026-09-25.md](ELIGIBILITY_CALC_PI_XML_2026-09-25.md) | **Current.** The `calc_screen_result` equation for the PI's 2026-09-25 workflow (military removed, stop actions, `phone`/`preg` only asked when AUDIT-C is positive, cannabis question), why the PI's version stored a blank for everyone, and the finding that stop actions do not stop the chain. |
| [calc_screen_result.txt](calc_screen_result.txt) | The equation itself, ready to paste into the Calculation Equation box. |
| ../../correspondence/2026-09-25-pi-screens-in-arm-1.md (removed 2026-10-12) | Why screening is filed under arm 1, the per-arm public link that skips eligibility and consent, and the PI's **16:46** export: calc deleted, inline logic lets minors and prisoners reach consent, `randomization_date` deleted. |
| ELIGIBILITY_GATE.md (removed 2026-10-12) | History (PID 257, `calc_eligible`): why consent is gated on the eligibility result at all. |

Tests: [`../../../e2e/eligibility-calc.js`](../../../e2e/eligibility-calc.js) (browser, every screening
path; `stops <pid>` walks every `pre_screen` stop action) and
[`../../phase-3-handoff/scripts/verify-eligibility-calc.php`](../../phase-3-handoff/scripts/verify-eligibility-calc.php)
(every input combination, through REDCap's own calc engine).

## Docs in this folder

| Doc | What it is |
| --- | --- |
| [2026-10-05-survey-queue-econsent-review.md](2026-10-05-survey-queue-econsent-review.md) | Prod's survey queue and e-Consent, reviewed from the 2026-10-05 15:42 export. |
| [2026-10-05-tsr-opens-chat.md](2026-10-05-tsr-opens-chat.md) | TSR opens the MICA chat directly: a configuration-only fix on PID 283, with prod steps. |
| [PRE_SCREEN_STOP_ACTIONS.md](PRE_SCREEN_STOP_ACTIONS.md) | Pre-screen stop actions must end the flow. Applied on prod 2026-09-30. |
| [ELIGIBILITY_CALC_PI_XML_2026-09-25.md](ELIGIBILITY_CALC_PI_XML_2026-09-25.md) | `calc_screen_result` for the PI's 2026-09-25 workflow. |
