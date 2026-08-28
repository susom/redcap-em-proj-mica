# MICA (PID 257) Alerts & Notifications — Audit vs ASPIRE (PID 262)

**Date:** 2026-08-26
**Instance:** localhost, REDCap 17.2.3, `redcap_2023_1_db`
**Status:** audit / design-questions stage. Nothing has been created in PID 257 yet.

---

## 1. Baseline state

| | MICA (257) | ASPIRE (262) |
|---|---|---|
| Title / status | `MICA_R01`, status 0 (dev) | `ASPIRE`, status 0 (dev) |
| Created | 2026-08-04 | 2026-08-26 (fresh import) |
| Records | 3 | 3,113 |
| **Alerts** | **0** | **22 — all `email_deleted=1` (deactivated)** |
| **ASIs** | **0** | **41 — all `active=0` (deactivated)** |
| Twilio | **enabled**, real SID `ACe1be19…`, from `6504075535`, modules `SURVEYS`, SMS-invite-web on, default delivery pref `EMAIL`, `twilio_delivery_preference_field_map` **NULL** | **disabled**, no creds |
| SMS mechanism | native REDCap Twilio | `@ESMS` → `enhanced_sms_conversation` module |
| EMs enabled | `proj_mica` only | **none** |

`email_deleted = 1` means *deactivated*, not deleted — confirmed at
`redcap_v17.2.3/Classes/Alerts.php:5295` (`'alert-deactivated' => ($row['email_deleted'] == 1) ? 'Y' : 'N'`).
So the entire ASPIRE notification layer is present-but-off in this copy; it is readable
as a design reference but is not running.

REDCap 17.2.3 supports `alert_type ENUM('EMAIL','SMS','VOICE_CALL','SENDGRID_TEMPLATE')`.
MICA has live Twilio credentials and ASPIRE does not, so MICA is *positioned* to use
native `SMS` alerts and skip ASPIRE's `@ESMS` workaround — **but** it is gated on
`twilio_modules_enabled`, currently `SURVEYS` (see gap 4b). One project-setting change
unlocks it.

262 was created today, holds 3,113 records, and its alert bodies still link to
`pid=31710` — it is a **copy of live production ASPIRE**. The fact that all 22 alerts and
41 ASIs are deactivated is a **copy artifact** (imports arrive deactivated so a clone
can't email real participants), not a PI decision to switch them off.

**Correction to an earlier note in this file: do not read ASPIRE as "battle-tested".**
Being a production copy is not the same as having worked. Across all 3,113 ASPIRE
records, these fields hold **zero** non-empty values:

| Field | Type | Rows with a value |
|---|---|---|
| `rnd`, `calcrnd` | calc | **0** |
| `calc_esms_valid` | calc — "Ready for ESMS Alerts" | **0** |
| `calc_valid_fup_emails` | text | **0** |
| `calc_month_3` / `_6` / `_12` | text | **0** |
| `first_monday`, `calc_week_6`, `calc_week_12` | text | **0** |
| `randomization_date` | date_ymd | 316 |

Only `randomization_date` was ever populated. Since **every** ASPIRE ASI is gated on
`calc_esms_valid`, `calc_valid_fup_emails` or `choice_fup_delivery`, and the first two
never evaluate, most of ASPIRE's automated layer could never have fired. That also
explains alerts 5609–5622: the 14 "ESMS Bulk week N" alerts with hardcoded
`[record-name]` lists are not a stylistic lapse, they are **the workaround** — the team
fell back to manually blasting named records because the gated automation never ran.

Two consequences for MICA:
1. Port ASPIRE's *shapes*, not its *gates*. The MICA build deliberately conditions on
   real instrument-status and contact fields rather than on `calc_esms_valid`.
2. `admin.calc_month_3` is dead scaffolding and cannot anchor the booster ladder.
   The build derives Month 3 as `randomization_date + 91` (the offset `calc_month_3`'s
   own label documents) instead.

---

## 2. MICA structure relevant to the four requested notifications

### Arms & events
| Arm | Events |
|---|---|
| 1 — Standard Care (SC) | Day 1 (ED) `1004`, M3 `1005`, M6 `1006`, M12 `1007` |
| 2 — MICA | Day 1 (ED) `1008`, M3 `1009`, M6 `1010`, M12 `1011` |
| 3 — MICA + Weekly SMS | Day 1 (ED) `1012`, Weeks 1–12 `1013`, M3 `1014`, M6 `1015`, M12 `1016` |

### The two "MICA sessions"
- `mica_ed_session` — Day 1 (ED); arms 2 & 3 only (events `1008`, `1012`). Survey, save-and-return, repeating.
- `mica_booster_session` — Month 3; arms 2 & 3 only (events `1009`, `1014`). Survey, save-and-return, repeating.

Arm 1 (SC) has **no** session instrument — it gets `standard_care_resources_sc_arm` instead.

### Fields the notifications depend on
| Field | Form | Type | Note |
|---|---|---|---|
| `first_name`, `last_name` | baseline1 | text | |
| `email` | baseline1 | text, `email` validation | PHI-flagged |
| `phonen` | baseline1 | text | PHI-flagged; "What is your phone number (cell)?" |
| `phone` | screen | yesno | *eligibility* question ("do you have a cell w/ texting"), not a number |
| `rnd` | baseline1 | calc | `if([rnd]='', random(1000,9999), [rnd])` |
| `calcrnd` | baseline1 | calc, `@HIDDEN-SURVEY` | `rounddown(([rnd]*10000*0.8999),0)+1000` → 4-digit code |
| `passcode` | **check_code** | text, **required** | participant types the code here |
| `calc_code_check` | check_code | calc | `if([calcrnd]=[passcode],1,0)` → phone confirmed |
| `desc_code_wrong` / `desc_code_pass` | check_code | descriptive | branching on `calc_code_check` |
| `desc_sms_code_check` | sms_code_check | descriptive | body = "Your passcode for the **ASPIRE** study is [calcrnd]." |
| `sms_stop` | admin | checkbox | set when participant texts STOP |
| `study_withdrawn` | admin | checkbox | |
| `study_group` | admin | radio | 1 SC / 2 MICA / 3 MICA+Weekly SMS |
| `randomize_trigger`, `randomization_date` | admin | radio / date_ymd | |
| `calc_esms_valid` | admin | calc | "Ready for ESMS Alerts" |
| `calc_valid_fup_emails` | admin | calc | `if([email]<>'' AND [cf_hipaa_adult_date]<>'' AND [phonen]<>'' AND [study_group]<>'' AND [birth_sex]<>'' AND [study_withdrawn(1)]=0, 1, 0)` |
| `calc_month_3` / `_6` / `_12`, `first_monday*`, `calc_week_6/12` | admin | text | date anchors, already computed |
| `consent_pdf`, `poc_signature`, `poc_date` | person_obtaining_consent | file/file/date | |

`check_code` is mapped to **every** event (Day 1, M3, M6, M12, all arms).
`sms_code_check`, `sms_opt_out`, `sunday` are mapped to **event 1013 only** (Weeks 1–12, arm 3).

---

## 3. Blocking gaps found in MICA

These must be resolved before the four notifications can be built.

1. ~~No communication-preference field exists.~~ **CORRECTED — it does exist.**
   `choice_fup_delivery` is present in **both** projects (MICA: `baseline1`, field_order 56,
   immediately after `phonen`; ASPIRE: `enrollment`). Identical definition in both:
   checkbox, `1, Email to [email]` / `2, SMS to [phonen]`.
   *(My first pass missed it — the `LIKE` sweep omitted `%choice%`/`%deliver%`. Found via
   full field-name set difference between 257 and 262.)*

   The real issue is its **type**: a *checkbox*, not a radio. So a participant can tick
   **both** (→ duplicate messages on both channels) or **neither** (→ silence).
   ASPIRE never solved this — its two ASIs are gated independently on
   `[choice_fup_delivery(1)]='1'` and `[choice_fup_delivery(2)]='1'`, so both failure
   modes were live in production. MICA needs a tie-break/fallback rule.
   REDCap's own `redcap_projects.twilio_delivery_preference_field_map` is NULL in both.
2. **`dummy_email` field does not exist in MICA**, yet the `sms_code_check` and
   `sunday` surveys both declare `email_participant_field = 'dummy_email'`.
   Any ASI on those two surveys cannot resolve a recipient. (ASPIRE has it on the
   `check` form with `@DEFAULT="noreply@stanford.edu" @HIDDEN-SURVEY`.)
3. **`sms_code_check` is only on event 1013** (Weeks 1–12, arm 3), but the phone-confirmation
   code has to go out at Day 1 for **all** arms, right after baseline. `calcrnd` itself
   lives on `baseline1` at the Day 1 event.
4. **`enhanced_sms_conversation` (ESMS) is not enabled on 257.** Every ASPIRE SMS path
   keys off `@ESMS` in the subject line and that module. MICA's native Twilio is a
   different, simpler mechanism — this is a deliberate fork, not an oversight to copy.
   (Module lives at `modules-local/enhanced_sms_conversation_v9.9.9`;
   `SUBJECT_TAG_FOR_EMAIL = "@ESMS"` at `EnhancedSMSConversation.php:24`. System
   `enabled=false`, enabled only on PID 192.)

4b. **Native SMS *alerts* are currently blocked by a project setting.**
   `redcap_projects.twilio_modules_enabled` is `ENUM('SURVEYS','ALERTS','SURVEYS_ALERTS')`
   and MICA is set to **`SURVEYS`**. So although `alert_type` accepts `'SMS'` at the schema
   level, this project may only use Twilio for *surveys* — an `alert_type='SMS'` alert is
   not available until the setting is changed to **`SURVEYS_ALERTS`**.
   Enum acceptance ≠ project permission; these are two separate gates.
5. **Copy-paste leftovers in MICA survey text:**
   - `sms_code_check.desc_sms_code_check` → "the **ASPIRE** study"
   - `sms_opt_out.desc_sms_optout` → "the **TRAM** trial"
6. **No anchor date for "session not completed within a week."** The ED session is
   same-visit in the ED; the booster is at Month 3 (`admin.calc_month_3` exists).
   Which session the 1-week clock applies to, and what starts it, is undefined.
7. **No CRC/coordinator recipient defined.** ASPIRE hardcoded `aspirestudy@stanford.edu`
   in all 7 staff alerts. MICA has no study-email field and no DAG-based routing.
8. **Suppression gates.** Every outbound message must respect
   `[sms_stop(1)]` and `[study_withdrawn(1)]` — ASPIRE does this on its ASIs
   (`('1'<>[baseline_arm_1][study_withdrawn(1)]) AND ('1'<>[baseline_arm_1][sms_stop(1)])`)
   but **not** on its staff alerts.

---

## 3b. BLOCKER for requirement #1 — `calcrnd` is broken in MICA, and never worked in ASPIRE

The passcode that requirement #1 is supposed to text is **not a 4-digit code**.

**Design intent** (from ASPIRE's `rnd`, whose original formula REDCap preserved as a comment):
```
// Original: if([rnd]='', Math.random(), [rnd])
```
`Math.random()` returns a float in `[0, 1)`, so
`calcrnd = rounddown(([rnd] * 10000 * 0.8999), 0) + 1000` yields `[1000, 9998]` —
a 4-digit number never starting with zero, exactly as the field label claims.

**What actually happened:**

| | `rnd` formula | Result |
|---|---|---|
| ASPIRE (262) | `Math.random()` — **JavaScript, not valid REDCap calc syntax**, so REDCap commented it out | `rnd` never computes → `calcrnd` never computes. **Zero `rnd`/`calcrnd` data rows across all 3,113 ASPIRE records.** The phone-check feature never ran in production. |
| MICA (257) | `if([rnd]='', random(1000,9999), [rnd])` — valid REDCap, latches a 4-digit int | `rnd` works (test data: 3089, 8927) but `calcrnd` still multiplies by `10000 * 0.8999` → **8-digit output** (test data: `27798911`, `80335073`) |

Verification of the MICA arithmetic: `3089 × 10000 × 0.8999 = 27,797,911` →
`rounddown → 27797911` → `+ 1000 = 27798911`. Matches the stored value exactly.

**Consequence:** the SMS would read *"Your passcode … is 27798911"* while `check_code.passcode`
is presented as a 4-digit entry. `calc_code_check = if([calcrnd]=[passcode],1,0)` would still
compare correctly, but the participant-facing experience is wrong and the label lies.

**Fix:** `rnd` already returns exactly the desired range (1000–9999, no leading zero), so
`calcrnd` should simply be `[rnd]`. Keep `calcrnd` as the referenced field — `check_code`,
`sms_code_check` and the new alert all point at it — and drop the stale multiplication.

**Secondary issue:** `calcrnd`/`rnd` live on `baseline1`, which is mapped to the Day 1 event of
*all three* arms (`1004`/`1008`/`1012`), so the code is per-event. Test record 1 already holds
two different codes (`27798911` @ 1004, `80335073` @ 1008). Any alert piping `[calcrnd]` must
pin the event explicitly (e.g. `[day_1_ed_arm_2][calcrnd]`) or it will resolve ambiguously.

---

## 4. ASPIRE inventory — what ports to MICA

### 4a. Alerts (22, all deactivated)

| ID | Title | Trigger | Recipient | Port to MICA? |
|---|---|---|---|---|
| 5604 | CONSENT FORMS | `person_obtaining_consent`, `[consent_pdf] <> ''` | `[email]` (participant) + PDF attachment | **Yes, directly.** MICA has `consent_pdf` on the same instrument. |
| 5605 | CONSENTED (& did check) | `check` form complete | `aspirestudy@stanford.edu` | **Yes** → retarget to MICA `check_code`. This is the shape for req #4. |
| 5606 | ENROLLMENT + BL activities DONE | `ucla` form | staff | **Yes** → retarget to MICA `baseline1`/`close`. |
| 5607 | MO3 FUP DONE | `close` @ ev 1035 | staff | **Yes** → MICA `close` @ 1005/1009/1014. |
| 5608 | MO6 FUP DONE | `close` @ ev 1036 | staff | **Yes** → MICA `close` @ 1006/1010/1015. |
| 5623 | MO12 FUP DONE | `close` @ ev 1037 | staff | **Yes** → MICA `close` @ 1007/1011/1016. |
| 5625 | Opt Out Received (Twilio STOP) | `[sms_stop(1)]=1 or [sms_opt_out_complete]=1 or … =2` | staff | **Yes, directly.** MICA has `sms_stop` + `sms_opt_out`. (Note the condition has a duplicated `[sms_opt_out_complete]=1` clause — clean up on port.) |
| 5624 | "edit for ears later ?" | `ucla` | staff | No — EARS-specific, unfinished. |
| 5609–5622 | ESMS Bulk week 1–12 / EARS install 6 / uninstall 3 | **hardcoded `[record-name] = "1683" OR …` lists** | `[survey-participant-email]` | **No — anti-pattern.** These are manual ad-hoc rescue sends built when the ASI layer failed. Do not reproduce. |

Common ASPIRE staff-alert body shape (worth keeping):
```
<a href="…/DataEntry/record_home.php?pid=…&arm=1&id=[record-name]">View Record Home for [record-name]</a>
REDCap ID: [participant_id]   EARS ID: [baseline_arm_1][ears_ksana_id]
<ul><li>…what the CRC should do next…</li></ul>
```
Subject shape: `[baseline_arm_1][ears_ksana_id] (R[participant_id]): <EVENT>`.
Every one uses `cron_send_email_on = now`, `alert_stop_type = RECORD`,
`email_repetitive = 0` (send once per record).

### 4b. ASIs (41, all `active=0`)

| Pattern | ASPIRE config | Relevance |
|---|---|---|
| **Phone-code SMS** | `sms_code_check` @ ev 1022, subj `@ESMS`, cond `[baseline_arm_1][calcrnd]<>''`, **IMMEDIATELY after survey `check` completes** | **This is requirement #1.** MICA can do the same, or use a native SMS *alert* instead. |
| **Welcome SMS** | `welcome_sms` @ 1022, subj `@ESMS`, cond `[phonen]<>'' AND [dummy_email]<>''`, IMMEDIATELY | Optional for MICA. |
| **Email-vs-SMS preference split** | `followup` ASI gated on `[choice_fup_delivery(1)]='1'`; `sms_followup_link` ASI gated on `[choice_fup_delivery(2)]='1'` — two parallel ASIs, same events (1035/1036/1037), same 10-hour lag | **This is requirement #2's exact pattern.** Two objects, one per channel, mutually-exclusive conditions. |
| **Opt-out confirmation** | `sms_opt_out` @ 1022, cond `[sms_stop(1)]="1"`, IMMEDIATELY | Directly portable. |
| **Weekly SMS cadence** | `sunday` ×12 (day lag 6,13,20,…,83) and `thursday` ×12 (lag 3,10,…,80), each on its own event `1023`–`1034`, gated on `study_withdrawn` + `sms_stop` + `calc_esms_valid` | MICA arm 3 has **one** `1013` "Weeks 1–12" event with a `sunday` form — a different (repeating-instance) design. Not a 1:1 port. |
| **EARS install/uninstall** | 8 ASIs | Not applicable to MICA. |

**Notable absence:** *no ASPIRE ASI or alert uses reminders* — `reminder_type` is NULL and
`reminder_num = 0` on all 41. And there is no escalation-to-staff-on-nonresponse alert.
So **requirements #3 (1-week nudge) and #4 (escalate to CRC) have no ASPIRE precedent
and must be designed from scratch.**

---

## 5. Design decisions (resolved 2026-08-26)

| Question | Decision |
|---|---|
| Which session gets the ladder | **Both** `mica_ed_session` and `mica_booster_session`. Arm 1 (SC) dropped — it has no session instrument. |
| 1-week anchor | **`admin.randomization_date`**. Booster derived as `+91` from the same field, because `calc_month_3` is never populated (§1). |
| CRC recipient | **One hardcoded study address.** No address supplied → `micastudy@stanford.edu` placeholder, flagged in the build README. |
| Passcode channel | **Native SMS alert**, with `twilio_modules_enabled` flipped to `SURVEYS_ALERTS`. Not ASPIRE's `@ESMS` route. |
| Checkbox tie-break | **SMS wins if both ticked; SMS also if neither.** |
| Passcode gate | **Literally as stated** — consent + `baseline1` + baseline battery (`close`). |
| Pre-existing defects | **Fix all three** (missing `dummy_email`, `sms_code_check` event mapping, ASPIRE/TRAM copy). A fourth and fifth were found during the build: the `calcrnd` formula (§3b) and `phonen`'s missing `phone` validation. |

## 6. What was built

23 alerts, all deactivated, plus 7 prerequisite changes.
See **[`alerts/README.md`](alerts/README.md)** for the alert-by-alert table, the
verification performed, the open items, and the production-replication checklist.
