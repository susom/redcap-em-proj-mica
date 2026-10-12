# 2026-10-05: TSR opens the MICA chat directly (no empty Close page)

Reported on local **PID 283 "Mica Prod"** (built 17:35 from the prod export, queue = today's FIX CSV,
104/104 entries identical). Two complaints: when a participant finishes TSR, the chat doesn't open;
and they have to Submit a Close page that is empty.

**Status:** fixed and verified on 283 with **configuration only. No module code changed.**
Prod needs the steps in [Prod steps](#prod-steps-35968), done through the user.

## What the participant saw (reproduced on 283, before the change)

| Arm | After TSR | After Submit on Close |
|---|---|---|
| 2 / 3 (record 3) | **Close @ Day 1 (ED) Arm 1, blank**: the logo and a Submit button, nothing else | the MICA ED session login |
| 1 Standard Care (record 2) | the same blank Close | TSR's own "Thank you for taking the survey" page |
| not randomized (record 1, 17:40) | the same blank Close | "Someone from the study team will be with you shortly" (no chat) |

## Why

1. **The chat didn't open on the first walk because 283 had no allocation table.** Randomization rid 17
   had 0 rows, so the TSR submit at 17:40:09 randomized nobody. `ed_session_url` kept the module's
   `state=pending` handoff link, written at 17:37:57, and Close redirected there. Randomization was
   then switched off (17:43:51) and `study_group = 3` set by hand (17:44:12); only after that did
   Close lead to the chat. This is a replica-setup issue, not a flow bug. Prod has its real table.
2. **The empty page is the survey queue, not the module.** TSR is answered at **Day 1 (ED) Arm 1** by
   everyone, because randomization happens on that submit. The PI's queue says the right thing for
   arms 2/3, "MICA ED session when TSR is completed", but names TSR on **their own** Day-1 event
   (`day_1_ed_arm_2/3`), which nobody completes. Those entries never fire. The queue then falls through
   to **Close @ arm 1** ("when TSR @ arm 1 is completed"), and Close's redirect `[ed_session_url]` is
   the only thing that opens the chat.
3. **Close is blank for everyone.** Its four messages branch on `[event-name]='baseline_arm_1'`, an
   event that doesn't exist, and on `month_N_arm_1` only. So Close is blank at Day 1 in every arm,
   and at every follow-up in arms 2/3. This was already reported in doc 33 §C (2026-10-02) and is
   still on prod.
4. **`session-fallback-instrument = tsr`**, set at 17:42 while experimenting, sent Standard Care from
   Close back to their own completed TSR.

REDCap facts this rests on (17.2.3):
- the queue is cross-arm: it orders every event in the project, arm 1 first, with no arm filter
  (`Survey.php:300-360`);
- `redcap_survey_complete` fires before the queue's auto-start redirect (`Survey.php:476-485`);
- End Session writes `mica_ed_session_complete = 2` (`MICA.php:3093`), so an ended chat stops being a
  queue capture point.

## The change (3 queue entries, alert 17's trigger, 4 branching lines, 1 module setting)

**Survey queue**: [`survey-queue/MICA-35968-SurveyQueue-HANDOFF-20261005.csv`](survey-queue/MICA-35968-SurveyQueue-HANDOFF-20261005.csv),
built by [`make_handoff_queue_csv.py`](survey-queue/make_handoff_queue_csv.py) from the FIX CSV. Same
104 entries, 3 changed; every other entry, and every guard, is untouched.

| Entry | "When survey is completed" | "… AND logic" (plus the existing later-timepoint guard) |
|---|---|---|
| MICA ED session @ Day 1 (ED) **Arm 2** | TSR @ Day 1 (ED) **Arm 1** (was Arm 2) | `[day_1_ed_arm_1][study_group] = '2'` |
| MICA ED session @ Day 1 (ED) **Arm 3** | TSR @ Day 1 (ED) **Arm 1** (was Arm 3) | `[day_1_ed_arm_1][study_group] = '3'` |
| Close @ Day 1 (ED) **Arm 1** | TSR @ Day 1 (ED) Arm 1 (unchanged) | `([day_1_ed_arm_1][study_group] = '1' OR [day_1_ed_arm_1][study_group] = '')` |

The blank-group half of the Close condition is the safety net: if randomization fails, the participant
still gets Close, then the "someone will be with you shortly" page.

> **Protocol point.** Because the queue is cross-arm, the `study_group` clause on the two session
> entries is the only thing keeping a Standard Care participant out of the intervention. The arm number
> must match the entry's event. The E2E below asserts that a Standard Care record never gets a
> session link.

**Alert 17 "CRC - baseline complete"**: trigger on **TSR** instead of Close. Condition:

```
[event-name]='day_1_ed_arm_1' and [tsr_complete]='2' and [baseline1_complete]='2'
```

Until now, every arm passed Close @ arm 1 straight after TSR, so alert 17 effectively fired at the TSR
submit. Arms 2/3 now reach a Close only after the chat **and** the CEMI. The hourly closer finalizes a
chat but submits neither page. An ED participant who is discharged mid-chat would then never trigger
"baseline complete", though they finished it. Triggering on TSR keeps the original timing for every
arm, covers a failed randomization, and doesn't depend on queue routing. The `[event-name]` clause
keeps the Month-12 TSR from firing it. It is still "send once". Its text still says to "set *Is the
participant ready to be randomized?*", which no longer applies, because randomization is automatic.

(A prefixed Close condition, `[day_1_ed_arm_1][baseline1_complete]`, also works and passed records
5–10. It was dropped for the abandoned-chat gap above.)

**Close branching** (data dictionary; Draft Mode on prod):

| Field | Branching logic |
|---|---|
| `desc_baseline_end` | `[event-name]='day_1_ed_arm_1' or [event-name]='day_1_ed_arm_2' or [event-name]='day_1_ed_arm_3'` |
| `desc_3m_end` | `[event-name]='month_3_arm_1' or [event-name]='month_3_arm_2' or [event-name]='month_3_arm_3'` |
| `desc_6m_end` | same, `month_6_arm_N` |
| `desc_12m_end` | same, `month_12_arm_N` |

**Module setting `session-fallback-instrument`**: blank (was `tsr` on 283). Prod: check it is blank or
`close`; either gives the handoff page.

All expressions pass `LogicTester::isValid`.

## The flow now

| Arm | Pages after TSR |
|---|---|
| 2 / 3 | **MICA ED session login** (no Close) → chat → End Session → CEMI → Close @ own arm ("$20 baseline" message) → "Thank you. You have finished this part of the study." |
| 1 Standard Care | Close @ arm 1 ("$20 baseline" message) → "Thank you. You have finished this part of the study." |
| not randomized | Close @ arm 1 ("$20 baseline" message) → "Thank you. Someone from the study team will be with you shortly to continue." |

## Verified on 283 (2026-10-05)

`node e2e/day1-handoff.js 283 [--mobile] [--chat] [--expect-arm 1|2|3|none]`. Every walk starts from
arm 1's public link and goes through the whole Day 1, e-Consent and signatures included. The record
is randomized by REDCap's own trigger on the TSR submit, against a stratified **test** allocation table.

| Record | Arm | Device | Result |
|---|---|---|---|
| 3 | 2 | desktop | **before** the change: H2/H3 FAIL, blank Close @ arm 1 (the reproduction) |
| 4 | 3 | desktop, `--chat` | all pass (alert 17 deleted on 283 at the time: not asserted) |
| 5 | 2 | iPhone 13, `--chat` | all pass, alert 17 sent once at Close @ arm 2 |
| 6 | 1 | desktop | all pass, no session link minted, alert 17 sent once |
| 7 | none (randomization off) | desktop | all pass, "shortly" page, alert 17 sent once |
| 8 | 3 | iPhone 13, `--chat` | all pass, alert 17 sent once at Close @ arm 3 |
| 9 | 3 | iPhone 13 | all pass |
| 10 | 1 | iPhone 13 | all pass |
| 11 | 2 | desktop, chat **never opened** | all pass; alert 17 (TSR trigger) sent once anyway |
| 12 | 1 | iPhone 13 | all pass, alert 17 (TSR trigger) sent once |
| 13 | 3 | desktop, `--chat` | all pass; alert 17 sent once at TSR, and no second send at the arm-3 Close |

No horizontal overflow on any page. **All 40 alerts on 283 are deleted** (`email_deleted = 1`), as built.
Alert 17 was un-deleted only for records 5–13, then deleted again. Its emails ("MICA n: Baseline
done") arrived in the local mailpit sink. Records 5–10 used the prefixed-Close condition, 11–13 the TSR
trigger.

**One new side effect, measured.** An arm-2/3 participant who **never logs into** the chat keeps the
session's queue entry open. The hourly closer skips sessions with no messages
(`SessionWindow.php:95`), and the entry's later-timepoint guard ends it only at Month-3 AUDIT-C. Until
then, submitting any other survey by web link sends them to the Day-1 chat login. Record 9 (arm 3,
chat never opened) submitted a weekly check-in by web link and landed on "MICA ED session". Before
this change that entry never activated. On prod the weekly check-in is SMS (ESMS), which never
redirects, so exposure is a web survey between Day 1 and Month 3. If that matters, the options are:
- a date bound on the two session entries, e.g.
  `datediff([day_1_ed_arm_1][randomization_date], 'today', 'd', true) <= 1`. It's untested, and it
  would block the handoff for a record with a blank `randomization_date`.
- having the closer also close never-opened sessions (module code).

Not covered: a real conversation with the model (End Session follows login straight away), and Month 3+.

## Prod steps (35968)

Done through the user, in this order. Each step takes effect immediately in Production, except the
Draft Mode step.

1. **Back up the queue**: Online Designer → Survey Queue ▾ → *Download Survey Queue Setup (CSV)*.
   Diff it against `MICA-35968-SurveyQueue-FIX-20261005.csv`. If they differ, stop: the upload is
   built on FIX.
2. **Alert 17 first**: Alerts & Notifications → alert 17 → *Trigger the alert…* when **TSR** is saved,
   condition as above, still "Just once". It fires at the same moment it does today (the TSR submit),
   so doing it before the upload loses no notices. Records that already got it won't get it again:
   their TSR is never re-saved.
3. **Upload** `MICA-35968-SurveyQueue-HANDOFF-20261005.csv` (Survey Queue ▾ → *Upload Survey Queue
   Setup (CSV)*). REDCap lists the 104 entries it updated.
4. **Draft Mode → Close's four branching lines → Submit.**
5. **Module config:** `session-fallback-instrument` blank.
6. **Check:** walk the arm-1 public link once to TSR with a test record, and confirm that an arm-2/3
   allocation lands on the session login, and Standard Care on Close with the $20 text.

If the PI later moves to the hybrid (auto-continue everywhere except screening), these three Day-1
entries have to stay in the queue, and TSR must not auto-continue.

## Rollback (283)

`docker exec -i redcap_2023_1_db mysql -uredcap -predcap123 redcap < docs/phase-3-handoff/scripts/rollback-tsr-chat-handoff-283-20261005-175817.sql`.
It restores the 104 queue rows, alert 17 (trigger form, condition, deleted), Close's branching, the fallback setting, `randomization = 0`
and the empty allocation table. Records 2–13 are test records from these walks. On prod, rollback is
re-uploading the step-1 CSV and reverting the alert text.

## State left on 283

- Randomization **on**, with the 30-row stratified **TESTING-ONLY** allocation table (11 used: records 2–6 and 8–13).
  `randomization` was 0 when found.
- The queue, alert 17 (TSR trigger) and the branching as above. Alert 17 is deleted, as all 40 alerts were when found.
- Temporary design user `e2e_mica_config` removed.
