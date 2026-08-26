# PID 257 (MICA_R01) — As-Built Structure Audit

**Purpose:** record what the researcher-provided structure actually contains, and diff it
against what the phase-3 plan assumes, so the plan can be corrected against facts rather
than assumptions.
**Audited:** 2026-08-17 · read-only queries against the live REDCap 17.2.3 instance.
**Project state:** PID 257, **Development**, `repeatforms = 1`, `surveys_enabled = 1`,
**262 fields / 27 instruments / 18 surveys / 0 data rows**.
**Nothing in PID 257 was modified by this audit.**

> **Provenance note.** Several instruments carry label text naming *other* studies —
> "Your passcode for the **ASPIRE** study is [calcrnd]" (`sms_code_check`), "You will no
> longer receive texts from the **TRAM** trial" (`sms_opt_out`). I am **inferring** that
> these were copied from sibling projects; I was not told that. The researcher may have
> included them deliberately and verbatim. Flagged, not assumed to be a defect — but the
> participant-facing study names will need correcting before launch either way.

---

## 1. What matches the plan (no change needed)

| Plan assumption | Status |
|---|---|
| 3 arms / 13 events as rebuilt 2026-08-04 | ✅ unchanged — Day 1 (ED), Month 3, Month 6, Month 12 in arms 1–3, plus Weeks 1–12 in arm 3 only |
| MICA runs at Day 1 (ED) + Month 3, arms 2 & 3 only | ✅ `mica_ed_session` designated to Day 1 (ED) arms 2+3; `mica_booster_session` to Month 3 arms 2+3 |
| R01 session model (ED baseline + Month-3 booster), not the pilot's biweekly 2–7 | ✅ structure has no session_2..7 equivalents |
| Windows computed from enrollment/randomization dates, not `consent_date + 14n` | ✅ supported — `admin.randomization_date` (`date_ymd`) exists, with `@CALCDATE`-derived `calc_month_3/6/12` |
| Post-session participant measure exists | ✅ `postsession` (11 MI-quality items: "Help you to talk about changing your behavior", "Argued with you to change your behavior", …), designated Day 1 (ED) + Month 3, arms 2 & 3 — this is the R01 equivalent of the pilot's `posttest` |
| Alcohol data available to map to `alcohol_summary` | ✅ sources exist: `audit` (AUDIT-10 + `audit_score`), `ddq` (30-day daily drinking + `max`), `sip2r`, `screen.auditc1-3` |
| Participant has an SMS-capable phone | ✅ **stronger than assumed** — `screen.phone` ("personal cell phone with text messaging capability") is an **eligibility criterion** feeding `screen_eligibility.calc_eligible`, so every enrolled participant has one by inclusion |
| Delivery-channel preference captured | ✅ `baseline1.choice_fup_delivery` — "receive your followup survey by email or text" |

## 2. Contact / identity fields actually present

| Field | Instrument | Type | PHI flag |
|---|---|---|---|
| `record_id` | `pre_screen` | text (PK) | — |
| `participant_id` | `screen` | text, no action tags, no field note | — |
| `first_name` | `baseline1` | text | — |
| `last_name` | `baseline1` | text | ✅ |
| `email` | `baseline1` | text, `email` validation | ✅ |
| `phonen` | `baseline1` | text ("phone number (cell)") | ✅ |
| `choice_fup_delivery` | `baseline1` | checkbox (email / text) | — |
| `age` | `screen` | text, **no validation** | — |
| `sc_age` | `pre_screen` | text, `int` | — |
| `birth_sex` | `screen` | radio | — |
| `enrollment_id` | `admin` | text — "Enrollment ID. Assign after randomization." | — |

**No date-of-birth field exists anywhere in PID 257.** The only date-validated fields are
consent dates (`consent.cf_hipaa_adult_date`, `person_obtaining_consent.poc_date`) and
admin/scheduling calcs. This has a direct consequence for the auth plan — see
[`08-auth-discovery.md §6`](08-auth-discovery.md), revised accordingly.

## 3. The study's own participant-verification pattern (new information)

The structure contains an auth mechanism the phase-3 plan did not know about:

| Instrument | Fields | Designated to |
|---|---|---|
| `check_code` (survey) | `passcode` (text), `calc_code_check` = `if([calcrnd]=[passcode],1,0)`, `desc_code_wrong`, `desc_code_pass`, `html_check_code` | Day 1 (ED), Month 3, Month 6, Month 12 — **all 3 arms** |
| `sms_code_check` (survey) | `desc_sms_code_check` — "Your passcode for the ASPIRE study is `[calcrnd]`" | Weeks 1–12, **arm 3 only** |
| `sms_opt_out` (survey) | `desc_sms_optout` — STOP confirmation | Weeks 1–12, arm 3 only |

So the intended participant gate is: **text the participant a random passcode, have them
type it into `check_code`, and compare via a calc field.** Two observations:

1. **It is incomplete — `[calcrnd]` does not exist as a field.** Both the comparison calc
   and the SMS body reference it, so as it stands the passcode can never match and the
   SMS would send a literal empty value.
2. **A calc field is not an access boundary.** `calc_code_check` can gate *survey-queue
   release or branching logic*, but it cannot stop someone opening a survey link
   directly — unlike REDCap's Survey Login, which core enforces before the survey renders
   (`Surveys/index.php:1367`, before the module hooks at 3455/3847). The two are
   complementary, not alternatives; see the revised `08 §6`.

## 4. Gaps that block phase-3 work

| # | Gap | Blocks | Owner |
|---|---|---|---|
| G1 | **No chat-host survey.** `mica_ed_session` and `mica_booster_session` contain *only* their `_complete` field (0 real fields) and are **not enabled as surveys**. The plan's `ui_hosting_instrument` does not exist in this project. | The entire chat UI — `redcap_survey_page` has nothing to mount on | Eng + researcher sign-off |
| G2 | **No `[calcrnd]` field** — the passcode the study's own `check_code` / `sms_code_check` depend on | Participant verification (§3) | Researcher (or folded into the auth design) |
| G3 | **No `consent_date` field.** The pilot's `calculateSessionInfo()` (`MICA.php:772`) reads it, so the pilot session engine returns `null` on this project. Also referenced by 7 `admin` calcs. | Session-window computation — already planned to move to randomization dates, this confirms it is mandatory not optional | Eng (plan already covers) |
| G4 | **No transcript/session fields**: no `raw_chat_logs`, no `session_timestamp`, no `mica_transcript_hash` / `_ref`. `completeSession()`'s save targets do not exist. | Session close + the A→B transcript bridge | Eng + researcher sign-off (open question #8) |
| G5 | **No `mica_safety_finding` instrument** and **no repeating instruments configured at all** (`redcap_events_repeat` empty for this project) | SafetyScan findings storage (`02-data-model §3`) | Eng + researcher sign-off (#8) |
| G6 | **No randomization group field.** `randomization` is a 1-field placeholder; the only `%group%` match is a descriptive alert (`admin.desc_group_assigned`). `[group]` is referenced by 4 fields. | Arm assignment, and any logic keyed on study group | Researcher |
| G7 | **Twilio not configured on PID 257** (`twilio_enabled = 0`). Not blocked — `twilio_enabled_global = 1` and `twilio_enabled_by_super_users_only = 0` system-wide, so this is project-level configuration pending. | SMS delivery (the recommended ED default) | Eng + study Twilio account |
| G8 | 7 instruments remain **1-field placeholders** from the 2026-08-04 rebuild: `randomization`, `standard_care_resources_sc_arm`, `mica_ed_session`, `mica_booster_session`, `binge_days`, `ehr_abstraction`, `compensation` | Varies; G1 is the MICA-critical subset | Researcher |

## 5. Reference-integrity findings

A static pass over all 262 fields — action tags (`misc`), calc/choice strings
(`element_enum`), branching logic, labels and field notes — extracting every `[token]` and
checking it against the project's own field and event list.

**48 unresolved references.** These are reported for the researcher; apart from the ones
marked *(auth/MICA)* they are outside the SOW item currently in progress and **no fixes are
proposed here**.

| Referenced token | Exists? | Referenced by | Consequence |
|---|---|---|---|
| `[baseline_arm_1]` (**39 references**, now 35 — see below) | ❌ no such event — this project's events are `day_1_ed`, `month_3`, `month_6`, `month_12`, `weeks_112` (arm-suffixed) | `audit.audit3_b`, `sunday.bd_1..bd_11` and 35 more, `admin.calc_*` | All cross-event piping resolves to nothing |
| `[binge]` | ❌ | `screen_eligibility.calc_eligible`, `desc_ineligible`, `desc_ineligible_hazard` | **Eligibility calculation is broken** |
| `[consentvar1]` | ❌ | `screen_eligibility.calc_eligible` | Same |
| `[calcrnd]` *(auth)* | ❌ | `check_code.calc_code_check`, `sms_code_check.desc_sms_code_check` | **Passcode verification can never succeed** |
| `[consent_date]` *(MICA)* | ❌ | `admin.calc_valid_fup_emails`, `calc_week_6`, `calc_week_12`, `debug_calc_1`, +3 | Scheduling calcs; also G3 |
| `[group]` | ❌ | `admin.calc_esms_valid`, `admin.calc_valid_fup_emails`, `admin.desc_group_assigned`, `sunday.debug_sunday` | Group-conditional logic inert |
| `[dummy_email]` | ❌ | `admin.calc_esms_valid`, `desc_smry`, `desc_valid_dummy_email` | Email-readiness validation inert |
| `[time_diff]` | ❌ | `admin.first_monday_1200/1500/1700` | Timezone-offset scheduling inert |
| `[calc_dquant_threshold]`, `[calc_gset_threshold]` | ❌ | `sunday.gset` | Weekly check-in thresholds inert |

**Partially actioned (2026-08-25).** These findings surfaced to a user as REDCap's
"syntactical errors in the Branching Logic and/or Calculations" banner on the `admin` form.
[`19-admin-form-logic-errors.md`](19-admin-form-logic-errors.md) triages the 16 flagged
fields and **fixed the 4** whose only defect was the stale `[baseline_arm_1]` prefix
(`admin.calc_month_3/6/12`, `admin.first_monday`) — project-wide `[baseline_arm_1]`
references are now 35. The `[consent_date]` / `[group]` / `[dummy_email]` / `[time_diff]`
rows below, and the other 35 `[baseline_arm_1]` references, are **still open** and remain
researcher-owned as stated above.

**Not defects — intentional cruft.** Some fields are already self-labelled for removal and
should not be counted as findings: `admin.calc_week_6` and `calc_week_12` are prefixed
`DELETE:`, and `admin.first_monday_1500` is prefixed `NOT_USED:`. `admin` also contains
explicit debug fields (`debug_calc_1`, `debug_calc_2`, `debug_gset`).

## 6. Resulting plan changes

| Doc | Change |
|---|---|
| [`08-auth-discovery.md`](08-auth-discovery.md) | **Revised** — credential field (no DOB available), host instruments (G1), reconciliation with `check_code` (§3 above), Twilio project config (G7), withdrawal handling (`admin.study_withdrawn`, `admin.sms_stop`). The option analysis and ranking are **unchanged**; everything load-bearing in the recommendation held up against the as-built project. |
| [`08-auth-discovery.md §6.1c`](08-auth-discovery.md) | **New finding the 3-arm structure forced out:** Survey Login credentials are (field, **event**) pairs and the event is absolute, so in this multi-arm project the credential **must be configured once per MICA arm** (`min_fields = 1`) — otherwise participants in the uncovered arm get a blank, unusable page. It also caps the design at **one** credential field (3 slots ÷ 2 MICA arms). This is the highest-risk implementation detail in the auth design and would have been invisible in a single-arm project. |
| [`05-open-questions-and-risks.md`](05-open-questions-and-risks.md) #8 | Partially answered — the session/auth structure now exists; the outstanding sub-decisions are G1, G2, G4, G5, G6 |
| [`02-data-model.md`](02-data-model.md) §3 | Field-addition list should be rewritten against the real dictionary: contact fields already exist on `baseline1` (§2 above), so only the transcript/finding/session fields remain to add (G4, G5) |
| [`06-implementation-plan/stage-2-session-engine.md`](06-implementation-plan/stage-2-session-engine.md) | Window source is `admin.randomization_date` (+ `calc_month_3`); post-session survey is `postsession`, not `posttest`; host instruments per G1 |
| [`01-architecture.md`](01-architecture.md) | `alcohol_summary` mapping sources confirmed (`audit`/`ddq`/`sip2r`/`screen.auditc*`) |

## 7. Method

Read-only SQL against `redcap_metadata`, `redcap_surveys`, `redcap_events_metadata`,
`redcap_events_arms`, `redcap_events_forms`, `redcap_events_repeat`, `redcap_projects`,
`redcap_config`, `redcap_surveys_queue`, `redcap_surveys_scheduler`, plus one read-only
`Project(257)` instantiation to confirm `baseline_arm_1` is absent from the event list.
The reference audit tokenised `[...]` occurrences across `misc`, `element_enum`,
`branching_logic`, `element_label`, `element_note` and compared against the project's field
list (including `<form>_complete` pseudo-fields) and Smart-Variable allowlist.
