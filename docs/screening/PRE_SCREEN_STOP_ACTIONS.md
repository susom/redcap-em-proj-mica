# Pre-screen stop actions must end the flow

**2026-09-30.** Fixed on PID 279. **Prod (PID 35968) has the same gap.** Its 09-28 export (replica
PID 278) has the same four stop actions and no auto-continue condition. The prod step is in
[§4](#4-prod).

## 1. The report

On PID 279, answering **No** to "Is the preferred language English?" on the pre-screen showed the
"End Survey" prompt. After End Survey, the AUDIT-C survey opened and the flow carried on.

## 2. Why

In REDCap 17.2.3 a stop action ends only the survey it is on. If that survey auto-continues, the
next survey still opens: `Surveys/index.php:2789` skips auto-continue only when "delete the
response" is set for stop actions. `pre_screen` auto-continued with **no condition**, so each of its
four stop actions led straight into AUDIT-C:

| Question | Stops on |
|---|---|
| `english`: Is the preferred language English? | No |
| `prison`: … currently in prison or to go to jail? | Yes |
| `goodcand`: … would be a good participant …? | No |
| `s_interest`: … interested in completing the screen …? | No |

This was first measured on 2026-09-25 on a copy of the PI's export
([`../randomization/2026-09-25-pi-reply-screens-in-arm-1.md`](../randomization/2026-09-25-pi-reply-screens-in-arm-1.md)),
but the fix never reached 279.

**Reproduced on 279 in the browser** with `node e2e/eligibility-calc.js stops 279 leak`, on desktop
and iPhone 13. Every stop case showed End Survey, then went `pre_screen > auditc > consent`: the
person reached the **consent form**, not only AUDIT-C.

## 3. The fix

**Survey Settings → `pre_screen` → "Auto-continue to next survey" → only if this logic is true:**

```
[english] = '1' AND [prison] = '0' AND [goodcand] = '1' AND [s_interest] = '1'
```

Each stop-action question has to have the answer that passes. A stop submits the page at once and
leaves the later questions blank, and a blank fails `= '1'`, so any stop ends the flow. Only a full,
passing pre-screen continues.

- Saved through REDCap's own Survey Settings page by a design-rights, non-admin user
  (`node e2e/eligibility-calc.js gate 279`). REDCap's validator accepted it.
- The e2e tool derives the condition from the project's own stop actions. It no longer uses the
  09-25 constant, which included `[md_assent] = '1'`: that field doesn't exist on 279 or prod, and
  naming it would stop everyone at pre-screen.

**Verified on 279** with `node e2e/eligibility-calc.js stops 279`: 17 checks passed.

| Case | Pages walked |
|---|---|
| English = No (desktop and iPhone 13) | `pre_screen > ack` |
| Prison = Yes | `pre_screen > ack` |
| Good candidate = No | `pre_screen > ack` |
| Interested = No | `pre_screen > ack` |
| Eligible control, no stop | `pre_screen > auditc > consent`, unchanged |

No horizontal overflow on any end page. The test records (4–15 and one re-check) were deleted,
along with their MICA log rows. Records 1–3 are untouched.

## 4. Prod

PID 35968 → Online Designer → `pre_screen` → **Survey settings** → **Auto-continue to next survey**:
leave it ticked, paste the condition from §3 into the logic box, and save. It needs design rights,
not admin rights, and takes effect immediately.

## 5. Left as is

- **The page a stopped person lands on is blank.** It shows a "Close survey" button with no message
  and no study header, because `pre_screen` has no completion text. Nobody reached it before, since
  every stop continued to AUDIT-C. Its content is the PI's call. Set it in Survey Settings →
  "Survey Completion Text", or with REDCap's per-survey stop-action text.
  Screenshot: `e2e/shots/elig-stops-stop-english-mobile-end.png`.
- **`screen2`'s stop actions (`phone` = No, `preg` = Yes) never fire, and nothing gates consent on
  279.** These are the only other stop actions in the project.
  - `screen2` is not enabled as a survey (`survey_enabled = 0`), so a participant is never asked
    about phone or pregnancy.
  - `screen_eligibility` is not on the Day-1 event.
  - `auditc` auto-continues with no condition.
  - So the chain is `pre_screen > auditc > consent` for everyone who passes the pre-screen. Walked
    in the browser on 2026-09-30:

    | Case | Pages walked |
    |---|---|
    | never drinks | `pre_screen > auditc > consent` |
    | man with AUDIT-C 1 (threshold 4) | `pre_screen > auditc > consent` |

  - `consent`'s `desc_eligible` banner encodes the rule: `((F and AUDIT-C ≥ 3) or (M and AUDIT-C ≥
    4)) and phone = 1 and (M or preg = 0)`. The banner hides for these two cases, but the consent
    form still opens.
  - Closing the gap means the following. **Not done; the flow is the PI's design.**
    1. Enable `screen2` as a survey.
    2. Give `auditc` the condition "AUDIT-C positive".
    3. Give `screen2` the condition `[phone] = '1' AND ([s_sex] = '1' OR [preg] = '0')`.
  - Prod couldn't be checked: replica 278 has only 7 survey rows (`auditc` and `consent` aren't
    surveys there), so it doesn't reflect prod's survey setup.
