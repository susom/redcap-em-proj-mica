# Arm 3 weekly SMS: rebuilt on PID 271 (2026-09-23)

**Status:** built and verified on localhost PID 271. **The invitation is INACTIVE**, so nothing sends.
**Prod:** not touched. Follow [ARM3_WEEKLY_SMS_PROD_RUNBOOK.md](../../runbooks/arm3-weekly-sms-prod.md).

Background: the original build on PID 268 ([ARM3_WEEKLY_SMS_BUILD.md](../../archive/ARM3_WEEKLY_SMS_BUILD_268.md))
was reverted on 2026-09-21 on request, and 268 was deleted the next day. PID 271 was copied from
prod, and prod never had the weekly SMS, so 271 didn't have it either. The weekly SMS is **not an
alert**. It is an Automated Survey Invitation (ASI) on `sunday`, so it never appears under Alerts &
Notifications.

## What 271 had and lacked before the rebuild

| Piece | Before | Now |
|---|---|---|
| Enhanced SMS Conversation module enabled and configured | ✅ already (`apply-esms-config.php`, 2026-09-22) | unchanged |
| `sunday` repeating at `weeks_112_arm_3` (event 1113) | ❌ | ✅ custom label `Week [current-instance]` |
| `sunday` branching: 61 dead `week_N_sms_arm_1` gates + `[baseline_arm_1]` labels | ❌ 64 dead refs, **REDCap refused to render the survey** | ✅ 0 dead refs, 63 gates rewritten |
| `calc_gset_threshold` (the goal number in the `gset` question) | ❌ missing | ✅ on `auditc` after `calc_binge_threshold`; recalculated on both records (= 4) |
| Survey title / TRAM branding | title blank, "It's the TRAM Team" | `MICA Weekly Check-In`, "MICA Team" |
| Recurring ASI | ❌ none | ✅ `ss_id 256`, **inactive** |

## Before, in a real browser

`node e2e/weekly-sms-sunday.js <record 1 sunday link> before`. Desktop and mobile both showed:

- **SURVEY ERRORS EXIST: CANNOT CONTINUE!**, REDCap's branching-error page
- "Hi again. It's the TRAM Team checking in."
- a blank survey title ("the survey titled .")
- every feedback message rendered at once

Screenshots: `e2e/shots/weekly-sms-before-{desktop,mobile}.png`.

## Build steps as applied

Rollback, taken first:
[`rollback-weekly-sms-271-20260923-112658.sql`](../../phase-3-handoff/scripts/rollback-weekly-sms-271-20260923-112658.sql).
It covers all of 271's `redcap_metadata`, because the new field shifts `field_order` project-wide.
It also removes the ASI and 1113's repeat rows, and restores the survey title and instructions. It
uses an **UPDATE** for the survey row, because deleting from `redcap_surveys` cascades to that
survey's participants and responses.

1. `redcap_events_repeat` ← `(1113, 'sunday', 'Week [current-instance]')`
2. `apply-weekly-sms-branching.php 271`: 63 branching rewrites, 3 labels, and `calc_gset_threshold`
   created. A rerun reports `0 / already ok 63`.
3. `redcap_surveys` 1427: title `MICA Weekly Check-In`, instructions TRAM → MICA.
4. `Calculate::saveCalcFields([], ['calc_gset_threshold'])` recalculates **only** the new field.
   Calcs are stored only when a record is saved, so until this runs `gset` pipes a blank ("drink or
   fewer drinks").
5. `apply-weekly-sms-asi.php 271` creates `ss_id 256`, INACTIVE.

Then the prod route was proven on 271 as well: see the end of this doc.

## Verification

| Check | How | Result |
|---|---|---|
| Survey renders | `e2e/weekly-sms-sunday.js`, desktop 1400×950 + iPhone 13 | **PASS**: no branching error, `Hi Ihab` piped, no TRAM, title set, 0 px horizontal overflow |
| REDCap's own UI reads the SQL-built ASI | `e2e/weekly-sms-asi.js` as a design-rights user, Online Designer → Automated Invitations | **PASS**: Not Active; Email type; `@ESMS` subject; condition + AND + "Ensure logic is still true"; 6 days after `[day_1_ed_arm_1][first_monday_1200]`; every 7 days, 12 times |
| Conversation content, all branches | `simulate-weekly-sms.php` (sends nothing, cleans up after itself) | **PASS**, identical to the 09-21 handset run. See below. |
| ASI condition per record | `REDCap::evaluateLogic` | record 1 (arm 3) **true**; record 2 (arm 2) **false** |

```
week 1  reply 0,Yes,Yes  -> ar_1 "Amazing job staying alcohol-free…" -> "goal to drink 4 or fewer" -> gg_1
week 2  reply 3,Yes,No   -> sd_2 "…staying below higher-risk levels…"                        -> gp_2
week 3  reply 7,No       -> bd_3 "Sometimes drinking patterns move us…"                      -> no_plan
week 5  reply 2,Yes,Yes  -> sd_5 "Great job staying within lower-risk…"                      -> gg_5
week 12 reply 6,No       -> bd_12 -> no_plan -> sun_end_week_12 "This completes the text messaging…"
```

The ASI dialog screenshot `e2e/shots/weekly-sms-asi-dialog.png` is the field-by-field template for
building the ASI by hand on prod.

## The prod route, tested on 271

Prod has UI access only, so the `sunday` rewrite has to go through a Data Dictionary upload. It was
run on 271 exactly the way prod will run it:

1. Exported 271's dictionary **before** the build.
2. `patch-weekly-sms-data-dictionary.php` on that export gave 63 logic rewrites, 3 labels, and 1 field
   added. It reported 0 rows changed elsewhere and ended `*** OK ***`.
3. Compared the patched CSV with 271's **post-build** export: **0 differing rows**.
4. Restored 271's metadata to pre-build and uploaded the patched CSV **through REDCap's Data
   Dictionary page** (`e2e/weekly-sms-dd-upload.js`). REDCap's preview read "271 fields, which will
   replace the 270 fields", with only pre-existing allowable warnings. Committed: "Changes Made
   Successfully!".
5. 271's dictionary after the upload vs. the DB-script build: **0 cell differences**. The branching
   script's dry run reports `already ok 63 / dead refs 0`.

So on prod, the upload produces exactly the instrument that was verified here.

Two traps found along the way:

- **REDCap's download prefixes every `@…` annotation with a space.** All 43 in 271's export have
  one; the database stores them without it. That is REDCap's CSV-injection guard. The patcher follows
  the same convention for the field it adds, and REDCap strips the space on upload.
- **A scripted Commit is rejected as CSRF if it beats REDCap's token.** `appendCsrfTokenToForm()`
  adds the token 100 ms after DOM-ready. The response reads *"Cross-site Request Forgery (CSRF) was
  detected and **successfully** blocked"*, which a naive `/success/` check reads as a pass. The E2E
  script waits for `REDCap.appendCsrfTokenToFormComplete`. A human clicking never hits this.

## Before activating on 271

Activating is the one step that texts a real phone, so it waits for an explicit go-ahead.

- **Activating alone schedules nobody.** The logic has no `datediff(…'today'…)`, so no cron re-checks
  it; REDCap only evaluates it when a record is saved, or via Online Designer → "Auto Invitation
  options" → "Re-evaluate Automated Survey Invitations". **Once record 1 is re-evaluated or saved, it
  is texted within a minute.** Its anchor is `first_monday_1200 = 2026-09-14 12:00`, so week 1 was
  due 2026-09-20 12:00, which is in the past, and `SurveyInvitationEmailer` runs every minute.
- **Record 1's anchor chain was computed by a person saving `admin` in the browser** (log 26877),
  not by automation. `stamp-randomization-date-field` is **blank on 271**, so a newly randomized
  arm-3 participant here gets no `randomization_date` and is never texted. A scratch-record test
  showed that a server-side stamp *does* compute the chain through `first_monday_1200`, but only
  when `time_diff` (from `pre_screen`) exists. See runbook 0f.
- **Replies would be dropped.** `disable-incoming-sms = true` on 271, and no public tunnel points at
  this instance. Outbound works; the conversation stalls after the first question. The ngrok setup
  from the 268 run (build doc §5, Step 7) is what makes replies work locally.
- **Records 1 and 2 share `(330) 622-2938`.** The module binds an inbound reply to the *first*
  record with that number (`EnhancedSMSConversation.php:730-733`), so replies can land on record 2.
  Give record 2 a distinct number before a live run, as was done on 268.

## Known gaps, unchanged from the 268 build

- ESMS never sets `sunday_complete = 2`, so completed weeks are invisible to form-status reports.
- Shared phone numbers mis-route replies (above).
- `thursday` (ASPIRE's second weekly message) is not built.
- Reminder at 60 min, expiry at 180 min (ASPIRE's values): a week left sitting is abandoned.
  **Superseded 2026-09-30:** the expiry is 24 h, and the unfixed module's re-prompts were broken. See
  [ESMS_REPROMPT_TIMEOUT.md](ESMS_REPROMPT_TIMEOUT.md). 271 itself still has 180.
