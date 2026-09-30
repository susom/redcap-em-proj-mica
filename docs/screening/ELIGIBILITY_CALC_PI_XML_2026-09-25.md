# `calc_screen_result` in the PI's 2026-09-25 workflow

**Diagnosed and fixed 2026-09-25** on the PI's export `NEWTESTMICAR01_2026-09-25_1058.REDCap.xml`
(project "NEW TEST MICA_R01", metadata only, no records). The fix was verified end to end on a
local project built from that file (**PID 272**). **Not applied to PID 271 or prod.**

> **Update, same day: the PI's 16:46 export deletes `calc_screen_result`.** In that version, each
> eligibility message and the consent condition carry their own copy of the logic, "Congratulations"
> moved to the top of `consent`, and `randomization_date`, `md_assent`, `html_screen` and
> `randomize_trigger` were deleted. The equation below is still the recommended fix: restore the
> field, then point the consent condition and the messages back at it.
>
> Why, from a browser walk on **PID 273**, built from the 16:46 export:
> - A **17-year-old**, a **70-year-old**, someone **in prison** and a **non-English speaker** all
>   reach consent. The new condition doesn't check age or prison, and the stop actions still don't
>   stop the chain.
> - Never-drinkers see **no message**. Their AUDIT-C score is blank, so every "AUDIT-C negative"
>   test is false for them.
> - The `pre_screen` condition must be the **16:46 variant**, without `md_assent` (see "Stop actions
>   do not stop" below).
> - Alerts 02–04, the 3/6/12-month dates, `first_monday`, `weekday` and `calc_esms_valid` all still
>   reference the deleted `randomization_date`.
>
> Evidence and the full table:
> [`../randomization/2026-09-25-pi-reply-screens-in-arm-1.md`](../randomization/2026-09-25-pi-reply-screens-in-arm-1.md).

---

## The equation

Paste this into **Calculation Equation**. Don't paste it into Field Label, which is how it broke.
The same text is in [`calc_screen_result.txt`](calc_screen_result.txt).

```
if(
  [sc_age] = "" OR [s_sex] = "" OR [prison] = "" OR [days_dr] = "",
  "",
  if(
    [days_dr] = "0",
    0,
    if(
      [audit_c_score] = "",
      "",
      if(
        ([s_sex] = "1" AND [audit_c_score] < 4)
        OR ([s_sex] = "2" AND [audit_c_score] < 3),
        0,
        if(
          [phone] = "" OR ([s_sex] = "2" AND [preg] = ""),
          "",
          if(
            [sc_age] >= 18
            AND [sc_age] <= 65
            AND [phone] = "1"
            AND [prison] = "0"
            AND ([s_sex] = "1" OR [preg] = "0"),
            1,
            2
          )
        )
      )
    )
  )
)
```

| Value | Meaning | What `screen_eligibility` shows | Then |
|---|---|---|---|
| *blank* | not enough answered to decide | nothing | thank-you page |
| `0` | not eligible: AUDIT-C negative (M < 4, F < 3), including never-drinkers | `desc_ineligible` | thank-you page |
| `1` | eligible: AUDIT-C positive, 18–65, phone, not in prison, not pregnant | `desc_eligible` | **auto-continues to `consent`** |
| `2` | AUDIT-C positive but fails another criterion | `desc_ineligible_hazard` (SAMHSA resources) | thank-you page |

The codes and messages are the same as before, and `[calc_screen_result] = '1'` still gates the
auto-continue to consent. The PI's randomization trigger is `[day_1_ed_arm_1][tsr_complete]=2`
(trigger option 2 on `tsr`, stratified) and **does not read this field**. So the consent gate is
the only eligibility check between screening and randomization. (Local 271 uses
`[calc_screen_result]=1 AND [tsr_complete]='2'`; see
[`../randomization/TRIGGER_TSR_COMPLETE_OR_MANUAL.md`](../randomization/TRIGGER_TSR_COMPLETE_OR_MANUAL.md).)

**Read it top to bottom:**
1. An always-asked answer is missing → blank.
2. Never drinks → 0.
3. AUDIT-C not finished → blank.
4. AUDIT-C negative → 0. `phone` and `preg` are not needed, because they are never asked.
5. AUDIT-C positive but `phone` (or, for women, `preg`) unanswered → blank.
6. Otherwise 1 if eligible, 2 if not.

Every blank check is written with `=`.

---

## What broke

### 1. The equation box is empty (the breakage)

In the XML, `calc_screen_result` has `redcap:Calculation=""`. The formula text is in the field's
**label**. The Online Designer on 272 shows the Calculation Equation box empty. A calc with no
equation never computes, so:

- nobody sees an eligibility message;
- `screen_eligibility`'s auto-continue (`[calc_screen_result] = '1'`) never fires, so **nobody reaches
  consent** through the survey chain, and so nobody reaches `tsr` or randomization that way either.

Reproduced as a participant through the public survey link (`e2e/eligibility-calc.js run 272 broken`):

| Participant | Stored | Eligibility page | Ends |
|---|---|---|---|
| man, AUDIT-C 8, phone (should be eligible) | blank | empty, just **Submit** | "Thank you for taking the survey" |
| woman, AUDIT-C 3, phone, not pregnant (should be eligible) | blank | empty | thank-you page |
| woman, AUDIT-C 2, cannabis weekly | blank | only `cann_elig` (it branches on `[cann]`) | thank-you page |
| never drinks | blank | empty | thank-you page |

### 2. Moving the label text into the box is not enough

The label holds the old equation with `military` removed. Pasted into the box, it is valid. But
REDCap's own evaluator, run over the same 1,011 input combinations as below, gets **322 of them
wrong**. That's because the PI also changed the workflow around it:

| Input class | PI's text gives | Should be | Why |
|---|---|---|---|
| AUDIT-C negative, `phone`/`preg` never asked (88 combos) | blank, no message | `0` | `phone` and `preg` now only appear when AUDIT-C is positive, and the old guard returns blank if either is empty |
| never drinks (144) | blank, no message | `0` | `days_dr = 0` hides the other two AUDIT-C items, so `audit_c_score` is blank |
| AUDIT-C-positive **woman** who fails another criterion (90) | `0` | `2` | the hazard branch tests `[s_sex] = "0"`; the codes are 1 = Male, 2 = Female. This bug predates the PI and is on 271 too |

`< =` (with a space) in the label is **not** a problem. REDCap normalises it to `<=` on the server
and in the JavaScript it generates (checked with `LogicTester::formatLogicToJS`).

### 3. `military` is gone, deliberately

The PI deleted the `military` field and took it out of the label formula. The new equation follows
that. It was an exclusion criterion on 257 and 271.

---

## The workflow the equation has to fit

Screening instruments in the PI's XML compared with PID 271:

| Change | Effect on eligibility |
|---|---|
| `military` deleted | criterion dropped |
| `prison` moved from `screen2` to `pre_screen` | always answered before AUDIT-C |
| **stop actions added**: `english`/`goodcand`/`md_assent`/`s_interest` = 0, `prison` = 1, `phone` = 0, `preg` = 1 | see "Stop actions do not stop" below |
| `phone` branches on AUDIT-C positive (was always asked) | blank for AUDIT-C-negative participants |
| `preg` branches on female **and** AUDIT-C ≥ 3 (was every female) | blank for AUDIT-C-negative women |
| new `cann` (asked when AUDIT-C negative) and `cann_elig` message | not part of this study's eligibility |
| branching removed from `goodcand` and `s_interest`; `screen_no`, `eligibility` and `participant_id` deleted | the stop actions replace them |

The survey chain is unchanged: `pre_screen` → `auditc` → `screen2` → `screen_eligibility` →
`consent`, all auto-continue. The only condition is `[calc_screen_result] = '1'` on
`screen_eligibility`, from [`../ELIGIBILITY_GATE.md`](../ELIGIBILITY_GATE.md).

---

## Verification

**1. E2E on PID 272.** Everything was done in the browser through REDCap's own pages, as a
**non-super** user. That user created the project from the XML on the New Project page, took the
public link from Survey Distribution Tools, walked one participant per case, and fixed the equation
in the Online Designer by pasting it into the Calculation Equation box. REDCap's validator reported
**Valid**, and the saved equation is byte-identical to `calc_screen_result.txt`. Each case's stored
value, which PHP computes, was read back and compared with the message on the page, which the
browser computes. **76/76 checks passed**, including three mobile (iPhone 13) walks with no
horizontal overflow.

| Case | Stored | Shows | Ends |
|---|---|---|---|
| man, AUDIT-C 8, phone | 1 | eligible | **consent** |
| woman, AUDIT-C 3, phone, not pregnant | 1 | eligible | **consent** |
| man, AUDIT-C 3 (one under his cut-off) | 0 | not eligible | thank-you |
| woman, AUDIT-C 2, cannabis weekly | 0 | not eligible **+** cannabis study | thank-you |
| never drinks | 0 | not eligible | thank-you |
| man, 70, AUDIT-C 8 | 2 | resources | thank-you |
| woman, 17, AUDIT-C 4 | 2 | resources | thank-you |
| woman, AUDIT-C 4, pregnant (stop on `screen2`) | 2 | resources | thank-you |
| man, AUDIT-C 8, no phone (stop on `screen2`) | 2 | resources | thank-you |
| man in prison (stop on `pre_screen`) | 2 | resources | thank-you |
| not English (stop on `pre_screen`) | blank | nothing | thank-you, but walked through AUDIT-C first |
| **not interested (stop on `pre_screen`)** | **1** | **eligible** | **consent** (the leak below) |

**2. Every combination, on the server.** [`verify-eligibility-calc.php`](../phase-3-handoff/scripts/verify-eligibility-calc.php)
saves 1,011 records through `REDCap::saveData()`, which is the path that makes REDCap compute and
store the calc. The inputs are sex × age 17/18/65/66 × prison × 7 AUDIT-C patterns × phone ×
preg, plus the blank guards. Each stored value is compared with the eligibility rules written
separately in PHP. **Result: 0 mismatches.** Compared with the equation that worked on 271
(military = No), the only differences are the intended ones:

```
 144  never drinks            271 blank -> 0
  88  AUDIT-C negative, phone/preg not asked   271 blank -> 0
  90  AUDIT-C-positive woman, fails another criterion   271 0 -> 2
```

No result changed between eligible and not eligible.

*Harness note:* the first version saved 100 records per `saveData()` call. REDCap's bulk auto-calc
then left `audit_c_score` uncomputed for 73 of 1,011 records, and those showed up as mismatches.
Every one of the 73 was such a record, and all 477 records that did get a score matched. The
script now saves one record per call, the way a survey submit does, and stops with a harness error
if a score is missing. So **don't recompute existing records with a bulk import**; use Data
Quality rule H.

---

## Found while testing: not part of the equation

### Stop actions do not stop (verified; fix verified)

In REDCap 17.2.3, a stop action ends the **current** survey. Auto-continue still sends the
participant to the next one. The only case where it doesn't is `stop_action_delete_response = 1`
(`Surveys/index.php:2789`), and the PI's surveys have 0. On `pre_screen` that means:

- **`s_interest`, `goodcand` or `md_assent` = 0**: the patient is carried through AUDIT-C, can
  score `1`, and **lands on consent**. Measured: "not interested" → eligible → consent. From there
  nothing checks eligibility again, because the PI's randomization trigger is `tsr` complete only.
  (That the gates placed after `prison` behave the same way follows from field order and the code
  path; `s_interest` is the one walked end to end.)
- **`english` = 0**: carried through AUDIT-C too. The stop fires before `prison` is answered, so
  the calc stays blank and they never reach consent, but they are still shown the AUDIT-C questions.

This is not a regression. 271 has no stop actions and no auto-continue condition on `pre_screen`,
so nothing ever ended the chain there either. The PI's stop actions were the first attempt at a
gate, and they don't work as intended. The equation can't fix this, because it can't stop the
questions being shown. The fix goes in
**`pre_screen` → Survey settings → Survey Termination Options → conditional logic for
auto-continue**:

Use the version that matches the dictionary. A condition that names a deleted field is false for
everyone, so the wrong one stops every participant at `pre_screen`.

| Export | Condition | Checked |
|---|---|---|
| 10:58 (has `md_assent`) | `[english] = '1' AND [goodcand] = '1' AND [md_assent] = '1' AND [s_interest] = '1' AND [prison] = '0'` | 272, browser (below) |
| **16:46 (`md_assent` deleted)** | `[english] = '1' AND [goodcand] = '1' AND [s_interest] = '1' AND [prison] = '0'` | 273, `REDCap::evaluateLogic` on walked records: eligible man continues; "in prison", "not English" and "not interested" stop. The 10:58 version stops the eligible man too |

The 10:58 version was entered through Survey Settings on 272 (`eligibility-calc.js gate`), where
REDCap's validator reported Valid, then re-walked (`run 272 gated`, **36/36**). Every `pre_screen` stop now
ends at `pre_screen`, and eligible men and women still reach consent. Survey settings do not
travel in the Data Dictionary; see [`../alerts/PROD_PROMOTION.md`](../alerts/PROD_PROMOTION.md).

The `screen2` stops (no phone, pregnant) still continue to `screen_eligibility`. With this
equation, those participants score `2` and see the resources message. That was left alone because
it looks like the right outcome.

### Smaller items

- **Two contradicting messages.** An AUDIT-C-negative participant who uses cannabis weekly or daily
  reads "you are **not eligible** to participate in our research study" and then "you are
  **eligible** for our research study on cannabis". If only the cannabis message should show, set
  `desc_ineligible`'s branching to
  `[calc_screen_result] = "0" AND [cann] <> "4" AND [cann] <> "5"`. This was checked with
  `REDCap::evaluateLogic` against five real 272 records (cannabis weekly, cannabis rarely, never
  drinks, eligible, hazardous) and does the right thing in each. **Not applied**, because it
  changes what participants read.
- **An empty page.** AUDIT-C-negative participants get a `screen2` page with no question, only
  **Submit** (`phone` and `preg` are both hidden). This comes from the PI's branching.
- **Never-drinkers never see `cann`.** Their `audit_c_score` is blank, and REDCap treats
  blank `<` number as false on both server (`chkNullCompare`) and browser. They now get "not
  eligible", which is correct for this study. Asking them about cannabis would mean scoring
  `days_dr = 0` as AUDIT-C 0 inside `audit_c_score`. That's the PI's call.
- **Four copies of the thresholds.** The AUDIT-C cut-offs (M ≥ 4, F ≥ 3) now live in four places:
  this equation and the branching on `phone`, `preg` and `cann`. Change one, change all four.
- **The label.** Replace the formula text in the label with a plain description, so nobody pastes
  it into the equation box again. For example: *Screening result (1 = eligible, 0 = not eligible,
  2 = not eligible, hazardous drinking; blank = screening not finished)*. The field is
  `@HIDDEN-SURVEY`, so only staff see the label.
- **Existing records.** Changing an equation doesn't recompute stored values. Anyone screened on
  the PI's version has a blank result and never reached consent. After the fix, run **Data
  Quality → rule H** ("Incorrect values for calculated fields") and fix.
- **A local script to update.** `seed-rand-test.php` writes `military`. On a project with the PI's
  dictionary, `saveData()` will reject that field.

---

## Applying it

**Where.** The export is titled "NEW TEST MICA_R01", the same title as prod PID 35968, so the
broken equation is probably live on prod now. That's likely, not confirmed: prod can't be seen from
here. **Don't paste this equation into PID 271 as-is.** 271 still has `military`, and this equation
ignores it, so it would silently drop that exclusion. It fits 271 only after the PI's dictionary
has been loaded there.

1. **Equation.** Online Designer → `screen_eligibility` → pencil on `calc_screen_result` →
   **Calculation Equation** → paste [`calc_screen_result.txt`](calc_screen_result.txt) → Update &
   Close Editor → Save. Check that REDCap reports **Valid**. In production status this is a Draft
   Mode change.
2. **Stop-action leak.** Set the `pre_screen` auto-continue condition above. It applies immediately
   with no Draft Mode, so note the current (empty) value first.
3. **Recompute.** Run Data Quality rule H.

## Reproducing

```bash
S=/var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts
# the PI's XML minus <redcap:AlertsGroup> and <redcap:SurveysSchedulerGroup> (no email/SMS locally)
docker exec redcap_2023_1_web php $S/e2e-admin-form-user.php 271 setup --create-projects
node e2e/eligibility-calc.js create /path/to/pi-xml-no-alerts.xml        # -> PID
node e2e/eligibility-calc.js run <pid> broken
node e2e/eligibility-calc.js fix <pid> docs/screening/calc_screen_result.txt
node e2e/eligibility-calc.js run <pid> fixed
docker exec redcap_2023_1_web php $S/verify-eligibility-calc.php <pid>
node e2e/eligibility-calc.js gate <pid>
node e2e/eligibility-calc.js run <pid> gated
docker exec redcap_2023_1_web php $S/e2e-admin-form-user.php 271 teardown
```

Screenshots: `e2e/shots/elig-*.png` (gitignored).

## Local state left behind

- **PID 272** "ELIG REPRO - PI XML 2026-09-25T18:11": Development. Built from the PI's XML without
  alerts or automated invitations. It has the corrected equation and the `pre_screen` gate. It's a
  scratch project; delete it when no longer needed. Its records:
  - 54 E2E records, one per browser walk, found by `s_room = E2E-<case>-<run>`.
  - `TT-tlxnj7-*` (1,011): the corrected equation, **0 mismatches**. This is the run to trust.
  - `TT-tlxno0-*` (1,011): stored while the equation was **temporarily the PI's label text** (the
    322-mismatch run). Those values are stale by design; they are not the fix misbehaving.
  - `TT-tlxnfm-*` (1,011): the batched first run, where 73 records have no `audit_c_score` (the
    harness note above).
- **PID 273** "ELIG REPRO - PI XML 1646 …": the PI's **16:46** export, built the same way. It is
  unmodified (as the PI built it) and holds 10 walked records. Use it to reproduce the 16:46
  findings.
- The throwaway user `e2e_admin_form` was removed, including its rights on 272 and 273.
- PID 271 and prod were not touched.
