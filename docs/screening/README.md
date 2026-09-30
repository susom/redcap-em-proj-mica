# Screening: `pre_screen` → `auditc` → `screen2` → `screen_eligibility` → `consent`

The Day-1 (ED) chain that decides who is eligible and who goes on to consent. Every link
auto-continues. The only condition is on `screen_eligibility`: `[calc_screen_result] = '1'`.
**PID 279 also has one on `pre_screen` (2026-09-30), so a stop action there really ends the flow. Prod
does not yet.**

| Doc | Covers |
|---|---|
| [PRE_SCREEN_STOP_ACTIONS.md](PRE_SCREEN_STOP_ACTIONS.md) | **2026-09-30.** "End Survey" on the pre-screen (not English, in prison, not a good candidate, not interested) still carried people through AUDIT-C to consent. It is fixed on 279 with a `pre_screen` auto-continue condition and verified in the browser, desktop and phone. Includes the one-line prod step, and the blank page a stopped person now lands on. |
| [ELIGIBILITY_CALC_PI_XML_2026-09-25.md](ELIGIBILITY_CALC_PI_XML_2026-09-25.md) | **Current.** The `calc_screen_result` equation for the PI's 2026-09-25 workflow (military removed, stop actions, `phone`/`preg` only asked when AUDIT-C is positive, cannabis question), why the PI's version stored a blank for everyone, and the finding that stop actions do not stop the chain. |
| [calc_screen_result.txt](calc_screen_result.txt) | The equation itself, ready to paste into the Calculation Equation box. |
| [../randomization/2026-09-25-pi-reply-screens-in-arm-1.md](../randomization/2026-09-25-pi-reply-screens-in-arm-1.md) | Why screening is filed under arm 1, the per-arm public link that skips eligibility and consent, and the PI's **16:46** export: calc deleted, inline logic lets minors and prisoners reach consent, `randomization_date` deleted. |
| [../ELIGIBILITY_GATE.md](../ELIGIBILITY_GATE.md) | History (PID 257, `calc_eligible`): why consent is gated on the eligibility result at all. |

Tests: [`../../e2e/eligibility-calc.js`](../../e2e/eligibility-calc.js) (browser, every screening
path; `stops <pid>` walks every `pre_screen` stop action) and
[`../phase-3-handoff/scripts/verify-eligibility-calc.php`](../phase-3-handoff/scripts/verify-eligibility-calc.php)
(every input combination, through REDCap's own calc engine).
