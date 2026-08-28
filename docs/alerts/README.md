# MICA (PID 257) — Alerts & Notifications build

**Built:** 2026-08-26, revised 2026-08-27. Localhost, REDCap 17.2.3
**Result:** 21 alerts created in PID 257, **all 21 deactivated**. 10 prerequisite
changes applied to the project.

Companion audit (why each decision was made, and what ASPIRE PID 262 actually
does): [`../ALERTS_AUDIT_257_vs_262.md`](../ALERTS_AUDIT_257_vs_262.md)

| File | Purpose |
|---|---|
| `build_alerts_csv.py` | Source of truth. Regenerates the CSV from a spec. Edit this, not the CSV. |
| `MICA_257_alerts_import.csv` | REDCap-format alert import (21 rows, 41 columns). |
| `MICA_257_prereq_migration.sql` | The 10 project/metadata changes, with verification + rollback. |
| `TEST_PLAN_LOCALHOST.md` | Step-by-step end-to-end test of all 7 requirement groups on localhost. |
| `test_helpers.sql` | Watch/activate/time-travel/reset SQL used by the test plan. |
| `PROD_PROMOTION.md` | Promoting to production: the two DD-upload errors, what the DD does **not** carry, and the order of operations. |

---

## ⚠️ Nothing is live

All 21 alerts have `alert-deactivated = Y` (`redcap_alerts.email_deleted = 1`).

**PID 257 has real Twilio credentials** — SID `ACe1be19…`, from number
`6504075535`, and the migration switched `twilio_modules_enabled` to
`SURVEYS_ALERTS`, which is what makes `alert_type='SMS'` deliverable. Activate
one alert at a time and confirm each send before enabling the next.

---

## What was built

Timings are days from `admin.randomization_date` — the anchor chosen by the PI.

### Requirement 1 — confirm the phone number with a passcode
| # | Type | Fires |
|---|---|---|
| 01 | SMS | On **`baseline1`** submit at any Day 1 event, when `consent` + `baseline1` are both Complete, and `phonen`/`calcrnd` are non-empty |

Texts `[calcrnd]` to `[phonen]`; the participant types it into
`check_code.passcode`, and the pre-existing
`calc_code_check = if([calcrnd]=[passcode],1,0)` confirms the number.
Sends once per record. **✅ Verified end-to-end 2026-08-27** (record 3).

Deliberately fires at enrollment rather than after the baseline battery, so a wrong
number is caught while the participant is still reachable — see O4 below.

### Requirements 2–4 — MICA ED session ladder (Day 1, arms 2 & 3)
| # | Type | Day | Recipient |
|---|---|---|---|
| 02 | EMAIL | +7 | participant |
| 03 | SMS | +7 | participant |
| 04 | EMAIL | +14 | **CRC — "please call"** |

The +1-day rung was dropped on 2026-08-27 at the PI's direction: the ED session
happens minutes after randomization with a CRC present, so a next-day "your
session is ready" message had no job distinct from the +7 nudge — both were
really "walked out mid-session" recovery. What remains maps 1:1 onto the original
requirements: one reminder at +1 week (req 3), CRC call at +2 weeks (req 4).

One alert per rung covers **both** arms: every field referenced
(`randomization_date`, `mica_ed_session_complete`, `choice_fup_delivery`,
`email`, `phonen`, `sms_stop`, `study_withdrawn`) lives in the same Day 1 event,
so nothing needs an event prefix.

### Requirements 2–4 — MICA booster session ladder (Month 3, arms 2 & 3)
| # | Type | Day | Arm | Recipient |
|---|---|---|---|---|
| 05 / 10 | EMAIL | +92 | 2 / 3 | participant |
| 06 / 11 | SMS | +92 | 2 / 3 | participant |
| 07 / 12 | EMAIL | +98 | 2 / 3 | participant |
| 08 / 13 | SMS | +98 | 2 / 3 | participant |
| 09 / 14 | EMAIL | +105 | 2 / 3 | **CRC — "please call"** |

Duplicated per arm — see "Why the booster ladder is per-arm" below.
+92 = Month 3 (`randomization_date + 91`, per `admin.calc_month_3`'s label) + 1 day.

### Ported from ASPIRE
| # | Type | Recipient | ASPIRE origin |
|---|---|---|---|
| 15 | EMAIL | participant, **+ signed consent PDF attached** | 5604 |
| 16 | EMAIL | CRC — consented | 5605 |
| 17 | EMAIL | CRC — baseline complete | 5606 |
| 18 | EMAIL | CRC — Month 3 follow-up done | 5607 |
| 19 | EMAIL | CRC — Month 6 follow-up done | 5608 |
| 20 | EMAIL | CRC — Month 12 follow-up done | 5623 |
| 21 | EMAIL | CRC — SMS opt-out (Twilio STOP) | 5625 |

**Not ported:** ASPIRE 5609–5622 (14 "ESMS Bulk week N" alerts with hardcoded
`[record-name] = "1683" OR …` ID lists) and 5624. Those are manual rescue blasts,
not a design — and the audit explains why they exist.

---

## How the ladder actually stops

`ensure_logic_still_true = 1` on all 14 scheduled alerts. This is the load-bearing
mechanism and it was verified in source, not assumed:

`Alerts.php:1051-1058` — the cron re-evaluates `alert_condition` at send time and,
if it has gone false, **deletes the queued recurrence** instead of sending.

So a participant who finishes the session on day 3 has
`[mica_ed_session_complete]='2'`, the day-7 and day-14 rows evaluate false, and
both the nudge and the CRC escalation are cancelled. Without this flag every
participant would be nudged and escalated regardless of completion, which is
worse than having no ladder at all.

`alert_stop_type = RECORD_EVENT` (not `RECORD`) so the ED and booster ladders
don't suppress each other.

## Channel split — email vs SMS

`choice_fup_delivery` (`baseline1`, checkbox: `1, Email to [email]` /
`2, SMS to [phonen]`) already existed in both projects.

Because it's a **checkbox**, a participant can tick both or neither. Per the PI's
decision (SMS wins):

```
SMS    ([choice_fup_delivery(2)]='1' or ([choice_fup_delivery(1)]<>'1' and [choice_fup_delivery(2)]<>'1'))
EMAIL  ([choice_fup_delivery(1)]='1' and [choice_fup_delivery(2)]<>'1')
```

Mutually exclusive and jointly exhaustive — exactly one channel always fires.
ASPIRE gated its two ASIs independently on `(1)='1'` and `(2)='1'`, so in
production anyone ticking both got duplicates and anyone ticking neither got
nothing. Both existing MICA test records have **both** boxes ticked, so both
route to SMS under this rule.

## Suppression

Every participant-facing alert carries `[study_withdrawn(1)]<>'1'`, and every SMS
also carries `[sms_stop(1)]<>'1'`. ASPIRE applied these to its ASIs but not to its
staff alerts. Alert 21 tells the CRC when opt-out fires — the suppression itself
is automatic.

## Why the booster ladder is per-arm and the ED ladder is not

The booster alerts fire at Month 3, but `randomization_date` and the contact
fields live at Day 1, so they need an event prefix. `[first-event-name]` would
collapse both arms into one alert and **does resolve at runtime**
(`Piping.php:725`, `LogicLexer.php:143`, and `send-on-field` is piped at
`Alerts.php:1176`).

The **importer is stricter than the runtime.** `validateDateTimeFields`,
`getPhoneFieldsList` and `getEmailsList` each build their whitelist as `[field]`
plus `[literal_event_name][field]` only. Verified empirically — the
`[first-event-name]` version was rejected with *"invalid date time field value for
send-on-field"* plus invalid phone/email errors on exactly those rows. Hence
literal `[day_1_ed_arm_2]` / `[day_1_ed_arm_3]` prefixes, and two alerts per rung.

## Record links

CRC alerts use `[form-link:<form>:…]`, a real smart variable resolved against the
running REDCap version. ASPIRE hardcoded absolute URLs and they have already
rotted — its alerts still point at `redcap_v15.8.0` / `v16.0.0` / `v16.1.0` and
`pid=31710`. The named form must exist at the firing event: `admin` is Day 1 only,
`close` is on every event.

---

## Verification performed

- **All 21 conditions** pass `LogicTester::isValid()` — REDCap's own logic parser.
  Alert 01's revised (O4) condition was re-validated after the change: `TRUE`.
- **All 21 rows** were accepted by `Alerts::validateCSVContent()` with zero errors
  *when originally imported*. ⚠️ This cannot be re-run as a check — that function
  **performs the import**, it is not a dry run. The current file's integrity is
  instead verified structurally: 22 × 41 grid, `alert-unique-id` blank and
  `alert-deactivated=Y` on every row, and **0 drift** against the 21 live alerts.
- `prevent_piping_identifiers = 0` on exactly the 3 CRC escalations (04/09/14),
  which pipe the PHI-flagged `phonen`/`email` so the CRC can place the call; `1`
  on the other 18.
- `cron_repeat_for` non-null on all 21; `field_order` in `redcap_metadata` still a
  gapless 1–299 after inserting `dummy_email`.

### Live end-to-end status — updated 2026-08-27
1. ~~An SMS leaves Twilio and arrives~~ — ✅ **confirmed.** Alert 01 delivered a
   correct 4-digit `[calcrnd]` to a real mobile, and `calc_code_check` computed `1`.
   **But against an edited alert — see O4 below.**
2. ~~`[survey-link:…]` / `[survey-url:…]` render correct, working links~~ — ✅
   **confirmed 2026-08-27.** Alert 02's email carried one link resolving to
   `mica_ed_session` / event 1008 / record 3; fetched it and got HTTP 200 with page
   title *"MICA ED session"*. `[first_name]` piped correctly in the body.
2b. ~~The email/SMS channel split is exclusive~~ — ✅ **confirmed 2026-08-27.** All
   four preference states exercised on a live record. In every SMS case alert 02
   never queued, and MailHog ended with exactly **1** message across the whole run,
   so no email leaked. **SMS wins when both are ticked; SMS is the fallback when
   neither is** — the two cases ASPIRE got wrong.
3. ~~The day-7 → day-14 cancellation actually fires when the session completes~~ — ✅
   **confirmed 2026-08-27, with the counterfactual.** Both rungs reached DUE, then
   completing `mica_ed_session` caused the cron to delete both queued rows and send
   nothing. Re-running with **only** the completion flag reverted sent both — so the
   cancellation is causal, not incidental. **Cancellation happens at cron time, not
   save time** (see the Test 4 result for why that window matters).
3b. ~~CRC escalation reaches the CRC with usable contact details~~ — ✅ **confirmed.**
   Alert 04 delivered with phone *and* email piped (`prevent_piping_identifiers=0`)
   and a correct `[form-link:admin:…]` pointing at record 3 / event 1008.
4. ~~`[form-link:close:…]` resolves at the Month 3 event~~ — ✅ **confirmed 2026-08-27.**
   Alerts 09/14 linked to `page=close` at events 1009/1014 respectively. Also
   confirmed `admin` is genuinely not designated at Month 3, which is the reason
   these two use `close`.
5. ~~The booster ladder is arm-isolated~~ — ✅ **confirmed.** Two records, one per
   arm: record 3 (arm 2) queued only 05/07/09, record 4 (arm 3) only 10/12/14. Zero
   cross-firing, so the `[day_1_ed_arm_N]` cross-event prefixes are right.
   `study_group` piped as "MICA" vs "MICA + Weekly SMS", independently confirming
   the arms are distinct.

**Requirements 1–4 are now all verified end-to-end.** Alerts **01–14** are tested.
Alerts **15–21** (the ASPIRE ports) are not — see O6.

**Two traps that will look like bugs:**
- Both existing test records have `baseline1_complete = 1` (Unverified). Every
  gate is written against `'2'`. Nothing fires until these are Complete.
- Record 1 holds `baseline1`/`admin` data at **both** event 1004 (arm 1) and 1008
  (arm 2), with two different `calcrnd` values. That is a fixture artifact — a
  real record lives in one arm. Use a fresh single-arm record for E2E testing.

---

## Findings raised after the build

### ~~O1. The `close` form is branch-broken at every event~~ — RESOLVED 2026-08-27

All four `close` descriptives were branched on ASPIRE event names, so the form
rendered as a **completely blank survey** at every Day 1 event and, for arms 2 & 3,
at every follow-up event too. `desc_baseline_end` branched on `baseline_arm_1` —
an event that does not exist in MICA at all.

Fixed as migration step **P8**, rewritten against MICA's real event names across
all arms ("MICA has correct events, no need to match ASPIRE"). All four new
branching expressions pass `LogicTester::isValid()`.

| Field | Now shows at |
|---|---|
| `desc_baseline_end` | `day_1_ed_arm_1` / `_2` / `_3` |
| `desc_3m_end` | `month_3_arm_1` / `_2` / `_3` |
| `desc_6m_end` | `month_6_arm_1` / `_2` / `_3` |
| `desc_12m_end` | `month_12_arm_1` / `_2` / `_3` |

This matters beyond cosmetics: `close` is the end-of-baseline marker that alerts
**17–20** trigger on, and it is what tells the participant "You have completed the
baseline assessment." *(Alert 01 gated on it too until O4 moved it to `baseline1`.)*

### ~~O2. Does the ED session really want four reminders?~~ — RESOLVED 2026-08-27

No. The +1-day rung (former alerts 02/03) was removed; the ED ladder is now a
single +7 reminder on both channels plus the +14 CRC call. See the ED table above.

### ~~O8. A 22nd alert appeared, and its condition is invalid~~ — CONDITION FIXED 2026-08-27

**Applied.** `alert_id 5671` now reads:

```
([calc_eligible] = '0') AND ([days_binge] > 0
   OR ([drinksperweek]>=7 AND [birth_sex]=0)
   OR ([drinksperweek]>=14 AND [birth_sex]=1))
```

`LogicTester::isValid()` → **TRUE** (was FALSE). Both defects addressed: the missing
`[` on `birth_sex`, and the precedence bug that let the ineligibility test gate only
the first of three risk clauses.

**Still deactivated, and two things remain unresolved — do not activate yet:**

1. **`email_to = [email]` is on `baseline1`, which an ineligible participant never
   reaches.** Under the eligibility gate they stop at `screen_eligibility`
   ([`../ELIGIBILITY_GATE.md`](../ELIGIBILITY_GATE.md)), so the recipient field is
   empty exactly when the alert should fire. It needs an email captured on `screen`
   or `pre_screen` instead.
2. **`ensure_logic_still_true = 0`**, unlike every alert in this build.

**It is also not in `MICA_257_alerts_import.csv`** (21 rows; this is a 22nd alert
created in the UI). The fix above exists **on localhost only** — it will not travel
to prod with the alerts CSV. Either add it to `build_alerts_csv.py` once items 1 and 2
are settled, or recreate it by hand on prod.

<details>
<summary>Original finding (for the record)</summary>

The condition as created was:

```
[calc_eligible] = 0 AND [days_binge] > 0 OR ([drinksperweek]>=7 AND [birth_sex]=0)
                                         OR ([drinksperweek]>=14 AND birth_sex]=1 )
```
</details>

### O7. Form-triggered alerts never fire on an API / data-import save — 18/19/20 FIXED 2026-08-27

**Applied to alerts 18, 19, 20** (the follow-up-done notifications), in both the
database and `build_alerts_csv.py`:

| | Before | After |
|---|---|---|
| `alert-trigger` | `SUBMIT-LOGIC` | **`LOGIC`** |
| `unique-form-name` / `form_name` | `close` | **empty / NULL** |

Verified the fix actually works rather than assuming: alert 18 was re-run against
record 4 at event 1014 via `REDCap::saveData()` — **the exact import path that
previously skipped it entirely** — and it delivered *"MICA 4: Month 3 follow-up
done"* to the CRC, MailHog delta 1. Before the change the same call produced nothing.

The condition is unchanged and still pins the event and requires
`close_complete = '2'`, so behaviour on a normal UI save is identical; the form
trigger was redundant and was the only thing costing import compatibility.

Regenerated the CSV and all five delimiter variants. **DB vs CSV drift: 0** across
all 21 (the 6 SMS `subject` differences remain the known benign case).

**Still form-triggered — a deliberate remaining choice:**

| # | Form | Exposure if that form is ever populated by API/import |
|---|---|---|
| **01** | `baseline1` | No passcode SMS. `ONCE`/`RECORD`-scoped, so it never retries. |
| **15** | `person_obtaining_consent` | Participant never receives their signed consent PDF. |
| **16** | `consent` | CRC not told the participant consented. |
| **17** | `close` | CRC not told baseline is complete. |

These four are all Day-1 forms driven by a person at a keyboard, so the exposure is
much lower than for the Month 3/6/12 follow-ups that were fixed. Worth revisiting if
enrollment data ever starts arriving by API.

<details>
<summary>The underlying mechanism</summary>

Found during Test 7. `Alerts.php:193` forces the instrument to `""` when
`$isDataImport` is true, and `getAlertsForInstrumentSave` can then only match
logic-only alerts (`form_name IS NULL`). `Records::saveData` always passes
`$isDataImport = true`.

As originally found, the split was:

| Skipped on API/import | Immune (logic-only) |
|---|---|
| **01** (`baseline1`), **15** (`person_obtaining_consent`), **16** (`consent`), **17, 18, 19, 20** (`close`) | 02–14, 21 |

18/19/20 have since been moved to the immune column. Full trace in
[`TEST_PLAN_LOCALHOST.md`](TEST_PLAN_LOCALHOST.md#-two-mechanism-findings-from-test-7--both-matter-beyond-testing).

</details>

### O6. Does MICA actually want the 7 ASPIRE ports? — recommendation, 2026-08-27

Alerts **01–14** implement the four stated requirements and are now fully tested.
Alerts **15–21** are extras, ported because the brief asked which ASPIRE
notifications could be reused. None of them was a stated requirement, so keeping
them is a **choice**, not an obligation.

The cost of keeping them is not zero: **16, 17 and 21 are `RECORD`-scoped and 18–20
are `RECORD_EVENT`-scoped, all pointed at one inbox.** That is up to 6 CRC emails per
participant over the study, on top of the 3 escalations. At full enrolment that
inbox becomes noise, and noisy inboxes get filtered — which is how the escalations
that *do* matter (04/09/14) get missed.

| # | Alert | Recommendation | Why |
|---|---|---|---|
| **15** | Participant — signed consent PDF | **Keep and test** | The most valuable of the seven: participant-facing, arguably a regulatory nicety, and the **only alert with a file attachment** (`email_attachment_variable = [consent_pdf]`). Attachments fail silently, and no other test covers that mechanism. |
| **17** | CRC — baseline complete | **Keep and test** | Triggers on `close` at Day 1 — the same trigger alert 01 was *moved off* in O4. Worth confirming it still behaves now that `close` no longer gates the passcode. |
| **16** | CRC — participant consented | **Keep, spot-check only** | Fires once per record right before 17. Ask the CRC whether they want both "consented" and "baseline complete", or just the latter. |
| **18, 19, 20** | CRC — Month 3 / 6 / 12 follow-up done | **Keep, test ONE** | Identical shape, differing only in the event pinning — and Test 6 already proved event-pinned per-arm conditions resolve correctly. Testing all three buys nothing. |
| **21** | CRC — SMS opt-out | **Do not test as designed — it cannot fire** | Blocked by **O5** (nothing writes `sms_stop`) *and* **O3** (its other clause is arm-3-only). Resolve O5 first; until then this alert is dead for arms 1 and 2. |

So the honest scope for a "Test 7" is **three checks, not seven**: alert 15's
attachment, alert 17's trigger, and one of 18/19/20. Alert 21 is blocked, and 16 is a
product question for the CRC rather than an engineering one.

Also unresolved for all of these: they still point at the
`micastudy@stanford.edu` **placeholder** (open item 1 below).

### 🔴 O5. `sms_stop` has no automatic writer — the opt-out gate is manual everywhere

Raised 2026-08-27 while scoping Test 7. **This is not a localhost limitation; it
applies to production too.**

The `admin.sms_stop` field's own label promises otherwise:

> *"When participant texts STOP, this field gets updated. This will STOP texts, but
> keeps other assessments on-going"*

Nothing updates it. Searched for STOP / opt-out keyword handling in
`Classes/TwilioRC.php` and `Surveys/twilio_question.php` (REDCap's inbound Twilio
handler) — **no matches**. Swept the module tree for `sms_stop`: every hit is a
`.md` document, no PHP. Only `proj_mica` is enabled on PID 257, and it does not
write the field either.

So `sms_stop` is, in practice, **a manual CRC checkbox**.

**What that means in production:**

| Layer | Behaviour |
|---|---|
| **Twilio** | Handles STOP itself. The number is blocked at Twilio's end and subsequent sends fail with error **21610**. The participant *is* protected — texts genuinely stop. |
| **REDCap** | Never learns about it. `sms_stop` stays `0`, so all 6 SMS alerts keep firing, each failing at Twilio. |
| **Alert 21** (CRC opt-out notification) | Fires on `[sms_stop(1)]='1'`, so it **never fires** from a real STOP. |
| Failure visibility | 21610s land in `redcap_twilio_error_log`; the alert itself logs nothing (see the invisible-failure note). |

Consequences to decide on before go-live:

1. **Alert 21 is effectively dead** for the STOP path unless someone ticks the box by
   hand. Combined with O3 (its second clause is arm-3-only), it currently has no
   working trigger for arms 1 and 2.
2. **`[sms_stop(1)]<>'1'` on the 6 SMS alerts is a real suppression gate only if the
   CRC maintains it.** Worth adding to the CRC's SOP: *on any STOP report or 21610,
   tick `sms_stop`.*
3. **Or close the loop properly** — point Twilio's inbound webhook at something that
   writes `sms_stop` (a small EM endpoint, or Twilio Studio → REDCap API). That is
   the only way the field matches its label.
4. **Monitor `redcap_twilio_error_log` for 21610** regardless — it is the only signal
   that a participant opted out.

**Testing implication:** don't text STOP to the study number to test this. Twilio
would block that handset for the study's sender permanently until it texts
START/UNSTOP, ending SMS testing. Set `sms_stop` directly instead, which is exactly
what the alert reads.

### O3. Alert 21 covers less than its title suggests

It fires on `[sms_stop(1)]='1'` (the real Twilio STOP signal, all arms) **or**
`[weeks_112_arm_3][sms_opt_out_complete]`. That second clause only exists for
arm 3 — event 1013 is arm-3-only — so for arms 1 and 2 it is permanently false.
Net effect: **STOP is covered for everyone, the opt-out survey for arm 3 only.**
That is correct behaviour, just narrower than "opt-out received".

### ~~O4. Alert 01 differed between localhost and the prod CSV~~ — RESOLVED 2026-08-27

Alert 01 was modified in the UI on 2026-08-27 at 11:09 and 11:11
(`redcap_log_event15`, `Modify alert` × 2 on A-5649), minutes before the Test 1
send, leaving localhost and `MICA_Alerts_UPLOAD_TO_PROD.csv` out of step.

**Decision: keep the tested behaviour — trigger on `baseline1`.**

| | Was (as originally specced) | Now |
|---|---|---|
| Trigger form | `close` | **`baseline1`** |
| Condition | …`and [close_complete]='2'` | **clause dropped** |

Rationale: verifying the phone *before* the participant sits through the baseline
battery catches a wrong number while they are still reachable. The `close` version
only discovers it at the very end, when the participant has already left. This
supersedes the earlier design answer ("after consent + baseline1 + BL battery").

`build_alerts_csv.py` and all five delimiter variants in `~/Downloads` were
regenerated. Verified after the change:

- **exactly one line** of `MICA_257_alerts_import.csv` changed, in exactly the two
  intended fields
- **DB vs CSV drift across all 21 alerts: 0** (the six SMS `subject` differences
  are REDCap storing NULL subjects for SMS alerts — systematic and benign)
- all five variants round-trip to identical 22 × 41 grids; `alert-unique-id` blank
  and `alert-deactivated=Y` on every row
- the revised condition passes `LogicTester::isValid()`; `baseline1` is a real form
  and is designated at 1004/1008/1012

Test 1 therefore stands as **passed against the shipping configuration**.

#### Two things checked because the move to `baseline1` created new risk

**1. `calcrnd` is computed on the same save that now triggers the alert — is it
stored in time?** Yes. `rnd` (field_order 62) and `calcrnd` (63) both live on
`baseline1`, so the very first `baseline1`-as-Complete save is the save that both
computes the passcode and fires the alert. Under the old `close` trigger this
ordering could never be exercised. Resolved from the code, not by assumption:

| `Classes/DataEntry.php` | |
|---|---|
| **6704** | `Calculate::saveCalcFields(...)` — calc values written |
| **6790** | `$eta->saveRecordAction(...)` — alerts evaluated |

Same function, sequential, 86 lines apart. Calc fields are committed before any
alert condition is read. Belt and braces: on a form submission REDCap normally
skips server-side recalc because JavaScript already computed the value into
`$_POST` (comment at 6658) — but the longitudinal guard at 6676 means a form
designated at the current event is recalculated server-side *as well*.
`redcap_projects.disable_autocalcs = 0` for PID 257, so this path is live.

**2. It turns out the `close` version was broken in the participant flow.**
`baseline1` **auto-continues straight to `check_code`** (instrument order 52 → 66),
which is where the participant types the passcode. So:

| | Old (`close`, order 233) | New (`baseline1`, order 52) |
|---|---|---|
| Participant reaches `check_code` | immediately after `baseline1` | immediately after `baseline1` |
| Passcode SMS has been sent by then | **No** — not until `close`, ~170 instruments later | **Yes** |

Under the `close` trigger the participant would land on a **required** `passcode`
field (`field_req=1`) with no code to enter. The O4 decision doesn't just improve
the timing — it's what makes the survey chain coherent at all.

⚠️ Residual timing note: alert 01 is `send-on: NOW`, but delivery is via the
`AlertsNotificationsSender` cron at **60 s** frequency. The participant can arrive
at `check_code` before the text lands and may need to wait up to a minute.

---

## Other open items

1. **`micastudy@stanford.edu` is a placeholder.** No study address was supplied.
   It appears in 9 alerts (04, 09, 14, 16–21). Swap before go-live:
   ```sql
   UPDATE redcap_alerts SET email_to = 'REAL@stanford.edu'
    WHERE project_id = 257 AND email_to = 'micastudy@stanford.edu';
   ```
2. **Alert 15 sends once per instance, not every save.** ASPIRE's 5604 had
   `email_repetitive = 1`; the importer stored `0` here despite
   `alert-send-how-many=EVERY` (`Alerts.php:3760` zeroes it in some paths). Once
   per `RECORD_EVENT_INSTRUMENT_INSTANCE` is arguably the better participant
   experience — a re-saved consent form won't re-send the PDF. Flip "Send every
   time" in the UI if ASPIRE's exact behaviour is wanted.
3. **`from` address is `ihab.zeedia@stanford.edu`** (copied from all 22 ASPIRE
   alerts), display name "MICA Study". Change if the study wants its own sender.
4. **Arm 1 (Standard Care) gets no session ladder** — confirmed by the PI. SC has
   no session instrument. Arm 1 records still receive alerts 01, 15–21.

---

## Production replication checklist

An alert export carries **only the alerts**. All 7 prerequisites are separate
artifacts and must be re-applied by hand on the Stanford server, in this order,
*before* importing the CSV. On a production-status project these metadata edits
must go through Draft Mode / the Online Designer, **not** raw SQL.

| | Change | Why it is required |
|---|---|---|
| P1 | `twilio_modules_enabled` → `SURVEYS_ALERTS` | `SURVEYS` permits Twilio for surveys only; all 7 SMS alerts are undeliverable without this. A project *setting* — it does not travel with any export. |
| P2 | `calcrnd` formula → `[rnd]` | Was emitting **8-digit** codes. See audit §3b. |
| P3 | add `baseline1.dummy_email` | `sms_code_check` and `sunday` both declare `email_participant_field='dummy_email'` and the field didn't exist. |
| P4 | `desc_sms_code_check`: "ASPIRE study" → "MICA study" | Participant-facing. |
| P5 | `desc_sms_optout`: "TRAM trial" → "MICA study" | Participant-facing. |
| P6 | ~~map `sms_code_check` onto events 1004/1008/1012~~ | **Not needed — do not promote.** Aimed at the wrong instrument: the passcode is entered on `check_code`, which was already designated at 1004–1016. See [`PROD_PROMOTION.md`](PROD_PROMOTION.md#p6-was-aimed-at-the-wrong-instrument--drop-it-from-the-prod-run). |
| P8 | `close` descriptives rebranched onto MICA event names | Without it `close` is a blank survey at Day 1 and at every follow-up for arms 2 & 3 — the instrument alerts 17–20 trigger on. |
| P9 | `calc_esms_valid` equation restored | It was a `calc` with a NULL equation, which **blocks every Data Dictionary import**. |
| P10 | `finding_rec_targets_json` note/validation repaired | Pre-existing DB corruption; also **blocks DD import**. |
| P7 | `phonen` validation → `phone` | **Blocks import.** `getPhoneFieldsList()` only whitelists fields whose validation `data_type` is `phone`, so `[phonen]` is rejected as an SMS recipient. ASPIRE has this; the MICA clone lost it. |

Then, after import:
- Recompute `calcrnd` on existing records — Data Quality rule **H** ("Incorrect
  values for calculated fields") → Fix all. P2 changes the formula only; stored
  values are not recomputed.
- Replace the `micastudy@stanford.edu` placeholder.
- Activate alerts individually.
