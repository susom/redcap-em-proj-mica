# Arm 3 weekly SMS — the build, on PID 268

> **Archived 2026-10-12.** The original weekly-SMS build on PID 268, reverted 2026-09-21; PID 268 was deleted the next day. Current state: [ARM3_WEEKLY_SMS_REBUILD_271.md](../topics/alerts/ARM3_WEEKLY_SMS_REBUILD_271.md). Prod steps: [arm3-weekly-sms-prod.md](../runbooks/arm3-weekly-sms-prod.md). Kept for the design reasoning (branching rewrite, ESMS) that the rebuild reused.

> ## ⚠️ REVERTED on 268 2026-09-21 12:49 — REBUILT on PID 271 2026-09-23 (inactive)
>
> **Current state:** [ARM3_WEEKLY_SMS_REBUILD_271.md](../topics/alerts/ARM3_WEEKLY_SMS_REBUILD_271.md) (built and
> verified on 271, ASI `ss_id 256` inactive). **Prod:**
> [ARM3_WEEKLY_SMS_PROD_RUNBOOK.md](../runbooks/arm3-weekly-sms-prod.md). The rest of this banner is the
> history.
>
> On the instruction *"DO NOT CHANGE IN PROJECT. REVERT ANYTHING YOU MODIFIED"*, the whole build
> was undone on PID 268. That covered: the recurring ASI `ss_id 255` (deleted by SQL, along with its
> queue and recurrence rows), the `sunday` branching rewrite, event 1098 as repeating, the ESMS
> config on 268, and record 31's move to arm 3. The "done" statuses below record what was *built
> and tested*, not the current state.
>
> - A pre-revert snapshot of the ASI row survives outside the repo at
>   `/private/tmp/claude-502/…/a5c5c45e-…/scratchpad/revert-safety/redcap_surveys_scheduler.sql`.
>   It is in tmp and will not last. `docs/phase-3-handoff/scripts/apply-weekly-sms-asi.php` and
>   `apply-weekly-sms-branching.php` are the durable way to rebuild it.
> - PID 268 was soft-deleted on 2026-09-22. PID 271 was copied from prod.
> - **Prod PID 35968 has no ASIs at all.** The 2026-09-23 XML export has no `SurveysScheduler`
>   section, so 271 inherited none.
> - The weekly SMS was never an alert. It is an ASI whose `@ESMS` subject tag makes the Enhanced SMS
>   Conversation module send it as SMS. It will never appear under Alerts & Notifications.

**Started:** 2026-09-21, on record **31**, at the request of testing the weekly SMS end to end on a
real handset.

**Supersedes the mechanism analysis in** [`ARM3_WEEKLY_SMS_STATUS.md`](../topics/alerts/ARM3_WEEKLY_SMS_REBUILD_271.md).
That document's *findings* about PID 257 stand; three of its conclusions do not carry to PID 268 and
one of its framing assumptions was wrong. Corrections are in §1.

---

## 0. Where the work happens

| | |
|---|---|
| Project | **PID 268** — `new_test_mica_r01`, app title *NEW TEST MICA_R01*, data table **`redcap_data7`** |
| Record | **31** — created 2026-09-21 10:21, `first_name` Ihab, `phonen` on file |
| Not PID 257 | 257 holds records 1–6 + `MICATEST01` only. Record 31 does not exist there. |
| Not prod | Production is PID **35968** on another instance; nothing here has been applied there. |

PID 268 is where the randomization module is actually enabled (`rid=7`, target field
`study_group`, target event 1089) — see [`../topics/randomization/README.md`](../topics/randomization/README.md).

---

## 1. Corrections to `ARM3_WEEKLY_SMS_STATUS.md`

### 1a. The weekly SMS is **not** a REDCap-native Twilio survey. It is an external module.

The status doc's recommended step 4 was *"Add the sender — ASI on `sunday` per slot"*, implicitly
REDCap's own Twilio survey plumbing. That is the wrong mechanism, and the evidence was sitting in
`sunday`'s own metadata the whole time:

```
drink_fut.misc = @ESMS-INVALID-RESPONSE="We don't understand. Please text Yes or No"
```

`@ESMS-*` is not a REDCap action tag. It belongs to **Enhanced SMS Conversation**
(`enhanced_sms_conversation_v9.9.9`, external module id **59**), which is installed on this
instance and **enabled with full Twilio credentials on PID 192** — a *second* ASPIRE project,
distinct from the PID 262 copy the status doc examined.

`EnhancedSMSConversation.php:24-31` declares the tag vocabulary:

| Tag | Purpose |
|---|---|
| `@ESMS` (in the ASI **email subject**) | the trigger — `redcap_email` hijacks the send |
| `@ESMS-IGNORE` | skip a field in the SMS conversation |
| `@ESMS-INVALID-RESPONSE` | per-question nonsense-reply text |
| `@ESMS-REMINDER-MESSAGE` / `-TIME` / `-MAX-COUNT` | per-question reminder ladder |
| `@ESMS-EXPIRY-MESSAGE` / `-TIME` | per-question conversation expiry |

**How it works.** An ASI is configured with `delivery_type = EMAIL` and `@ESMS` anywhere in the
subject. The module's `redcap_email` hook (`EnhancedSMSConversation.php:67-69`) sees the tag,
returns `false` so the email is never sent, and instead runs the whole instrument as an SMS
conversation over its **own** Twilio credentials (`classes/TwilioManager.php:30-32`, project
settings `twilio-sid` / `twilio-token` / `twilio-number`).

So the project's `twilio_enabled` / `twilio_modules_enabled` / `twilio_option_sms_initiate` columns
are **irrelevant to this feature**. No project-level Twilio change is needed.

### 1b. PID 192 is the working reference. Its shape:

| Piece | Value |
|---|---|
| Events | `Baseline` (708) + **`Week 1 SMS` … `Week 12 SMS` (709–720)** + Month 3/6/12 |
| ASIs | 12 × `sunday` + 12 × `thursday`, one per week event, **all `active=0`** |
| Subject | `@ESMS Sunday 1` … `@ESMS Sunday 12` (the number is cosmetic — the hook only greps `@ESMS`) |
| Timing | `TIME_LAG` **6, 13, 20 … 83** days off `[baseline_arm_1][first_monday_1200]` |
| Thursday | `TIME_LAG` 3, 10, 17 … 80 days off `[baseline_arm_1][first_monday_1700]` |
| Gate | `[baseline_arm_1][calc_esms_valid]='1' AND '1'<>[…][study_withdrawn(1)] AND '1'<>[…][sms_stop(1)]` |
| Module config | `phone-field = phonen`, `phone-field-event-id = 708`, `sms-opt-out-field = sms_stop` |

### 1c. `calc_esms_valid` is **already repaired** in PID 268.

The status doc says the gate "cannot currently evaluate to 1" because it requires `[birth_sex]`,
which has zero metadata rows. In 268 the equation is:

```
if([dummy_email] <> '' AND [randomization_date] <> '' AND [phonen] <> ''
   AND [study_group] <> '' AND [s_sex] <> ''
   AND [study_withdrawn(1)] = 0 AND [sms_stop(1)] = 0, 1, 0)
```

`s_sex`, not `birth_sex`. Record 31 has `s_sex=1`, `phonen`, `dummy_email` and `study_group`; the
only missing input is **`randomization_date`**. Same story for `calc_binge_threshold`, which is
`if([s_sex]='1',5,if([s_sex]='2',4,''))` and already computes **5** on record 31.

### 1d. The cross-event prefix is `[day_1_ed_arm_1]`, not `[day_1_ed_arm_3]`.

The status doc says to rewrite ASPIRE's 27 `[baseline_arm_1]` references onto
`[day_1_ed_arm_3]`. That would resolve to blank. The fields those references reach live on
`contact_info`, which is designated at **1089 only**:

| Field | Form | Designated at 1089 (arm 1 D1) | Designated at 1097 (arm 3 D1) |
|---|---|---|---|
| `first_name`, `phonen`, `dummy_email` | `contact_info` | ✅ | ❌ |
| `calc_binge_threshold` | `auditc` | ✅ | ✅ (but empty — data is at 1089) |
| `randomization_date`, `first_monday_1200`, `sms_stop`, `study_withdrawn`, `calc_esms_valid` | `admin` | ✅ | ✅ (but empty) |

MICA collects enrollment at the **arm-1** Day-1 event for every participant and then materializes
the record into its randomized arm (doc 15). `day_1_ed_arm_1` is therefore the structural analogue
of ASPIRE's `baseline_arm_1`. The project's own fields already agree —
`calc_week_12.misc = @CALCDATE([day_1_ed_arm_1][cf_hipaa_adult_date],83,'d')`.

### 1e. Under REDCap-native SMS, `dquant` would have been silently skipped.

Recorded because it is a trap for anyone who tries the native path. `dquant.misc = @FORCE-MINMAX`,
and `TwilioRC::fieldUsageIVR()` (`Classes/TwilioRC.php:643-656`) returns a *warning string* rather
than `true` for **any** field whose `misc` contains a core action tag. `Surveys/twilio_question.php:481`
treats anything that is not `true` as a skip:

```php
$skipField = (TwilioRC::fieldUsageIVR((SMS ? 'SMS' : 'VOICE'), $field) !== true);
```

So the pivotal question — how many drinks — would never be asked, `dquant` would stay blank, and
every tailored-feedback branch would evaluate against blank. Verified by probe:

```
debug_sunday   @HIDDEN-SURVEY   SMS_usable=NO -> SKIPPED
dquant         @FORCE-MINMAX    SMS_usable=NO -> SKIPPED
drink_fut      @ESMS-INVALID-…  SMS_usable=YES   (not a core tag, so not matched)
```

**The ESMS module does not have this defect** — `classes/FormManager.php:139-141` skips only
`@ESMS-IGNORE`.

---

## 2. Design decisions taken 2026-09-21

Both of the status doc's two blocking PI questions were answered, plus two test-shape questions.

| # | Decision | Chosen | Note |
|---|---|---|---|
| A | Calendar shape | **Event 1098 `Weeks 1–12` made repeating, 12 instances** | *Not* ASPIRE's 12-event shape. Compatible — see §3. |
| B | Cadence | **Sunday only** | Forced: PID 268 has no `thursday` instrument. Porting it from 192/262 is a later option. |
| C | Test pacing | **One week at a time, on explicit go-ahead** | |
| D | Branch coverage | **Vary the answers week to week** so all three feedback branches (`ar_*`, `sd_*`, `bd_*`) and both goal branches (`gp_*`, `gg_*`) appear across the 12 weeks | |

### Why the repeating shape is compatible with ESMS

The risk was that ESMS could not tell *which week* a conversation was for. It can:

- `MessageContext.php:187` selects `rssq.instance as instance` from
  `redcap_surveys_scheduler_queue` — the recurring-ASI queue row carries the instance.
- `EnhancedSMSConversation.php:85` passes it on: `$instance = $mc_context['instance'] ?? 1`.
- `FormManager::skipDueToBranching()` (`classes/FormManager.php:392-393`) evaluates branching
  instance-aware when the form is repeating:
  ```php
  \REDCap::evaluateLogic($branching_logic, $project_id, $record_id, $event_id,
                         $this->instance, $this->form, $this->form);
  ```
- `FormManager::pipe()` (`:342`) pipes with the same instance.

REDCap's own side supports it too: recurring ASIs require the event/form to be repeating
(`Classes/SurveyScheduler.php:1359-1362` joins `redcap_events_repeat`), and each recurrence is
allocated the next instance number (`:1440`, `max(q.instance)+1`).

**Consequence of choosing repeating over 12 events:** `sunday`'s 61 week gates change from
`[event-name]="week_N_sms_arm_1"` to `[current-instance]=N`, which is a rewrite rather than a
find-and-replace, and the shape diverges from the PID 192 reference the study has already run. One
recurring ASI replaces twelve.

---

## 3. Build steps

Status is updated as each lands.

| # | Step | Status |
|---|---|---|
| 1 | Record 31 → arm 3 (allocation, `study_group`, materialize 1097, drop arm-2 placement) | **done** |
| 2 | `randomization_date` set so `weekday` → `calc_monday_diff` → `first_monday` → `first_monday_1200` computes and `calc_esms_valid` → 1 | **done** |
| 3 | Event 1098 made repeating for `sunday` | **done** |
| 4 | `sunday` metadata rewrite (see §4) | **done** |
| 5 | `sunday` rebranded (TRAM → MICA, survey title set) | **done** |
| 6 | ESMS module enabled + configured on 268 | **done** |
| 7 | ngrok tunnel + Twilio inbound webhook → `pages/inbound` | awaiting go-ahead |
| 8 | Recurring ASI on `sunday` @1098, `@ESMS` subject, every 7 days × 12 | **done — created INACTIVE** |
| 9 | Weeks walked one at a time on a real handset | **done — weeks 1, 2, 12; all branches covered** |

### ✅ Teardown — done 2026-09-21, verified

Order matters and is not obvious, so it is recorded:

| # | Action | Why this position | Verified |
|---|---|---|---|
| 1 | Twilio `SmsUrl` → `http://redcap.local/surveys/`, `POST` | **before** killing the tunnel — a reply in flight against a dead tunnel strands the conversation silently | `sms_url` reads back correct |
| 2 | `ngrok` killed | the public URL onto a dev REDCap holding test PHI | no process, API gone |
| 3 | ASI `ss_id 255` → `active = 0` | **before** re-enabling the sender, or the 60-second cron gets a window to schedule for another arm-3 record | `active = 0` |
| 4 | `SurveyInvitationEmailer` → `ENABLED` | it was `DISABLED` for the whole run, which froze **every project's** ASIs on this instance, not just MICA's. The most urgent item on this list. | `ENABLED` |
| 5 | `e2e_admin_form` removed | known password with data-entry rights on 268 | account gone |

Final state: record 31 in arms `1,3`; 3 weeks answered (instances 1, 2, 12); 9 `DELETED`
placeholders; ASI inactive; cron restored.

**Records 2/4/17 `phonen`: leaving them distinct, deliberately.** They are dev test records whose
only realistic use is this kind of testing, and three participants sharing one number is precisely
the condition that produced the wrong-record bug above — so distinct numbers are the better resting
state, not merely the convenient one. `phonen_268_pre.sql` holds the originals if anyone disagrees.

**Re-running the cadence on record 31 needs a reset first.** Instances 3–11 hold `DELETED`
placeholder queue rows with no data behind them, so `MAX(q.instance)+1` will keep returning 13 and
week 3 can never be reached again as things stand. `reset-week.php` already does the right thing —
it clears `redcap_surveys_scheduler_queue` and `_recurrence` for `ss_id 255` and re-schedules from
instance 1. Any future test on this record inherits the placeholder state until that is run.

### Step 1 — as applied

| | |
|---|---|
| Allocation | `aid 72` (group 2) released, `aid 74` (group 3) claimed for record 31 |
| `study_group` | `2` → `3` at event 1089. Written by **SQL**, because `Records::saveData()` refuses writes to a randomization target field at its target event (`Records.php:6783-6789`) |
| Arm 3 materialized | `backfill-study-group-arms.php 268` → `materialized / written`, event 1097 |
| Arm 2 removed | 7 rows at 1093 deleted, then `Records::deleteRecordFromRecordListCache(268,'31',2)` — a raw delete does **not** clear the arm cache (doc 15, caveat 1) |
| Arms now | `1,3` — confirmed in `redcap_record_list` |
| Side effect, desirable | the MICA hook rewrote `ed_session_url` from the arm-2 session link to the **arm-3** one (hash resolves to `mica_ed_session` @ 1097) |

### Step 2 — as applied

`randomization_date = 2026-09-14` (a Monday), typed into the `admin` form **in a real browser** so
REDCap's own client-side action-tag engine produced the chain, not a script. Throwaway account from
`e2e-admin-form-user.php 268 setup`.

| Field | Value |
|---|---|
| `weekday` | 1 (Monday; the equation counts from Monday 2018-01-01) |
| `calc_monday_diff` | 0 |
| `first_monday` | 2026-09-14 |
| `first_monday_1200` | **2026-09-14 12:00** |
| `calc_esms_valid` | **1** ← the gate now passes |

That anchor puts **week 1 due 2026-09-20 12:00** (in the past) and **week 2 due 2026-09-27**
(future), so exactly one week is releasable at a time — which is what decision C asked for. To
advance to week N+1 on demand, backdate `redcap_surveys_scheduler_recurrence.first_send_time` by 7
days; due-ness is `first_send_time + times_sent × num_recurrence <= NOW()`
(`SurveyScheduler.php:2909`).

One cosmetic artifact: `calc_monday_diff` at event **1097** now holds the string `NAN`, because
`@CALCTEXT` ran there against an empty `randomization_date`. Nothing reads it — every reference is
prefixed `[day_1_ed_arm_1]` — but it is ugly in an export.

### Step 6 — ESMS configuration applied to 268

| Setting | Value |
|---|---|
| `phone-field` / `-event-id` | `phonen` / **1089** (`contact_info` is designated there only) |
| `twilio-number` / `-sid` / `-token` | reused from PID 268's own `redcap_projects` Twilio row, so texts come from **+1 650-618-8235** — the number the earlier alert tests used |
| `sms-opt-out-field` / `-event-id` | `sms_stop` / 1089 |
| `study-withdrawn-logic` | `[day_1_ed_arm_1][study_withdrawn(1)]="1"` |
| reminder / expiry | 60 min reminder, 180 min expiry, ASPIRE's wording. **Expiry is 1440 from 2026-09-30** ([ESMS_REPROMPT_TIMEOUT.md](../topics/alerts/ESMS_REPROMPT_TIMEOUT.md)) |

Verified against record 31: phone resolves to `+13306222938`, `isWithdrawn` false,
`getRecordOptOutStatus` false, ASI condition **true**.

Setting these from the CLI needs `define('CRON', true)` before `redcap_connect.php` —
`ExternalModules::ensureSetSettingIsAllowed()` skips the user-based permission check only when
`CRON` is defined (`ExternalModules.php:2469-2471`), and a CLI run has no session user.

### Step 8 — `condition_andor` must be `'AND'`, or the ASI is silently dead

Created with `condition_andor = NULL` first, and **nothing queued and nothing was logged**.

`checkConditionsOfRecordToSchedule()` seeds both of its pass-flags from a comparison against the
literal `'AND'` (`SurveyScheduler.php:2053`, `:2069`):

```php
$conditionsPassedSurveyComplete = ($thisSchedule['condition_andor'] == 'AND');
$conditionsPassedLogic          = ($thisSchedule['condition_andor'] == 'AND');
```

On NULL both start **false**, the guard around `evaluateLogicSingleRecord()` (`:2072-2074`) is never
entered — so the condition is *not even evaluated* — and the AND branch returns `false && false`
(`:2099`). The column is nullable and REDCap's own UI always writes `'AND'`; ASPIRE's twelve
reference ASIs all carry it. There is no warning anywhere. Worth knowing for anyone building an ASI
by SQL.

With `'AND'` set, `checkToScheduleParticipantInvitation('31')` returned `[1,0,1]` and produced both
rows:

| Table | Row |
|---|---|
| `redcap_surveys_scheduler_queue` | `ssq_id 5`, `instance **1**`, `QUEUED`, `scheduled_time_to_send 2026-09-20 12:00:00` (past → due) |
| `redcap_surveys_scheduler_recurrence` | `ssr_id 1`, `times_sent 1`, `first_send_time 2026-09-20 12:00:00` |

The recurrence row is the one that matters: without it weeks 2–12 never exist. It is seeded on the
**first** schedule regardless of the `$addRecurrenceSchedule` flag (`:2834-2847`). Week 2 becomes
due at `first_send_time + times_sent × 7 days` = **2026-09-27 12:00**.

`define('CRON', true)` in the scheduling script matters: without it, a send time already in the past
makes `scheduleParticipantInvitation()` call `emailInvitations()` **inline** (`:2822`) and the texts
go out during the scheduling call.

### Step 7 — what it requires, and why

Outbound SMS needs nothing extra: the module calls Twilio's API. **Inbound** replies need Twilio to
POST back to this instance, and the module's no-auth endpoint is

```
http://redcap.local/api/?type=module&prefix=enhanced_sms_conversation&page=pages%2Finbound&pid=268&NOAUTH
```

`redcap.local` is a private hostname. The number's webhook is in fact **already pointed at an
unreachable URL** — `sms_url = http://redcap.local/surveys/` on
`PN1c0642cabfb309aaa0a79879cf43a1cf` — so inbound SMS to +1 650-618-8235 currently goes nowhere and
repointing it costs nothing operationally. Restore value for afterwards:

| | |
|---|---|
| `SmsUrl` | `http://redcap.local/surveys/` |
| `SmsMethod` | `POST` |

There is no Twilio signature check on this path (only the 2FA path calls
`TwilioRC::verifyTwilioServerSignature`), so a tunnel hostname that differs from `redcap_base_url`
does not break it. `redcap_base_url_display_error_on_mismatch` is `1` system-wide and may need
turning off for the duration.

**The endpoint has a zero-side-effect probe.** `pages/inbound.php:34` ignores anything that is not a
POST, so a GET proves routing without touching a conversation. Verified locally before any tunnel
existed:

```
GET  …&NOAUTH                                   -> http 200, 0 bytes
POST …&NOAUTH  (disable-incoming-sms on)        -> <Response>The system is offline …</Response>
```

The POST variant is the better probe of the two — it proves the module executed *with project
context resolved* — but only do it with `disable-incoming-sms` temporarily on, otherwise
`processInboundMessage()` runs and replies by SMS to whatever `From` you supply.

**The sender cron is parked for the duration.** `redcap_crons.cron_enabled = 'DISABLED'` on
`SurveyInvitationEmailer`, so the due queue row sits still instead of firing on the next 60-second
tick. Re-enable it to release week 1. `esms_scan_conversation_state` is left enabled — it only
handles reminders and expiry for conversations that already exist.

### The anchor chain (all on `admin`)

```
randomization_date            (text, typed)
  └─ weekday                  calc
      └─ calc_monday_diff     @CALCTEXT(if([weekday]>=4, 8-[weekday], 1-[weekday]))
          └─ first_monday     @CALCDATE([randomization_date], [calc_monday_diff], 'd')
              └─ first_monday_1200  @CALCDATE([first_monday], 12+[time_diff], 'h')
```

Record 31 has `time_diff = 0` and no `randomization_date`, so the whole chain is blank.

---

## 4. The `sunday` rewrite

63 messages, 88 broken references. Counts confirmed against PID 268:

```sql
SELECT form_name,
       SUM(branching_logic REGEXP 'week_[0-9]+_sms_arm_1') AS week_sms_branches,
       SUM(COALESCE(branching_logic,'') LIKE '%baseline_arm_1%'
        OR COALESCE(element_label,'')  LIKE '%baseline_arm_1%') AS baseline_arm_1_refs
FROM redcap_metadata WHERE project_id = 268 GROUP BY form_name
HAVING week_sms_branches > 0 OR baseline_arm_1_refs > 0;
-- sunday 61 / 27   admin 0 / 2   close 0 / 1
```

### The week gates were far more broken than §1 of the status doc recorded

Only the `ar_*` family was ever per-week. Dumped from PID 268 before the rewrite:

| Family | Gate as shipped | Should be |
|---|---|---|
| `ar_1` … `ar_11` | weeks 1–11 | correct |
| `ar_12` | **week 11** | week 12 — duplicate-week copy/paste, wrong in ASPIRE's own terms |
| `sd_1` … `sd_12` | **all twelve on week 12** | one per week |
| `bd_1` … `bd_12` | **all twelve on week 12** | one per week |
| `gp_1` … `gp_12` | **all twelve on week 1** | one per week |
| `gg_1` … `gg_12` | **all twelve on week 1** | one per week |

So 60 of the 63 messages were either unreachable or would have fired as a block. The status doc
flagged only `ar_12`.

### Applied by [`apply-weekly-sms-branching.php`](../phase-3-handoff/scripts/apply-weekly-sms-branching.php)

Idempotent; a second run reports `branching rewritten 0 / already ok 63`.

| Change | Count | Why |
|---|---|---|
| `[event-name]="week_N_sms_arm_1"` → `[current-instance]=N` | 61 | the events do not exist in MICA; decision A picked instances |
| `[baseline_arm_1][…]` → `[day_1_ed_arm_1][…]` in labels | 3 | §1d |
| every family put on its own week | 63 gates rewritten | table above |
| blank guards — each gate requires `[dquant] <> ''` | 36 | REDCap compares loosely, so `[dquant]=0` is true while unanswered. This is what made all 24 feedback messages render at once. |
| `ar_*` / `sd_*` de-overlapped — `sd_*` now requires `[dquant] > 0` | 12 | `sd_*` was `[dquant] < threshold`, also true at zero drinks, so an alcohol-free week fired both the congratulation **and** "You reported drinking this week while staying below higher-risk levels" |
| `no_plan` `([drink_fut]=0)` → `[drink_fut]='0'` | 1 | displayed "Great! Thanks for letting us know." before the question was asked |
| `gset` `([drink_fut]=1)` → `[drink_fut]='1'` | 1 | same class |
| instructions `"Hi again. It's the TRAM Team checking in."` → MICA | survey | third study's name; it was the only survey in 268 still saying it. Now zero TRAM/ASPIRE strings in `redcap_surveys` for 268. |
| `redcap_surveys.title` `NULL` → `MICA Weekly Check-In` | survey | blank browser tab |

### `gset`'s question was broken in a way only a live dry-run exposed

The first simulated conversation sent this to the participant, tokens and all:

> Would you be willing to commit to a goal to drink less than
> `[day_1_ed_arm_1][calc_gset_threshold]` and fewer than
> `[day_1_ed_arm_1][calc_dquant_threshold]` drinks on any occasion?

**`calc_dquant_threshold` exists in no project** — not 192, not 262, not 268. And MICA's
two-threshold sentence is a local corruption: ASPIRE's actual label, identical in both 192 and 262,
is single-threshold —

> Would you be willing to commit to a goal to drink `[calc_gset_threshold]` or fewer drinks on any
> occasion?

Fixed by restoring ASPIRE's wording and creating the one field that *is* referenced.
`calc_gset_threshold` is keyed on `s_sex` (4 male / 3 otherwise), matching both ASPIRE's 4/3 split
and the `s_sex` convention its sibling `calc_binge_threshold` already uses. Placed on `auditc`
immediately after `calc_binge_threshold`, with `field_order` integrity asserted. Record 31 now
computes `calc_gset_threshold = 4`, and the question renders *"…to drink 4 or fewer drinks on any
occasion?"*

`debug_sunday` is `@HIDDEN-SURVEY`, which both mechanisms skip, so it never reaches a participant.
Its label still references `[day_1_ed_arm_1][group]`, a field MICA does not have. Left as is.

### Dry run — the conversation, before anything was sent

Driven through `FormManager` directly, mirroring the module's own loop
(`EnhancedSMSConversation.php:132-151` for the opening turn, `:554-604` for each reply). No
`TwilioManager` is constructed, so nothing leaves the machine.

```
=== week 1 (instance 1) ===              === week 3 ===                  === week 12 ===
MICA -> Hi Ihab, it's the MICA Team      … same opener                   … same opener
        checking in.
MICA -> How many total standard          MICA -> (same)                  MICA -> (same)
        alcohol drinks did you have
        over the past 7 days?
YOU  -> 0                                YOU  -> 7                       YOU  -> 6
MICA -> Amazing job staying              MICA -> Sometimes drinking      MICA -> Your goals, values,
        alcohol-free this week! …                patterns move us …              and priorities are …
        [ar_1]                                   [bd_3]                          [bd_12]
MICA -> Are you planning on drinking alcohol this week?   (Yes / No)
YOU  -> Yes                              YOU  -> No                      YOU  -> Yes
MICA -> Would you be willing to          MICA -> Great! Thanks for       MICA -> (same question)
        commit to a goal to drink                letting us know.
        4 or fewer drinks on any                 [no_plan]
        occasion?  (Yes / No)                    [complete]
YOU  -> Yes                                                              YOU  -> No
MICA -> Great decision! Setting a                                        MICA -> Taking time to consider
        drinking limit … [gg_1]                                                  both the benefits … [gp_12]
        [complete]                                                       MICA -> This completes the text
                                                                                 messaging intervention part
                                                                                 of the MICA study.
                                                                                 [sun_end_week_12]
```

Every branch resolved to the right week, `[first_name]` and the threshold both piped, and
`no_plan` no longer fires early. 4–6 texts per week.

### A dead setting, worth knowing

`disable-outgoing-sms` is declared in the module's `config.json:171` and **never read anywhere in
the code** — only `disable-incoming-sms` is, at `pages/inbound.php:40`. So it cannot be used as a
dry-run guard; ticking it gives false confidence. Dry-run through `FormManager` instead, as above.

---

## 5. The live run

### Step 7 — as applied

| | |
|---|---|
| Tunnel | `ngrok http 80` → `https://2979-171-66-135-15.ngrok-free.app` (ngrok is installed with an authtoken; cloudflared is not) |
| Probe through the tunnel | GET → `200 / 0 bytes`; POST with `disable-incoming-sms` on → `<Response>The system is offline …</Response>`. No ngrok interstitial on a form-encoded POST with a Twilio user-agent, and no base-URL-mismatch error. |
| Twilio `SmsUrl` | `PN1c0642cabfb309aaa0a79879cf43a1cf` repointed to the tunnel's `pages/inbound` NOAUTH URL, `SmsMethod=POST` |
| Restore afterwards | `SmsUrl = http://redcap.local/surveys/`, `SmsMethod = POST` |

### Releasing one week at a time

`SurveyScheduler::emailInvitations($ssq_id)` skips the recurring-ASI expansion block entirely when
given an id (`:2882`, `if ($ssq_id == null)`), so it sends **that one invitation and nothing else,
in any project**. That is the precise lever for decision C, and it is safer than
`emailInvitations()` with no argument, which sweeps every due invitation instance-wide.

`SurveyInvitationEmailer` stays `DISABLED` for the duration so nothing releases itself on the
60-second tick.

To advance to week N+1: subtract 7 days from `redcap_surveys_scheduler_recurrence.first_send_time`,
then let the expansion run. Due-ness is `first_send_time + times_sent × num_recurrence <= NOW()`
(`:2909`), and `times_sent` increments per send.

### ✅ Week 1 RESULT — 2026-09-21 — delivered

Released `ssq_id 5` (instance 1, due 2026-09-20 12:00). `emailInvitations(5)` returned `[1,0]`;
queue row → `SENT` at 11:26:43.

| Evidence | Result |
|---|---|
| Twilio API message log | **2 messages, both `delivered`**, `+16506188235 → +13306222938` |
| Body 1 | *"Hi Ihab, it's the MICA Team checking in."* — `[first_name]` piped |
| Body 2 | *"How many total standard alcohol drinks did you have over the past 7 days?"* |
| `redcap_outgoing_email_sms_log` | **no new row** — the `@ESMS` hijack suppressed the ASI email rather than sending it alongside |
| ESMS state | one `ConversationState` row for record 31, two `MessageHistory` rows |

### 🔴 Week 1, first attempt — the replies were written to the WRONG RECORD

The outbound half was perfect and the conversation ran to completion, so this looked like a pass
until the data was checked. **Every answer landed on record 2.** Record 31 got nothing:

```
record  event  instance  field            value
2       1098   NULL      dquant           22
2       1098   NULL      drink_fut        1
2       1098   NULL      gset             1
```

The module's own log names the cause:

```
ERROR getRecordIdByCellNumber  More than one record is registered with phone number
                               +13306222938: 2,4,17,31 -- taking first value
DEBUG processInboundMessage    [2] Inbound response: 22
DEBUG handleReply              Found CS#3410 for Record 2 / 1 / +13306222938 at field [dquant]
DEBUG saveResponseToREDCap     [2] Saved dquant for instance 1 ... {"2":{"repeat_instances":...}}
```

**Four records in PID 268 shared `(330) 622-2938`** — 2, 4, 17 and 31.

#### The defect, precisely

`EnhancedSMSConversation.php:730-733` detects the ambiguity, logs an **error**, and then proceeds
anyway with the first match:

```php
if (count($results) > 1) {
    $this->emError("More than one record is registered with phone number $number: "
                 . implode(",", array_keys($results)) . " -- taking first value");
}
$result = empty($results) ? null : strval(key($results));
```

`key($results)` is whatever REDCap's `getData` returned first — here record 2, and record 31 is
last.

The deeper problem is the **ordering** in `processInboundMessage()`. It binds the record from the
phone lookup at `:453`, and only afterwards finds the active conversation at `:530`:

```php
$record_id = $this->getRecordIdByCellNumber($from_number);          // :453  ambiguous guess
...
if ($CS = ConversationState::getActiveConversationByNumber($this, $cell_number)) {   // :530
```

`ConversationState` already knows whose conversation it is — CS#3410 was created for record 31 —
so the phone lookup is redundant once an active conversation exists, and it is the *less* reliable
of the two. **The fix is to prefer `$CS->getRecord()` over the phone lookup whenever an active
conversation is found**, and to refuse rather than guess when a genuinely new conversation arrives
on an ambiguous number.

#### Why this is not just a test-data problem

It is a one-line log line in a dev project and a mis-attributed clinical response in production.
Shared handsets are ordinary in an ED population — partners, parents and adult children, people
between phones. Two enrolled participants on one number means one of them silently answers for the
other, every week, for twelve weeks, with no visible error. The tailored feedback is then computed
from the wrong person's drinking threshold too.

`sms_stop` inherits the same flaw: an opt-out texted from a shared number is written to whichever
record sorts first, so the wrong participant is suppressed and the right one keeps receiving texts.

#### What was done here

Not a module patch — `enhanced_sms_conversation` is third-party and shared with PID 192, so
changing it is a separate decision. For this test the ambiguity was removed instead:

| Record | `phonen` before | after |
|---|---|---|
| 2 | (330) 622-2938 | (330) 622-2901 |
| 4 | (330) 622-2938 | (330) 622-2904 |
| 17 | (330) 622-2938 | (330) 622-2917 |
| **31** | (330) 622-2938 | **unchanged — now unique** |

Backup of every `phonen` row before the change: `phonen_268_pre.sql` in the session scratchpad.
Record 2's misrouted rows at event 1098 were deleted. `getRecordIdByCellNumber('+13306222938')` now
returns `'31'`.

### ✅ Week 1, second attempt — 2026-09-21 11:32 — correctly bound

After [`reset-week.php`](#) cleared the conversation state, the survey responses, the queue row and
the recurrence seed, `checkToScheduleParticipantInvitation` re-queued instance 1 as `ssq_id 6` and
`emailInvitations(6)` returned `[1,0]`.

| | |
|---|---|
| Twilio | 2 messages, both `delivered` |
| `ConversationState` | **#3419, record 31** — the right record this time |
| Data at 1098 | empty, awaiting the reply |

### ✅ Week 1 RESULT — 2026-09-21 — PASSED on record 31

Replied `13`, which is at or above the binge threshold of 5, so the heavy-drinking branch was the
one exercised. Every message and every stored value was correct:

| | As delivered |
|---|---|
| 1 | *"Hi Ihab, it's the MICA Team checking in."* — `[first_name]` piped |
| 2 | *"How many total standard alcohol drinks did you have over the past 7 days?"* |
| ← | `13` |
| 3 | *"This week's drinking was in the range considered unhealthy. Taking a moment to consider how that fits with your personal goals may be worthwhile."* — **`bd_1`**, the right family and the right week |
| 4 | *"Are you planning on drinking alcohol this week?"* (Yes / No) |
| ← | `Yes` |
| 5 | *"Would you be willing to commit to a goal to drink **4** or fewer drinks on any occasion?"* — `calc_gset_threshold` piped |
| ← | `Yes` |
| 6 | *"Great decision! Setting a drinking limit is a powerful way to stay in control…"* — **`gg_1`** |

All eight Twilio messages `delivered`. Stored on **record 31**, event 1098:

```
dquant 13   drink_fut 1   gset 1        (instance 1)
```

`instance = NULL` in `redcap_data7` is correct — REDCap stores the **first** instance as NULL and
numbers only from 2 upward. Confirmed against this project's own `mica_safety_finding`, which holds
`NULL, 2, 3, 4` for instances 1–4. It is not a lost instance number.

This single week proves the whole chain: recurring ASI → `@ESMS` subject → `redcap_email` hijack →
instance-aware branching → Twilio out → tunnel → `pages/inbound` → answer stored against the right
instance → next turn. Requirement met for arm 3's weekly SMS.

Re-run on the alcohol-free branch, same week, reply `0`: **`ar_1`** *"Amazing job staying
alcohol-free this week! Your body and mind will thank you for it"* → threshold `4` → **`gg_1`**.
Stored `dquant 0, drink_fut 1, gset 1`. So two of the three feedback families are confirmed against
the same instance.

### ✅ Advancing the cadence works — instance 2 is real

The mechanism, not a faked queue row. `advance-week.php` subtracts one 7-day interval from
`redcap_surveys_scheduler_recurrence.first_send_time` and then calls `emailInvitations()` with **no**
argument so the recurrence-expansion block actually runs (`:2882`):

```
before: times_sent=1  first_send_time=2026-09-20 12:00:00
after:  times_sent=1  first_send_time=2026-09-13 12:00:00  -> next due 2026-09-20 12:00:00
emailInvitations() => [1,0]

queue: ssq_id 7  instance 1  SENT
queue: ssq_id 8  instance 2  SENT   <-- created by the expansion
recurrence: times_sent=2  first_send_time=2026-09-13 12:00:00  last_sent=2026-09-21 11:37:02
```

REDCap allocated **instance 2** on its own (`max(q.instance)+1`, `:1440`) and incremented
`times_sent`. Two independent `ConversationState` objects now exist for record 31 — instance 1
`COMPLETE`, instance 2 `ACTIVE` at `dquant` — so weeks do not collide.

Checked before running the un-argumented sweep: **no other invitation was due instance-wide**, and
ours was the only active recurring ASI. Do that check every time, or the sweep sends other
projects' due invitations too.

### ✅ Week 2 RESULT — 2026-09-21 — PASSED, and it proves per-week indexing

Reply `3` — above zero, below the threshold of 5, so the `sd_*` family. The message that came back
was **`sd_2`**, not `sd_1`:

> "You reported drinking this week while staying below higher-risk levels. Think about what helped
> you keep your drinking where you wanted it."

`sd_1` is *"Nice work this week. You stayed within lower-risk drinking levels…"* — a different
string. Then `Yes` → threshold `4` → `No` → **`gp_2`**:

> "Not everyone is ready to make a change immediately. Many people spend time weighing what they
> enjoy about drinking against what concerns them."

`gp_1` is *"Change often begins with paying attention rather than making a commitment…"* — again a
different string. **Both families indexed to week 2 on their own**, from `[current-instance] = 2`
alone. This is the property the whole repeating-instance decision rests on, and it holds live rather
than only in the dry run.

The two weeks are stored independently, in one event, on one record:

| instance | `dquant` | `drink_fut` | `gset` |
|---|---|---|---|
| 1 (stored NULL) | 0 | 1 (Yes) | 1 (Yes) |
| 2 | 3 | 1 (Yes) | 0 (No) |

Both `ConversationState` objects are `COMPLETE`.

### Branch coverage so far

| Family | Seen | Via |
|---|---|---|
| `ar_*` alcohol-free | ✅ | week 1, reply `0` → `ar_1` |
| `sd_*` below threshold | ✅ | week 2, reply `3` → `sd_2` |
| `bd_*` at/above threshold | ✅ | week 1, reply `13` → `bd_1` |
| `gg_*` goal set | ✅ | weeks 1 → `gg_1` |
| `gp_*` no goal | ✅ | week 2 → `gp_2` |
| `no_plan` | ⬜ | needs `No` to *"planning on drinking this week?"* — week 3 in the plan |
| `sun_end_week_12` | ⬜ | fires only at instance 12 |

Every mechanism is now proven. The two outstanding branches are content, not machinery.

### ✅ Jumping the cadence, and the 12-week cap holds

Requested: skip weeks 3–11 and go straight to 12, without texting nine conversations.
[`jump-to-week.php`](#) does it with REDCap's own skip mechanism rather than a hack — the next
instance is `IFNULL(MAX(q.instance),0)+1` over the queue (`:2948`), and REDCap itself inserts
`status = 'DELETED'` queue rows precisely so "future recurrences skip over it when calculating the
next instance" (`:2959-2961`).

So: placeholder `DELETED` rows for instances 3–11, `times_sent = 11`, and `first_send_time`
backdated 77 days so instance 12 is due now.

```
highest queue instance now: 2  -> target 12
placeholder rows inserted for instances 3-11
times_sent=11  first_send_time=2026-07-05 12:00:00  -> due 2026-09-20 12:00:00
other invitations due instance-wide: 0
emailInvitations() => [1,0]

queue: ssq_id 18  instance 12  SENT  11:40:53
recurrence: times_sent=12  last_sent=2026-09-21 11:40:53
```

REDCap allocated **instance 12**, and `ConversationState #3449` is `ACTIVE` at `dquant` for
instance 12 — alongside instances 1 and 2, still `COMPLETE` and untouched.

**The cap is real.** With `times_sent = 12` and `max_recurrence = 12`, the eligibility query the
sender uses —

```sql
WHERE a.active=1 AND a.num_recurrence>0
  AND (a.max_recurrence IS NULL OR r.times_sent < a.max_recurrence)
```

— now returns **zero rows** for `ss_id 255`. There is no week 13. The ladder stops itself.

### ✅ Week 12 RESULT — 2026-09-21 — PASSED. Branch coverage complete.

Replies `6` then `No`, chosen to pick up both outstanding branches in one conversation.

| | As delivered |
|---|---|
| ← | `6` |
| | *"Your goals, values, and priorities are uniquely your own. Taking time to consider whether your drinking reflects those priorities can be a powerful exercise."* — **`bd_12`**, the twelfth of the family, correctly indexed |
| | *"Are you planning on drinking alcohol this week?"* |
| ← | `No` |
| | *"Great! Thanks for letting us know."* — **`no_plan`** |
| | *"This completes the text messaging intervention part of the MICA study."* — **`sun_end_week_12`** |

`gset` was correctly **not** asked (`[drink_fut] = '1'` is false), and no `gset` value was stored for
instance 12 — so the goal branch skipped cleanly rather than firing on a blank. `sun_end_week_12`
fired anyway, which is right: it is gated only on `[current-instance] = 12`, independent of the goal
path.

Three weeks stored side by side in one event on one record:

| instance | `dquant` | `drink_fut` | `gset` |
|---|---|---|---|
| 1 | 0 | Yes | Yes |
| 2 | 3 | Yes | No |
| 12 | 6 | **No** | *(not asked)* |

All three `ConversationState` objects `COMPLETE`.

### Final branch coverage — all families exercised on a real handset

| Family | Seen as | Week |
|---|---|---|
| `ar_*` alcohol-free | `ar_1` | 1 (reply `0`) |
| `sd_*` below threshold | `sd_2` | 2 (reply `3`) |
| `bd_*` at/above threshold | `bd_1`, `bd_12` | 1 (reply `13`), 12 (reply `6`) |
| `gg_*` goal set | `gg_1` | 1 |
| `gp_*` no goal | `gp_2` | 2 |
| `no_plan` | `no_plan` | 12 |
| `sun_end_week_12` | `sun_end_week_12` | 12 |

Plus the machinery: recurring ASI → `@ESMS` subject → `redcap_email` hijack → instance-aware
branching → Twilio out → ngrok → `pages/inbound` → answer stored against the right instance → next
turn; per-week message indexing from `[current-instance]` alone; independent per-instance
conversation state; and a `max_recurrence` cap that stops the ladder at 12.

**Requirement met: the Arm 3 weekly SMS works end to end.**

### Two gaps found in passing

**1. The instrument is never marked Complete.** ESMS sets its own
`ConversationState.state = 'COMPLETE'` (`EnhancedSMSConversation.php:600`) and stops there. It does
**not** write the REDCap form status, and it does not stamp the survey response:

```
sunday_complete                            = 0      (Incomplete)
redcap_surveys_response.completion_time    = NULL
ConversationState.state                    = COMPLETE
```

REDCap's own SMS path does both — `Surveys/twilio_question.php:539-541` sets
`$_POST[$form_name."_complete"] = '2'` and marks the response complete. So with ESMS, "how many
weekly check-ins did this participant finish?" cannot be answered from form status or from the
survey response, only from the module's own log objects. Anything that filters on
`[sunday_complete] = '2'` — a report, an export, an ASI gated on survey completion — will see zero
completed weeks. Not a MICA regression: ASPIRE behaves the same way. Worth a decision before launch.

It also disables a REDCap safety net. `emailInvitations()` skips an instance when
`Survey::isResponseCompleted()` is true, inserting a `DELETED` placeholder instead of sending
(`SurveyScheduler.php:2955-2961`). Because ESMS never stamps `completion_time`, **that skip can
never fire** — a week the participant fully answered would still be re-invited if the recurrence
came round to it again. Harmless here only because `max_recurrence` caps the ladder at 12. Same root
cause, so fixing the form-status write closes both.

**2. Duplicate `record_id` rows.** Each turn's `REDCap::saveData()` writes a fresh `record_id` row
for the instance, so after a three-answer conversation event 1098 holds **three identical
`record_id` rows** for record 31. `redcap_data7` has no unique key to prevent it. Harmless to
REDCap, but it inflates row counts and will look odd in a raw export.

### The 12-week answer plan (decision D)

What to text each week to exercise every branch. Threshold is 5 (`calc_binge_threshold`, `s_sex=1`),
goal threshold 4 (`calc_gset_threshold`).

| Week | Reply 1 (drinks) | Feedback | Reply 2 (drinking this week?) | Reply 3 (commit to goal?) | Goal message |
|---|---|---|---|---|---|
| 1 | `0` | `ar_1` | Yes | Yes | `gg_1` |
| 2 | `3` | `sd_2` | Yes | No | `gp_2` |
| 3 | `7` | `bd_3` | No | — | `no_plan` |
| 4 | `0` | `ar_4` | Yes | No | `gp_4` |
| 5 | `2` | `sd_5` | Yes | Yes | `gg_5` |
| 6 | `9` | `bd_6` | No | — | `no_plan` |
| 7 | `0` | `ar_7` | Yes | Yes | `gg_7` |
| 8 | `4` | `sd_8` | Yes | No | `gp_8` |
| 9 | `12` | `bd_9` | Yes | Yes | `gg_9` |
| 10 | `0` | `ar_10` | No | — | `no_plan` |
| 11 | `1` | `sd_11` | Yes | Yes | `gg_11` |
| 12 | `6` | `bd_12` | Yes | No | `gp_12` + `sun_end_week_12` |

Coverage: `ar_*` ×4, `sd_*` ×4, `bd_*` ×4, `gg_*` ×5, `gp_*` ×4, `no_plan` ×3, `sun_end_week_12` ×1.
Every branch of the script, 4–6 texts per week.

**Reminder and expiry are live.** A reply more than **60 minutes** after a question triggers *"We
missed your response."*, and the conversation **expires after 180 minutes** with *"You must be busy.
We will check in again later."* Both are ASPIRE's settings and worth seeing once, but they will
abandon a week if the session is left sitting.

> **Corrected 2026-09-30.** The reminder half of that paragraph was never true. In the unfixed module
> the re-prompt omits "We missed your response.", and for `gset`, the question with branching logic,
> it is empty and Twilio drops it. The expiry is now 24 h. See
> [ESMS_REPROMPT_TIMEOUT.md](../topics/alerts/ESMS_REPROMPT_TIMEOUT.md).

## 6. What is deliberately **not** covered

- **Production (PID 35968).** Untouched. Everything here is dev.
- **`thursday`.** MICA has no such instrument; the twice-weekly half of ASPIRE's cadence is not
  built. Decision B.
- **`sms_stop` still has no automatic writer** (O5 in [`README.md`](alerts-257/README.md)). Twilio blocks a
  STOP at its end and REDCap never learns — except that **ESMS does handle this**:
  `EnhancedSMSConversation.php:47` lists `OPT_OUT_KEYWORDS = ['stop','optout']` and the module
  writes its configured `sms-opt-out-field`. Worth re-testing O5 against the module once the
  cadence runs.
- **Link delivery to a handset.** `redcap.local` resolves only inside the dev setup. The SMS
  conversation does not send links, so this does not block; but any survey link texted from this
  instance will not open on a phone unless the tunnel host is used.
