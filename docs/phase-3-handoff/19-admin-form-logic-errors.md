# 19 — The `admin` form's "syntactical errors" banner on PID 257

**Status:** bucket A **applied and verified in the browser** (2026-08-25) via
[`scripts/apply-admin-calc-event-prefix.php`](scripts/apply-admin-calc-event-prefix.php).
Bucket B (delete 5 dead fields) and bucket C (4 missing tokens, researcher sign-off) are
still open. Applied on the **local docker copy only** — see §5.

**Symptom.** Opening `admin` on any record of PID 257 (`redcap.local`) shows REDCap's red
data-entry banner:

> There are syntactical errors in the Branching Logic and/or Calculations on this form.
> Branching Logic errors exist in these fields: `desc_valid_dummy_email`, `desc_group_assigned`, `desc_valid_consent_2`
> Calculation errors exist in these fields: `calc_esms_valid`, `calc_valid_fup_emails`, `first_monday`, `first_monday_1200`, `first_monday_1500`, `first_monday_1700`, `calc_week_6`, `calc_week_12`, `calc_month_3`, `calc_month_6`, `calc_month_12`, `debug_calc_2`, `debug_calc_1`

## 1. Root cause

The banner is **not** malformed syntax — every expression parses. REDCap's `LogicTester`
also resolves each `[token]` against the project's own field and event list, and flags the
field when a token names something that does not exist here. Every one of the 16 listed
fields references at least one of five non-existent things:

| Missing token | Kind | Exists in PID 257? |
|---|---|---|
| `[baseline_arm_1]` | event prefix | ❌ — this project's events are `day_1_ed`, `weeks_112`, `month_3`, `month_6`, `month_12` (arm-suffixed) |
| `[consent_date]` | field | ❌ (audit `09 §4` G3) |
| `[group]` | field | ❌ — the project has `study_group` (audit `09 §4` G6) |
| `[dummy_email]` | field | ❌ — the project has `email` on `baseline1` |
| `[time_diff]` | field | ❌ — no equivalent anywhere in the project |

This is already recorded as a reference-integrity finding in
[`09-pid-257-structure-audit.md §5`](09-pid-257-structure-audit.md). Doc 19 exists because
that audit deliberately proposed no fixes; this one maps the user-visible banner onto the
audit and works out what the repair actually is.

**Provenance.** All five tokens exist together in exactly one other project on this
instance — **PID 192 (ASPIRE)**, where `dummy_email` is on `check`, `group` is a radio on
`unblinded_randomization`, `time_diff` is a calc on `check`, `consent_date` is on `consent`,
and arm 1's first event is literally named `Baseline` → `baseline_arm_1`. PID 257's `admin`
form was copied from ASPIRE during the 2026-08-04 rebuild and the field/event references
were never remapped.

**Confirming the diagnosis (negative control).** The token theory predicts the error set
exactly, in both directions. The `admin` form's *other* logic-bearing fields —
`desc_valid_phonen`, `desc_valid_birth_sex`, `calc_monday_diff` (`@CALCTEXT` on `[weekday]`)
and `weekday` itself (bare `[randomization_date]`) — are **absent** from the banner, because
`phonen`, `birth_sex`, `weekday` and `randomization_date` all do exist. No field with only
resolvable tokens is listed; no listed field lacks an unresolvable one.

Two things that are **not** contributing, checked explicitly:

* `[study_withdrawn(1)]` and `[sms_stop(1)]` in `calc_esms_valid` are valid — both
  checkboxes define choice code `1`.
* `debug_calc_1`'s 4-argument `datediff(..., 'd', true)` is an accepted signature.

## 2. Form placement — why the repair is a *bare* reference

```
admin     → events 1004 / 1008 / 1012  (Day 1 (ED), one per arm 1/2/3)
baseline1 → events 1004 / 1008 / 1012  (same three)
```

`admin` sits on **exactly one event per arm**, and `baseline1` shares those same events. So:

* The correct fix for the `[baseline_arm_1][randomization_date]` references is to **drop the
  event prefix entirely** — `[randomization_date]` resolves within the current event on all
  three arms.
* Substituting a concrete event name (`[day_1_ed_arm_1]`) would be **wrong**: it would pin
  the calc to arm 1 and silently yield nothing for arms 2 and 3.
* `weekday` on this same form already uses bare `[randomization_date]` and validates
  cleanly — an in-project precedent for the fix, not just a theory.
* `calc_valid_fup_emails`'s bare `[email]` is fine as written, since `baseline1` co-habits
  the event.

## 3. The 16 fields, in three buckets

### Bucket A — event prefix only; mechanical and safe (4 fields) — ✅ APPLIED

`randomization_date` exists; only the `[baseline_arm_1]` prefix is bad. Dropping it is
behaviour-preserving relative to intent and needs no research input.

| Field | Current `misc` | Proposed |
|---|---|---|
| `calc_month_3` | `@CALCDATE([baseline_arm_1][randomization_date],91, 'd')` | `@CALCDATE([randomization_date],91, 'd')` |
| `calc_month_6` | `@CALCDATE([baseline_arm_1][randomization_date],182, 'd')` | `@CALCDATE([randomization_date],182, 'd')` |
| `calc_month_12` | `@CALCDATE([baseline_arm_1][randomization_date],365, 'd')` | `@CALCDATE([randomization_date],365, 'd')` |
| `first_monday` | `@CALCDATE([baseline_arm_1][randomization_date], [calc_monday_diff], 'd')` | `@CALCDATE([randomization_date], [calc_monday_diff], 'd')` |

Applied 2026-08-25 by [`scripts/apply-admin-calc-event-prefix.php`](scripts/apply-admin-calc-event-prefix.php);
results in §6, backfill obligation in §5.1.

`calc_month_3` is the **load-bearing** one: [`10-auth-implementation-pid257.md:323`](10-auth-implementation-pid257.md)
names it as the source for the Month-3 booster ASI window, and flags this exact broken
reference as the blocker.

### Bucket B — already self-labelled dead; recommend deletion, not repair (5 fields)

Per [`09 §5`](09-pid-257-structure-audit.md) ("intentional cruft"), these are not defects.
Repairing them would mean inventing a `consent_date` and a `time_diff` for fields nobody
intends to keep.

| Field | Why dead | Also needs |
|---|---|---|
| `calc_week_6` | label prefixed `DELETE:` | `consent_date` |
| `calc_week_12` | label prefixed `DELETE:` | `consent_date` |
| `first_monday_1500` | label prefixed `NOT_USED:` | `time_diff` |
| `debug_calc_1` | debug scaffolding | `consent_date` |
| `debug_calc_2` | debug scaffolding | `consent_date` |

Deleting these five clears 5 of the 13 calculation errors at zero research cost.

### Bucket C — needs a researcher decision; do not guess (7 fields)

Each of these references a field that has **no** unambiguous equivalent in PID 257. The
substitution is a research decision, not a rename.

| Field | Missing token | The open question |
|---|---|---|
| `desc_valid_dummy_email`, `calc_esms_valid` | `dummy_email` | Is the real `baseline1.email` the intended target? ASPIRE's field is explicitly a *dummy* address — it may have been a test-harness field with no R01 counterpart. |
| `desc_group_assigned`, `calc_esms_valid`, `calc_valid_fup_emails` | `group` | `study_group` (radio, codes `1`/`2`/`3` = arm numbers) is the obvious candidate, but the comparison values in each expression have to be re-checked against those codes. Owner is the researcher (`09 §4` G6). |
| `desc_valid_consent_2`, `calc_valid_fup_emails` | `consent_date` | G3 — no consent-date field exists. Phase-3's plan moves session windows onto `randomization_date`; whether these two validators follow or are dropped is unsettled. |
| `first_monday_1200`, `first_monday_1700` | `time_diff` | No timezone-offset field exists in this project. Either add one or drop the offset from the expressions. |

## 4. Impact of the fields still broken (buckets B and C)

The banner is a warning, not a save-blocker — the form still saves. The consequence is that
**each listed field's logic does not run**: the three `desc_*` descriptive alerts never show
or hide, and the calcs never populate. So the fields read as blank/absent rather than wrong,
which is why this stayed invisible until someone opened the form. It also leaks to the screen
as unresolved piping — the `admin` form currently displays labels like *"Consent Date:
`[baseline_arm_1][consent_date]`"* verbatim to whoever opens it.

The one item with downstream reach was `calc_month_3` (Month-3 booster ASI window), which
bucket A has now fixed. Nothing left in buckets B or C is load-bearing for phase-3 code.

## 5. Applying a fix

Buckets A and B are dictionary edits to the `admin` form only. PID 257 is
`status = 0` (development), `draft_mode = 0`, so `redcap_metadata` can be edited directly
without a draft/approval cycle.

The repo's convention for this is a script under [`scripts/`](scripts/) — see
`apply-study-group-field.php` / `apply-safety-finding-instrument.php`. A fix should follow
that pattern (idempotent, prints a before/after diff, `--dry-run` first) rather than being a
hand-run `UPDATE`.

**Scope guard.** `[baseline_arm_1]` has **39 references project-wide** (`audit.audit3_b`,
`sunday.bd_1..bd_11`, and 35 more). Only the 4 on `admin` are in scope here. The rest are a
separate, researcher-owned cleanup.

**This is the local docker dev copy.** Any dictionary change made here does not reach the
real instance — the study team has to apply it there separately.

### 5.1 Existing records need a backfill — on the real instance, not here

Fixing the action tag fixes the *metadata*, not the *data*. `@CALCDATE` evaluates when a form
is rendered and persists when it is **saved**, so a participant randomized before the fix
keeps a blank `calc_month_3/6/12` in `redcap_data` until something re-saves them. Applying the
fix without a backfill silently repairs new enrollments only — and `calc_month_3` is the
Month-3 booster ASI window.

On this dev copy there is nothing to backfill, confirmed rather than assumed:

```sql
-- 2 records in PID 257; 0 with a randomization_date; 0 with any of the four fields populated
SELECT COUNT(DISTINCT record) FROM redcap_data
WHERE project_id = 257 AND field_name = 'randomization_date' AND value <> '';
```

**On the production instance this count will not be 0**, so run that query first, then use
**Data Quality → rule H ("Calculations") → Fix Calculations**.

Rule H *does* cover action-tag calcs in REDCap 17.2.3, on the **fix** path and not just the
display path. This is worth spelling out because it is easy to assume rule H only handles
`element_type = 'calc'` fields — which would leave `weekday` (a real `calc`) populated and the
`@CALCDATE` dates still blank, reading as "the metadata fix didn't work". Traced end to end:

1. `DataQuality.php:1322` — with no field filter on the rule, `$calc_fields = []`.
2. `DataQuality.php:1330` — *Fix Calculations* (`action == 'fixCalcs'`) calls
   `Calculate::saveCalcFields()`. (`:1338` is the *else* branch: display-only discrepancy
   listing. Both paths matter, but it is `:1330` that writes.)
3. `Calculate::saveCalcFields()` (`Classes/Calculate.php:527`) does no field selection of its
   own — it delegates to `calculateMultipleFields()` at `:542`.
4. `calculateMultipleFields()` defaults an empty list to *all* metadata (`:138`) and branches
   explicitly on `isCalcDateField()` / `isCalcTextField()` (`:141-143`) alongside
   `element_type == 'calc'`.

So rule H is sufficient and a per-record re-save is not needed.

**Run it deliberately, not as a diagnostic click.** *Fix Calculations* is a bulk data write
across live study records, and it writes a per-record entry to the audit trail. Scope the rule
to the four fields if the intent is only this fix.

## 6. Verification — what was actually applied and observed

SQL confirming that tokens resolve is not sufficient; it does not exercise `LogicTester`,
which is what produces the message. So this was checked by loading record 1's `admin` form
in a real browser (Playwright, Chromium) as an **ordinary data-entry user** — not as an
admin — using the throwaway account from
[`scripts/e2e-admin-form-user.php`](scripts/e2e-admin-form-user.php) (`setup` / `teardown`;
the account was torn down afterwards and its removal confirmed).

**Banner, before → after.** The branching-logic line is unchanged, as predicted — all three
of those fields are bucket C. The calculation line dropped from 13 fields to 9; the four
removed are exactly bucket A.

| | Calculation errors listed |
|---|---|
| before | `calc_esms_valid`, `calc_valid_fup_emails`, **`first_monday`**, `first_monday_1200`, `first_monday_1500`, `first_monday_1700`, `calc_week_6`, `calc_week_12`, **`calc_month_3`**, **`calc_month_6`**, **`calc_month_12`**, `debug_calc_2`, `debug_calc_1` |
| after | `calc_esms_valid`, `calc_valid_fup_emails`, `first_monday_1200`, `first_monday_1500`, `first_monday_1700`, `calc_week_6`, `calc_week_12`, `debug_calc_2`, `debug_calc_1` |

Project-wide `[baseline_arm_1]` references went 39 → 35, i.e. only the four intended fields
changed.

**The calcs now actually compute.** Clearing the banner is necessary but not sufficient — a
field can validate and still produce nothing. `randomization_date` is blank on record 1, so
the four fields were exercised by typing a date into the form and letting `@CALCDATE` fire
client-side (then leaving without saving):

| Field | Value with `randomization_date = 2026-08-25` | Expected |
|---|---|---|
| `weekday` | `2` | Tuesday (formula anchors on 2018-01-01, a Monday) |
| `calc_monday_diff` | `-1` | `1 - weekday` |
| `first_monday` | `2026-08-24` | see note below |
| `calc_month_3` | `2026-11-24` | +91 d ✓ |
| `calc_month_6` | `2027-02-23` | +182 d ✓ |
| `calc_month_12` | `2027-08-25` | +365 d ✓ |

Before the fix all six were blank (`calc_monday_diff` read `NaN`).

**Verified on all three arms, not just arm 1.** §2's argument — that a bare reference is
correct *because* `admin` is on one event per arm — is the load-bearing part of the repair, so
it was checked rather than reasoned about. Record 1's `admin` form was loaded at all three
designated events (1004 / 1008 / 1012). None of the four fixed fields is flagged on any arm,
and all four compute the same values on each:

| | arm 1 (ev 1004) | arm 2 (ev 1008) | arm 3 (ev 1012) |
|---|---|---|---|
| any of the 4 still flagged? | no | no | no |
| `calc_month_3` / `_6` / `_12` | 2026-11-24 / 2027-02-23 / 2027-08-25 | same | same |
| `first_monday` | 2026-08-24 | same | same |

This is precisely what a substituted `[day_1_ed_arm_1]` would have broken, and it is the
reason `apply-admin-calc-event-prefix.php` aborts if the form is ever on more than one event
per arm.

### Two things noticed while verifying — both pre-existing, both out of scope

**`first_monday` can precede randomization, which feeds SMS send times.** It resolved to
2026-08-24, the Monday *before* a Tuesday randomization. That is what `calc_monday_diff`'s
formula specifies (`if([weekday]>=4, 8-[weekday], 1-[weekday])` snaps Mon–Wed backward and
Thu–Sun forward) and its label says so outright: *"Days difference to next Monday (if
Thursday or more) or current Monday (less than Thursday)"* — so it is intentional
nearest-Monday behaviour, untouched by this fix. It is worth raising anyway because
`first_monday` feeds `first_monday_1200/1500/1700`, which are **SMS send times**: a Monday,
Tuesday or Wednesday randomization therefore produces a first send slot dated *before* the
participant was randomized. Scheduling behaviour for the researcher to confirm, not a label
nitpick — and note that `first_monday`'s own label ("First Monday **after** Randomization
Date") describes the opposite.

**`calc_valid_fup_emails` is flagged on arm 1 only.** Reproducible: 8 calculation errors on
arms 2 and 3 versus 9 on arm 1, stable across two passes and in reversed arm order, so not a
page-load race. Both forms it reads from (`baseline1` for `[email]`/`[phonen]`, `screen` for
`[birth_sex]`) are designated on all three events, so form designation is not the
explanation. The mechanism was **not** chased down — it is a bucket-C field that stays broken
either way until `[group]` and `[consent_date]` are resolved. Recorded so that whoever fixes
bucket C is not surprised by an error count that varies by arm. A plausible-but-unverified
cause: `@CALCTEXT` contents are piped before being validated (`Classes/Calculate.php`
`feedEquation`, *"in case piping has resulted in a static value"*), so the equation actually
handed to the validator can differ depending on which data exists in the event.
