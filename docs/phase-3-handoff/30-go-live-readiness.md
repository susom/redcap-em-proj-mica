# 30 — Go-live readiness, prod PID 35968

**Assessed 2026-09-28** from the prod export `NEWTESTMICAR01_2026-09-28_1111.REDCap.xml` (118
records). Randomization was tested end to end on a replica of that export (**PID 278**). Module
settings and module-owned data are not in a REDCap export, so items marked *(settings)* have to be
checked on prod.

**Verdict: the build is there and randomization works, but the project is not ready to move to
Production.** What's left is mostly configuration plus two inputs from people: the statistician's
production allocation table, and leadership's critical-finding acknowledgement time. Several items
get much harder after the move (see the last column), so do them first.

**Added the same day (blocker 9): the safety scan had never succeeded.** Until the D23 fix reaches
prod, every session goes to the CRC as "could not be screened", about 7.5 minutes after it ends. The
PI's target is a critical finding reaching the CRC within 5 minutes. With the fix, that took 22–65 s
end to end on 271 ([31](31-critical-finding-crc-notify.md)).

## Blockers

| # | Item | Evidence | Fix | After the move? |
|---|---|---|---|---|
| 1 | **Minors, over-65s and people in prison can consent.** The consent condition checks AUDIT-C, phone and pregnancy only. | Unchanged since the 09-25 16:46 export, where a 17-year-old, a 70-year-old and a prisoner reached consent in a browser walk (PID 273) | **Restore `calc_screen_result`** ([equation](../screening/calc_screen_result.txt)), and point the consent condition and the eligibility messages at it. Adding age and prison to the consent condition alone would leave an ineligible 70-year-old with a blank eligibility page | Needs Draft Mode |
| 2 | **Stop actions don't stop the survey chain.** "Not English", "not interested" and "in prison" carry on into AUDIT-C. | `pre_screen` has no auto-continue condition (reproduced 09-25, PIDs 272 and 273) | `pre_screen` → Survey settings → auto-continue condition: `[english] = '1' AND [goodcand] = '1' AND [s_interest] = '1' AND [prison] = '0'` | Editable |
| 3 | **`randomization_date` is often blank.** It anchors the weekly SMS, the ED-session reminders and the follow-up dates. | 4 of the 8 randomized since 09-24 have none (95, 117, 120, 128) | *(settings)* MICA → "Stamp the randomization date into this field" = `randomization_date`, **and remove `@TODAY`** from the field. The module only stamps an empty field, so if staff open `admin` before randomization, `@TODAY` fills in an earlier date and the stamp never fires. The stamp has never been exercised: it was off on 271, where every log line reads `randomization_date_stamped=no`. So randomize one test record on prod and confirm the date | `@TODAY` removal needs Draft Mode |
| 4 | **All 22 alerts are deactivated**, including 01 (the phone-verification passcode used during enrolment), 15 (consent PDF) and the CRC notifications. | `email_deleted = 1` on all 22 | Re-activate the ones the protocol needs and re-test them **on fresh records**. The test records share phone numbers, so re-activating alerts now could text those phones. Check Twilio's scope is "Surveys and Alerts" | Editable |
| 5 | **No production allocation table.** | Tables don't travel between statuses | See Randomization, below | **Admin only** |
| 6 | *(settings)* **MICA launch gates.** In Production status, a session won't start unless all 7 pass. The acknowledgement time is blank on purpose, so leadership must set it. | `LaunchReadiness.php` | Review dashboard → **Launch readiness** tab: all green. **Reviewers (updated 2026-10-02):** the PI wants the CRC told of a critical finding within 5 minutes. That is the "findings ready" email. It goes to *Reviewer notification addresses* when that has any entry, and to the REDCap role mapped as MICA *Reviewer* only when it is blank. The role still decides who can open the findings, so every CRC must be in it (not custom rights), unsuspended, with the profile email that is on the list. Order: CRCs into the role, then the list, then this tab straight away, because on Production a red gate stops new sessions (item 3 below, [32](32-prod-runbook-crc-notify.md) step 3). **Acknowledgement:** nothing in the module records an acknowledgement, so N only sets when one reminder email goes out after each notice ([31](31-critical-finding-crc-notify.md)) | Editable |
| 7 | **Test data and ID reuse.** Deleting all records does **not** remove the modules' own per-record state. REDCap then numbers new records from max + 1, so **the first real participant is record 1 again**. | MICA keeps transcripts, turns and closed-session markers in the module log (`sessionWasClosedBefore()` looks up `mica_session_closed` by record), and scan jobs, runs, findings and notifications in `redcap_entity` tables. Enhanced SMS keeps conversations by record (its README warns a new record can inherit them) | Delete all records in the move dialog, **and** either purge the per-record state of both modules for PID 35968 (Enhanced SMS: Conversations → "Delete All Conversations"; MICA: a REDCap admin, since the module has no purge page), or keep real IDs clear of test IDs. Otherwise a real participant can be refused a session as "already closed" | — |
| 8 | *(deploy)* **Confirm prod runs the current MICA code.** | Branch `mica-phase-3` is 84 commits ahead of `main`, including the session fixes (white page, arm-1 redirect loop, closing sessions) | Compare the module version on prod with this branch, and deploy if it's behind. Prod's module shows v0.0.0 (2026-10-02). The *Reviewer notification addresses* setting (item 3 below) only exists on prod after this deploy | — |
| 9 | *(deploy)* **No session is ever safety-screened.** The provider refuses the scan's output schema (`uniqueItems`, HTTP 400), so every scan fails 3 times and the session goes to manual review, never as a finding ([14 D23](14-live-defects.md)) | Reproduced 09-28 as a participant on 271: an overdose plan for tonight produced only "could not be screened", **457 s** after End Session. With the fix: a critical finding, and the CRC's email at 22–65 s | Fixed in the working tree (not committed). Commit and deploy it with blocker 8, then run **one synthetic critical session on prod** and time the CRC's email. The preflight script is not evidence: it said PASS on 271 throughout | — |
| 10 | *(AI Hub)* **Azure's content filter refuses MICA's reply to some self-harm statements.** The participant is told "I apologize, but I'm experiencing network difficulties" | Prod's test session, 09-28 14:47:24: "Honestly I don't see the point of anything anymore." gave HTTP 400, 988 bytes. Reproduced locally on the development key: `content_filter`, `self_harm` rated medium, on `gpt-5-6-luna` and `gpt-5-6-sol`. Locally the scan is not blocked, and the CRC's email arrives in 65–71 s. On prod's test the scan failed and no email came, cause still open ([31](31-critical-finding-crc-notify.md), [32](32-prod-runbook-crc-notify.md) step 6) | The AI Hub team sets a content-filter configuration for MICA's deployments that doesn't block `self_harm` at medium. Give them `scripts/probe-content-filter.php` to show it before and after. Optionally, MICA could show study-approved crisis wording instead of "network difficulties" when a reply is refused (a code change and an IRB wording question) | — |

## Randomization

**Works now (development table).** 8 of the 9 participants who finished `tsr` since 09-24 were
randomized, with `rand_strata` set. The export has no log, so it can't show whether each one came
from the trigger. The 09-23 problems are fixed: `rand_strata` has moved to `tsr`, and the trigger is
option 2.

**Replica test, 2026-09-28 (PID 278)** (`e2e/rand-strata.js`, `seed-strata-test.php`): a stratified
test table was uploaded through REDCap's own Randomization page, then `tsr` was submitted as the
participant:

| Participant | `rand_strata` | Result |
|---|---|---|
| man, AUDIT 17 | 1 | "Randomize record (via trigger)", stratum-1 slot |
| woman, AUDIT 3 | **0** | "Randomize record (via trigger)", **stratum-0** slot (first time this stratum is shown to work) |
| one AUDIT answer missing | blank | **not randomized**. REDCap logs "failed due to missing stratification data", and the participant lands on "someone will be with you shortly". Every AUDIT item is required on the survey, so this only happens outside the survey chain |

Record 87 on prod is that last case: it has no `sip2r`, `bscq` or `drug_use` data.

**Still to do, all before the move:**
1. **Production allocation table from the statistician.**
   - Columns exactly `redcap_randomization_number, redcap_randomization_group, rand_strata`. That's
     REDCap's template for this setup; `number` may be blank.
   - Groups 1/2/3, with rows for **both** strata 0 and 1.
   - **Enough rows in each stratum for the full target.** The consent form says 750, and the split
     between strata is unknown. An empty stratum fails silently, and the participant sees "someone
     will be with you shortly".
   - Upload it **while still in Development**, using the *production* upload form. Afterwards only a
     REDCap admin can ("Because this project is already in Production status, no one is allowed to
     upload an allocation table (except REDCap admins)", `random_77`).
   - Use an account in the *Project Admins* role, which has the rights. A project built from the XML
     gives its creator none.
2. **Trigger logic.** Today it's `[day_1_ed_arm_1][tsr_complete]=2` alone, so anyone who reaches
   `tsr` is randomized. Use
   `[day_1_ed_arm_1][calc_screen_result]='1' AND [day_1_ed_arm_1][consent_complete]='2' AND [day_1_ed_arm_1][tsr_complete]='2'`,
   which adds eligibility (after blocker 1) and a completed consent, as recommended to the
   statistician on 09-15. Do it before the move to be safe: REDCap locks the randomization setup
   values in Production (`random_76`).
3. *(settings)* MICA → Configure:
   - **"Automatically add the record to its randomized arm"** (checkbox). This is **most likely already
     on**: every record randomized to 2 or 3 is in its arm with `study_group` at that arm's Day 1
     (e.g. 128 → Arm 2), which is what the setting writes. Confirm it's ticked, with "Allocation
     field" = `study_group`.
   - **"Stamp the randomization date into this field"** (a text box, not a dropdown): type
     `randomization_date`. This is **off**: record 128 has an ED-session link stored by the module,
     the moment the stamp would have fired, but no randomization date. Also remove `@TODAY`
     (blocker 3).
   - **Check after:** complete `tsr` on a prod test record. Expect `randomization_date` = today on
     `admin` and `first_sunday_1200` filled in, and for an Arm-3 record `calc_esms_valid` = 1.

## Safety review: settings to check on prod

Blocker 9 and [31](31-critical-finding-crc-notify.md) were measured on 271. These three decide
whether they hold on 35968, and none of them is in an export. The step-by-step, with every MICA
setting, is [32](32-prod-runbook-crc-notify.md).

1. **MICA → Configure → SafetyScan model alias:** exactly `gpt-5-6-sol`.
   - **Hyphens, not dots.** `gpt-5.6-sol` is the model's version name, and prod had it on 09-28. It
     fails every scan as "Unsupported model", behind MICA's generic error ([31](31-critical-finding-crc-notify.md)).
   - **A blank alias fails too:** MICA falls back to `gemini-2.5-flash`, which prod doesn't register.
   - **Also:** deploy the D24 and D25 fixes, and switch MICA off on any other project on the server
     that doesn't need it.
   - It should be a registered GPT-5.6 alias, as on 271 (`gpt-5-6-sol`).
   - D23 only bites where the schema is actually sent, so a GPT alias is what makes blocker 9 true on
     prod.
   - If it is blank, the code falls back to `gemini-2.5-flash`, and the models launch gate fails
     instead.
2. **"Close sessions when their window ends" (`close-expired-sessions`).**
   - Off on 271. Off means a session the participant never ends with End Session is **never** scanned.
   - On, it is scanned when the window (`ed-session-window-hours`, 24 h) runs out.
3. **Reviewer role members and Reviewer notification addresses (2026-10-02).**
   - **What changed:** the PI asked for the scan results to go to named people, not a group. When
     *Reviewer notification addresses* has any entry, the "findings ready", "could not be screened"
     and overdue-reminder emails go to those addresses **instead of** the Reviewer role. Blank keeps
     the role. Second-review requests still go to the role.
   - **The role still decides who can open the findings**, so everyone on the list who should confirm
     or dismiss them must be in it too.
   - **Prod today:** role 135783 `CRC (MICA reviewer)` is mapped as Reviewer, and its only member is
     ihabz, the developer. Project Admins (134858) is mapped as PI-lead.
   - **In this order:**
     1. Put each CRC in role 135783 `CRC (MICA reviewer)`, with their own account and email.
     2. Fill in *Reviewer notification addresses* with the CRCs' and Brian's addresses. Brian is in
        Project Admins, the PI-lead role, so he can already open findings. Don't map Project Admins as
        a Reviewer role to get him the emails: it has 8 users, and all of them would become reviewers.
     3. Open Launch readiness at once and confirm the *Reviewers configured* and *Recipient lists*
        gates pass. On Production a red gate stops new sessions.
   - **Removing a CRC** now means taking them out of the role **and** off the list.
   - The setting appears on prod only once a release containing it is deployed (blocker 8).

## Before the move, or it needs an admin

- **Take screening and baseline off Arms 2 and 3.** REDCap offers a public screening link per arm,
  and the Arm-2 link skipped eligibility and consent in two browser tests. Event designations are
  locked in Production. See [PI reply](../randomization/2026-09-25-pi-reply-screens-in-arm-1.md).
  - **Checked against the 09-28 17:34 export:** no data, logic, ASI or alert depends on these.
  - **Untick on Day 1 (ED) of Arm 2 and Arm 3:** `pre_screen`, `auditc`, `screen2`, `contact_info`,
    `baseline1`, `ddq`, `audit`, `sip2r`, `bscq`, `drug_use`, `tsr`, plus `sms_code_check` on Arm 2.
  - **Keep on those events:** `mica_ed_session`, `postsession`, `close`, `admin` and
    `mica_safety_finding`. Leave Months 3, 6 and 12, and Weeks 1–12, alone.
- **Rename Arm 1** "Screening + Standard Care (SC)". It's a display name, but arms are locked in
  Production.

## Should fix (not blockers)

- **Weekly-SMS guard.** Change `randomization_date >= '2026-09-22'` to the go-live date.
- **Cannabis message for never-drinkers.** A never-drinker who uses cannabis daily sees no cannabis
  message. `cann_elig` needs `OR [days_dr] = '0'` in its AUDIT-C-negative test, with brackets.

## Done since the last review (09-25 → 09-28)

- **SMS texts:** plain punctuation and ≤160 characters. `gset` is 2 segments, but it's the last text
  in its batch.
- **Weekly invitation:** anchored on `first_sunday_1200` + 5 h (Sunday 5 PM, same day if randomized
  on a Sunday), with "Ensure logic is still true" ticked.
- **Never-drinkers** now see "not eligible".
- **Randomization** works (above).

## Moving to Production (order)

1. **Before the move:**
   - blockers 1–4, 6, 8 and 9, and randomization items 2–3
   - take screening and baseline off Arms 2 and 3, and rename Arm 1
   - then re-run the screening walk and one full randomization on prod
2. Upload the statistician's production allocation table (Development status, production form).
3. Request the move (a Stanford REDCap admin approves it). **Delete all records** in the dialog, and
   deal with module state and ID reuse as in blocker 7.
4. Confirm the **Launch readiness** tab is all green, then switch on the weekly-SMS invitation.
5. Run one synthetic critical session on a test record, click End Session, and confirm that the
   CRC's "highest urgency: critical" email arrives within 5 minutes. Then delete the record.
   - Make it a realistic length, not two turns. Every local run was two turns, and a longer
     transcript is where a quote-verification miss would show up (see [31](31-critical-finding-crc-notify.md)).
