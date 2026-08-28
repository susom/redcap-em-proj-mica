# Gate the consent survey on `calc_eligible = 1`

**Applied on localhost PID 257: 2026-08-27. Verified. Not yet on prod.**

---

## What was wrong

`screen_eligibility` had **Auto-continue to next survey = ON with no conditional
logic**, so *every* participant who submitted it was forwarded to `consent` —
including ineligible ones. The eligibility calculation existed and was correct; it
simply wasn't gating anything.

## The change

One setting on the `screen_eligibility` survey:

| Setting | Before | After |
|---|---|---|
| `end_survey_redirect_next_survey` (Auto-continue) | `1` | `1` (unchanged) |
| `end_survey_redirect_next_survey_logic` | *empty* | `[calc_eligible] = '1'` |

That's REDCap's native "Conditional logic for Survey Auto-Continue" — no external
module, no branching-logic workaround, no new field.

**How it behaves** (`Surveys/index.php:2046-2047`):
```php
if (trim($end_survey_redirect_next_survey_logic) == ""
    || REDCap::evaluateLogic($logic, $project_id, $record, $event_id, $instance, $form_name, $form_name))
    → redirect to the next survey
```
- empty logic → always continue (the old behaviour)
- logic true → continue to `consent`
- logic false → **no redirect**; the participant sees `screen_eligibility`'s
  end-of-survey text ("Thank you for taking the survey. Have a nice day!")

The logic is evaluated with the **current record + event** context, so unprefixed
`[calc_eligible]` is correct even though the project is longitudinal —
`screen_eligibility` and `consent` are both Day 1 instruments.

## The eligibility rule itself (unchanged)

`screen_eligibility.calc_eligible`:
```
if([age] >= 18 AND [age] <=65 AND [phone]='1' AND [military]='0'
   AND [prison]='0' AND ([preg] = '0' OR [preg] = ''), 1, 0)
```
All five inputs live on the **`screen`** form, which is submitted before
`screen_eligibility`, so the value is already computed when the gate is evaluated.

## Verification performed

`[calc_eligible] = '1'` passes `LogicTester::isValid()` — the same check
`SurveySettings.php:196` runs on save. Then the gate was exercised against real
records through the exact runtime call:

| record | event | `calc_eligible` | evaluates | behaviour |
|---|---|---|---|---|
| 1 | 1004 | `'1'` | TRUE | → continues to consent |
| 1 | 1008 | `'0'` | **FALSE** | → **stops** |
| 2 | 1004 | `'1'` | TRUE | → continues to consent |

Both branches confirmed. A backup of `redcap_surveys` for PID 257 is in the session
scratchpad as `257_surveys_pre_eligibility_gate.sql`.

---

## ⚠️ Found while making this change: the page contradicts itself

`screen_eligibility` holds three descriptive messages:

| Field | Branching logic | Text |
|---|---|---|
| `desc_eligible` | `[calc_eligible]='1'` | "Congratulations! You are eligible to participate…" |
| `desc_ineligible_hazard` | `[calc_eligible]='0'` | "…we encourage you to consider seeking further support… SAMHSA helpline…" |
| `desc_ineligible` | **none — always displays** | "Based on the information provided, **you are not eligible** to participate in our research study" |

`desc_ineligible` has **no branching logic**, so an *eligible* participant is shown
"Congratulations! You are eligible" **and** "you are not eligible" on the same page,
then auto-continues to consent. Its sibling `desc_ineligible_hazard` already carries
`[calc_eligible]='0'`, which is almost certainly what `desc_ineligible` was meant to
have.

**Not applied** — it changes participant-facing text, so it's your call. One line:

```sql
UPDATE redcap_metadata SET branching_logic = "[calc_eligible]='0'"
 WHERE project_id=257 AND field_name='desc_ineligible';
```

Worth deciding before you test the gate, or the eligible path will look broken.

---

## Applying it on production

### 🔴 This does NOT travel in the Data Dictionary

Survey settings live in `redcap_surveys`; the Data Dictionary only carries
`redcap_metadata`. So this is a **fourth** item in the same class as P1 (Twilio
scope) and P6 (event designation) — it must be applied separately on prod. See
[`alerts/PROD_PROMOTION.md`](alerts/PROD_PROMOTION.md).

### Option A — the UI (recommended, ~30 seconds)

1. Open prod PID, **Designer** (Online Designer).
2. On the `screen_eligibility` row, click **Survey settings**.
3. Scroll to **Survey Termination Options**.
4. Confirm **"Auto-continue to next survey"** is **Yes / enabled**.
5. In the field labelled
   **"(Optional) Conditional logic for Survey Auto-Continue:"**
   enter exactly:
   ```
   [calc_eligible] = '1'
   ```
6. **Save Changes.** REDCap validates the logic with `LogicTester::isValid()` on
   save and will reject a malformed expression, so a successful save is itself
   confirmation.

No Draft Mode is involved — survey settings are not versioned metadata, so this
applies immediately even on a production-status project. That also means **there is
no approval step and no undo**; note the current value before you change it.

### Option B — Survey Settings CSV import

Designer → the **gear / Survey Settings** dropdown → Export, edit the
`end_survey_redirect_next_survey_logic` column for the `screen_eligibility` row,
then Import.

⚠️ **Same delimiter trap as the alerts import.** `SurveySettings.php:91` reads the
file with `FileManager::readCSV($file, 0, User::getCsvDelimiter())` — the
logged-in user's preference, not a fixed comma. If your prod delimiter isn't `,`
this import fails too. Option A avoids the issue entirely.

*(This is a good reason to just fix the delimiter permanently in **My Profile** →
CSV delimiter → `,`. It affects the Alerts importer and the Survey Settings
importer; the Data Dictionary is the odd one out that hardcodes a comma.)*

### Verify on prod

```
Designer → screen_eligibility → Survey settings → Survey Termination Options
```
should read `[calc_eligible] = '1'`. Then walk one test record through
`screen` → `screen_eligibility` with deliberately **ineligible** answers (e.g.
`age = 70`, or `prison = Yes`) and confirm it stops at the thank-you page instead of
opening consent. Repeat with eligible answers and confirm it proceeds.

---

## Testing it on localhost

1. Public survey link for `pre_screen`, or add a record and open `screen`.
2. Answer `screen` so eligibility **fails** — e.g. `age = 70`, or `prison = Yes`.
3. Submit `screen_eligibility`.
   **Expect:** the thank-you page. **Not** consent.
4. Repeat with eligible answers (`age` 18–65, has cell phone, not military, not in
   prison, not pregnant).
   **Expect:** auto-continue straight into the consent survey.

If an ineligible participant still reaches consent, check that `calc_eligible`
actually computed — it is a calc field, so it only updates on save. `[calc_eligible]`
being empty (not `'0'`) also makes the gate false, which is the safe direction.
