# Arm 3 weekly SMS: building it on prod (PID 35968), step by step

**Audience:** whoever builds it on the production server, with REDCap UI access only.
**Verified against:** the same steps on localhost PID 271, including the Data Dictionary upload
through REDCap's UI. See [ARM3_WEEKLY_SMS_REBUILD_271.md](../topics/alerts/ARM3_WEEKLY_SMS_REBUILD_271.md).
**State of prod on 2026-09-23** (from the XML export): same structure as 271 before the rebuild.
`sunday` is not repeating, has 61 dead week gates, there is no `calc_gset_threshold`, the survey
still says "TRAM", and there are **no ASIs at all**.

> The weekly SMS is **not an alert**. It is an Automated Survey Invitation on `sunday`. The subject
> line contains `@ESMS`, and that tag makes the **Enhanced SMS Conversation** external module send it
> as a text conversation instead of an email. It never appears under Alerts & Notifications.

Do the steps **in order**. Step 2 must come before step 7, because the "send every 7 days" option in
the ASI only exists for a repeating instrument.

---

## Step 0: decide these first (not UI steps)

| # | Decision | Why it matters |
|---|---|---|
| 0a | **Is Enhanced SMS Conversation installed on the prod server?** | A REDCap admin enables modules system-wide. Without it, the `@ESMS` invitation goes out as a plain **email** with a survey link. |
| 0b | **Which Twilio number, SID and token does the module use?** | The module has its own Twilio settings and never reads the project's. Localhost reused the project's number, +1 650-618-8235. |
| 0c | **May that number's inbound webhook point at this module?** | Replies only reach the module if the number's Messaging webhook is the module's URL (step 6). A Twilio number has one inbound URL, so anything else relying on that number's inbound traffic loses it. **A dedicated number is the clean choice.** |
| 0d | **Project status still Development?** | Yes on 2026-09-14. If it is now Production, step 3's upload goes into Draft Mode and needs admin approval, and step 2 needs Draft Mode too. |
| 0e | **Randomization fixed first?** | The ASI only fires for `[day_1_ed_arm_1][study_group] = '3'`, which the randomization module writes. Prod randomizes nobody today; see [../topics/randomization/PROD_35968_RANDOMIZATION_CONFIG.md](../topics/randomization/PROD_35968_RANDOMIZATION_CONFIG.md). Until that is fixed, this build is correct but sends to no one. |
| 0f | **Who fills `randomization_date`?** | The whole schedule hangs on it: `randomization_date → weekday → calc_monday_diff → first_monday → first_monday_1200` (the send anchor), and `calc_esms_valid` requires it too. With randomization automated, nobody types it. Either set MICA's module setting **"Stamp the randomization date into this field" = `randomization_date`**, or CRCs must enter it on `admin` for every arm-3 participant. **It is blank on 271**, and I couldn't see prod's value (EM settings aren't in the XML export). Left blank with nobody typing the date, arm-3 participants are randomized and **never texted**. |

## Step 1: download two rollback files

1. **Project Setup → Data Dictionary → "Download the current Data Dictionary"**. Save it as
   `prod-dd-BEFORE.csv` and **don't edit it**.
2. **Online Designer → `sunday` → Survey settings**: note the current title (blank) and instructions
   ("Hi again. It's the TRAM Team checking in.").

## Step 2: make `sunday` repeat at Weeks 1–12

1. **Project Setup → "Repeating instruments and events" → Modify**.
2. Find the row **"Weeks 1–12 (Arm 3: MICA + Weekly SMS)"** and set it to **"Repeat Instruments
   (repeat independently of each other)"**.
3. Tick **`sunday`**. Custom label: `Week [current-instance]`.
4. **Leave every existing `mica_safety_finding` row exactly as it is**. There are four, on Day 1 of
   arms 2 and 3 and on Month 3 of arms 2 and 3.
5. **Save**.

## Step 3: rewrite `sunday` with a Data Dictionary upload

The rewrite touches 63 branching-logic fields, so it goes through the dictionary rather than the
Online Designer.

> **Nothing runs on prod.** The patcher edits the downloaded CSV on any machine with PHP (a laptop,
> the local docker container), and prod only ever receives an ordinary Data Dictionary upload. No PHP
> at hand? Send the downloaded CSV to whoever has the repo and upload what comes back.
>
> **Don't use Instrument ZIP export/import instead.** Upload always *adds* an instrument. With
> `sunday` already present, REDCap renames the form to `sunday_<6 random chars>` and every clashing
> field to `<name>_<6 random chars>` (`Design/zip_instrument_upload.php:187-221`). That leaves a
> second, disconnected copy the ESMS module and the ASI know nothing about, not designated to Weeks
> 1–12 and not repeating. It also can't carry `calc_gset_threshold`, which lives on `auditc`.

1. On your laptop, from the repo:
   ```bash
   php docs/phase-3-handoff/scripts/patch-weekly-sms-data-dictionary.php prod-dd-BEFORE.csv prod-dd-WEEKLY-SMS.csv
   ```
2. The report **must** show all of the following. If it doesn't, stop.
   ```
   branching rewritten     63
   labels rewritten        3
   field added             1
   dead-event refs left    0
   expected fields missing 0
   rows changed elsewhere  0
   rows lost               0
   *** OK ***
   ```
3. **Project Setup → Data Dictionary → Upload** `prod-dd-WEEKLY-SMS.csv` **straight away**. The
   upload replaces the whole dictionary, so any Designer edit made after step 1's download would be
   undone.
4. REDCap's preview should say the file has **one more field than the project** (on 271: *"271
   fields, which will replace the 270 fields"*). The only warnings should be the "allowable"
   long-name and missing-label ones, which already exist. **Commit Changes**. Expect *"Changes Made
   Successfully!"*.
5. Keep the separate `rand_strata` move from the randomization fix **out of this upload**, unless you
   deliberately combine them. One change per upload keeps each one reviewable and reversible.

**✅ UPLOADED to prod 2026-09-23.** Patched from prod's 2026-09-23 download: `NEWTESTMICAR01_DataDictionary_2026-09-23.csv`
became `…-WEEKLY-SMS.csv`, with `*** OK ***`, 65 `sunday` fields changed (63 logic, 3 labels; `gset`
has both), and `calc_gset_threshold` added after `calc_binge_threshold`. Nothing else changed. Its
`sunday` rows and new field are **identical** to 271's verified build. Prod differs from 271 in 39
cells outside `sunday` (prod's own screening edits, e.g. `press_notelig` in place of
`eligibility`), and the patch preserves all of them. Re-download and re-patch if anyone edits prod's
design before the upload.

What changes: every week gate becomes `[current-instance] = N`, with one message per week and blank
guards. `[baseline_arm_1]` becomes `[day_1_ed_arm_1]` in labels. The `gset` question reads "…drink
[calc_gset_threshold] or fewer drinks…". `calc_gset_threshold` is added on `auditc`
(`if([s_sex]='1',4,if([s_sex]='2',3,''))`, `@HIDDEN-SURVEY`). Rationale for each rule:
[ARM3_WEEKLY_SMS_BUILD.md §4](../archive/ARM3_WEEKLY_SMS_BUILD_268.md).

## Step 4: store `calc_gset_threshold` on existing records

A new calc field is empty on every existing record until that record is saved again. Until then,
`gset` texts "drink  or fewer drinks".

**Data Quality → Rule H** ("Incorrect values for calculated fields") → **Execute** → **"Fix calcs
now"**. Then open any record that has `s_sex` and check that `calc_gset_threshold` on its Day 1 (ED)
(Arm 1) `auditc` reads 4 (male) or 3 (female).

## Step 5: survey title and branding

**Online Designer → `sunday` → Survey settings:**

- Survey title: `MICA Weekly Check-In`
- Survey instructions: `Hi again. It's the MICA Team checking in.`

**Save**.

## Step 6: enable and configure Enhanced SMS Conversation

> **The send time is not set here.** The module has no start-time setting. It texts the moment REDCap
> sends the invitation email (the `@ESMS` subject), so the time is the invitation's Step 4 (Step 7
> below), one setting for every participant. The module's only timing settings are Reminder Delay and
> Expire Conversations. *(Checked against its `config.json`, 2026-09-26.)* Reminder Delay counts from
> the last text for the current question, because each reply re-arms it. Expire Conversations counts
> from the first text. *(Checked against the code, 2026-09-30.)*

> **The module must be the fixed version (2026-09-30), or re-prompts are broken.** In the unfixed
> module, a question with branching logic (`gset`) gets an empty re-prompt, which Twilio drops, and
> "We missed your response." is never sent. See [ESMS_REPROMPT_TIMEOUT.md](../topics/alerts/ESMS_REPROMPT_TIMEOUT.md).

1. **External Modules → Manage → Enable a module → Enhanced SMS Conversation**. This needs 0a done.
2. **Configure** it with the values below. They are the settings `apply-esms-config.php` applied on
   271.

| Setting (as labelled in the dialog) | Value |
|---|---|
| Phone Field | `phonen` |
| Phone Field Event | **Day 1 (ED) (Arm 1: Standard Care (SC))**. Everyone is enrolled there; `contact_info` data lives nowhere else. |
| Twilio Number | per 0b, E.164, e.g. `+16506188235` |
| Twilio API SID / Token | per 0b |
| Reminder Delay | `60`. The PI's 1-hour re-prompt, once per unanswered question. |
| Missing Text Reminder | `We missed your response. ` |
| Expire Conversations | `1440`. The PI's 24-hour timeout, 2026-09-30; it was `180`. |
| Default Expiry Text | `You must be busy. We will check in again later.` |
| Default Nonsense Text Warning | `We don't understand.` |
| No Open Conversation Message | `Thanks! We will check in soon.` |
| SMS Opt Out field | `sms_stop` |
| SMS Opt Out field Event ID | **Day 1 (ED) (Arm 1: Standard Care (SC))** |
| Study Withdrawn Logic | `[day_1_ed_arm_1][study_withdrawn(1)]="1"` |
| Disable Outgoing Twilio SMS | unticked. It is never read by the code, so it cannot be used as a safety switch. |
| **Disable Incoming Twilio SMS** | **unticked on prod.** It is ticked on 271 only because localhost can't receive webhooks. Ticked, every reply is ignored and each week stalls after the first question. |
| Enable Debug Logging | optional |

3. **Twilio console → the number from 0b → Messaging → "A message comes in"**: Webhook, **HTTP
   POST**, URL:
   ```
   https://redcap.stanford.edu/api/?type=module&prefix=enhanced_sms_conversation&page=pages%2Finbound&pid=35968&NOAUTH
   ```
   Check first: an HTTP **GET** of that URL returns 200 with an empty body. The endpoint ignores
   anything that isn't a POST, so the check is harmless.

## Step 7: create the invitation (leave it Not Active)

**Online Designer → `sunday` row → "Automated Invitations" → choose "Weeks 1–12 (Arm 3: MICA +
Weekly SMS)"**. Fill it in to match `e2e/shots/weekly-sms-asi-dialog.png`:

| Section | Setting |
|---|---|
| Activate | **Not Active**, for now |
| Step 1: Invitation type | **Email invitation**, *not* SMS. The module intercepts the email because of `@ESMS`. |
| Step 2: From | `MICA Study` / a project user's address. On 271 the saved `micastudy@stanford.edu` shows as *"email no longer belongs to a project user"*, so pick one that does. |
| Step 2: Subject | `@ESMS MICA weekly check-in` (`@ESMS` is what routes it) |
| Step 2: Body | Copy-paste the two lines in **"Body text"** below the table. It is **never texted**: the module drops the email and texts the `sunday` questions instead. It is only seen if the module fails to intercept, in which case it explains itself and `[survey-link]` still lets the participant answer in a browser. |
| Step 3: Conditions | "When the following survey is completed": **unticked**. Leave the AND/OR box on **AND**. |
| Step 3: "When the following logic becomes true" | ticked, with the logic below |
| Step 3: "Ensure logic is still true before sending invitation?" | **ticked**. Without it REDCap doesn't re-check the logic before each weekly send, so withdrawal or opt-out mid-cadence won't cancel the remaining weeks in REDCap (the module still refuses to text withdrawn or opted-out records). *Corrected 2026-09-26: this used to say "without it REDCap sends one invitation instead of twelve". That's wrong; the recurrence is created either way (`SurveyScheduler.php:2834-2850`). Found **unticked** on prod in the 09-26 export.* |
| Step 4 | **"Send the invitation 6 days 0 hours 0 minutes after"** field **"First Monday at 12:00 …" (`[day_1_ed_arm_1][first_monday_1200]`)**. That is the Sunday noon after the first Monday following randomization: **12:00 PM, not midnight** (verified 2026-09-26: queued `2026-09-27 12:00:00`, weekly repeats at 12:00). **For a different Sunday time T**, keep the anchor and set the offset to 6 days + (T − 12) hours: 09:00 = 5 days 21 hours, 17:00 = 6 days 5 hours (verified on the replica: week 1 and the recurrence both at 17:00). Changing it does **not** move invitations already queued; delete those in the Survey Invitation Log and re-evaluate. A record with no `time_diff` gets a blank anchor and is never sent. |
| Step 4: "Re-evaluate the send-time…" | unticked |
| Step 5 | **Multiple times on a recurring basis**: send every **7 days**, up to **12** times total |
| Reminders | none. The module has its own reminders. |

### Option: first text on the *coming* Sunday (same day if randomized on a Sunday)

The Monday anchor above makes Thursday–Sunday randomizations wait 7–10 days, because
`calc_monday_diff` rolls Mon–Wed back to that week's Monday and Thu–Sun forward to next week's.
Offsetting from `first_monday_1200` can't fix this:
- "7 hours before" is Monday 5:00 AM, because the anchor is Monday **noon**.
- "19 hours before" sends Mon–Wed randomizations a text immediately, because their Monday is already
  past.
- REDCap's own "send on the next Sunday" option uses PHP `strtotime('NEXT SUNDAY')`
  (`SurveyScheduler.php:2563`), which on a Sunday means **next week**.

What works is one new field on `admin`, after `first_monday_1700`:

| Setting | Value |
|---|---|
| Variable | `first_sunday_1200` |
| Type / validation | Text Box, Datetime (Y-M-D H:M), same as `first_monday_1200` |
| Action tag / annotation | `@CALCDATE([randomization_date], (7-[weekday])*24+12+[time_diff], 'h')` |
| Invitation Step 4 | **0 days 5 hours 0 minutes after** `[day_1_ed_arm_1][first_sunday_1200]` → Sunday 5:00 PM |

The first `sunday` text then comes this many days after randomization, compared with the current
setup. Verified on the prod replica (PID 277) on 2026-09-27, week 1 and the recurrence both queued at
17:00:

| Randomized | Sun | Mon | Tue | Wed | Thu | Fri | Sat |
|---|---|---|---|---|---|---|---|
| Current (Monday anchor + 6 d 5 h) | 7 | 6 | 5 | 4 | 10 | 9 | 8 |
| `first_sunday_1200` + 5 h | **0** | 6 | 5 | 4 | **3** | **2** | **1** |

Consequences:
- Thu–Sun participants get their first "past 7 days" question 0–3 days after the ED visit, instead of
  7–10. That's a protocol call.
- Someone randomized on a Sunday **after** 5 PM is texted immediately, because that day's 5 PM has
  passed.
- Invitations already queued keep their old time. Move them with the pencil in the Survey Invitation
  Log.
- A record with no `time_diff` still gets a blank anchor and no invitation.

Body text (paste into the editor as two lines; type `[survey-link]` as plain text, since REDCap
turns it into the link):

```
This invitation is delivered as an SMS conversation by the Enhanced SMS Conversation module; the subject tag @ESMS is what routes it. If you are reading this as an email, the module did not pick it up.
[survey-link]
```

Logic:

```
([day_1_ed_arm_1][calc_esms_valid] = '1') and
('1' <> [day_1_ed_arm_1][study_withdrawn(1)]) and
('1' <> [day_1_ed_arm_1][sms_stop(1)]) and
([day_1_ed_arm_1][study_group] = '3')
```

**Save**.

## Testing on prod

**The blank page at the inbound URL is the correct result.** `pages/inbound.php` ignores anything
that isn't a POST. Checked 2026-09-23: the real URL returns **HTTP 200, 0 bytes**. The same URL with a
made-up module prefix returns 200 with a 76-byte error, so the empty body proves REDCap found and ran
the module's page.

**`pages/superuser_test` is not a test of this build.** It is a developer scratch page. It prints the
module's inbound URLs (useful for copying into Twilio), shows Record/Event dropdowns whose "Set
Context" button does nothing, then evaluates hard-coded logic from another study (`[step_prompt]`,
form `daily`, record 2) and stops. It reads nothing from MICA and sends nothing.

### Test A: round trip, with no participant involved (after step 6)

**✅ PASSED on prod 2026-09-23**, after fixing error 11210: the webhook still pointed at
`redcap.local`. PI summary email: [pi-review/2026-09-23-weekly-sms-email.md](../correspondence/2026-09-23-pi-weekly-sms.md).

The module answers a text that arrives when no conversation is open, and you can use that to test
the whole loop: phone → Twilio → webhook → module → Twilio → phone.

1. Pick a phone number that is on **exactly one** prod record, in `phonen` at Day 1 (ED) (Arm 1).
   Your `(330) 622-2938` is on 11 records; the module would bind to the first one and log an error.
2. From that phone, text **`hello`** to the module's Twilio number.
3. Expected reply within seconds: **"Thanks! We will check in soon."** (No Open Conversation
   Message).

If the reply arrives, the webhook, module config, credentials and outbound sending all work.

**Silence has several causes, so don't guess.** Note that the module's "offline" and "something went
wrong" texts are returned to Twilio as `<Response>text</Response>`, without a `<Message>` verb, so
**Twilio never delivers them**. A ticked "Disable Incoming" box and an internal error both look like
silence. Look in these two places:

**1. Twilio console → Monitor → Logs → Messaging**, filtered to the module's number:

| Twilio shows | Meaning / fix |
|---|---|
| No inbound "hello" at all | Texted a different number than the one in the module's settings |
| Inbound, with error **11210 "HTTP bad host name"** | The webhook's hostname doesn't resolve publicly. **Seen on prod 2026-09-23**: the number still carried the localhost webhook `http://redcap.local/surveys/` (restored there by the 09-21 teardown, build doc §5). Set "A message comes in" to the prod URL above, HTTP POST. If the number belongs to a Messaging Service, set it on the service (Integration → Send a webhook), which overrides the number. |
| Inbound, with webhook error **11200** in Monitor → Errors | Twilio reached the host but the request failed: wrong path/parameters, or not **HTTP POST** |
| Inbound, with error **12100 / 12200** (document parse) | REDCap answered with bare text: "Disable Incoming Twilio SMS" is **ticked**, or the module threw an error |
| Inbound, no error, no outbound | The module ran but didn't reply. Your number matched no record (next table), or "No Open Conversation Message" is empty |
| Outbound reply **failed/undelivered**, code **30034** | The number isn't registered for US A2P 10DLC, so carriers block it. Twilio/admin fix, not REDCap. |
| Outbound reply failed, code **21610** | Your phone once texted STOP to this Twilio number. Text `START` to it, then retry. |
| Inbound goes to a URL that isn't `…prefix=enhanced_sms_conversation…pid=35968…` | The number's webhook still points elsewhere, e.g. REDCap's `/surveys/` |

**2. REDCap → External Modules → Manage → Enhanced SMS Conversation → View Logs**:

| REDCap shows | Meaning |
|---|---|
| A `MessageHistory` entry with `from_number` = your phone, `body` = hello | The text reached the module with incoming enabled. A second entry *to* your phone means the reply went to Twilio. |
| Nothing | The webhook isn't reaching the module, or "Disable Incoming" is ticked (it returns before logging) |

**Number matching:** the module looks for `phonen` **exactly** equal to `(xxx) xxx-xxxx` at the
**Phone Field Event** (Day 1 (ED) (Arm 1)) via `getData` filter logic. Any other format, or the
number stored at a different event, means no match and silence. A number on several records still
matches the first one and still gets a reply.

**Don't text `STOP` or `OPTOUT`**: the module ticks `sms_stop` on that record. `START` reverses it.

### Test B: one real weekly conversation, on one test record (after step 7)

This uses the real invitation, restricted to a single record so no participant can be texted.

1. **A test record that qualifies:** in arm 3 by randomization (`study_group = 3` at Day 1 (ED) (Arm
   1)), `phonen` = the tester's phone and on no other record, `dummy_email`, `s_sex`,
   `randomization_date`, and `calc_esms_valid = 1` on its `admin` form. `study_group` at that event is
   the randomization target and can't be typed **or imported**, so this needs randomization working
   (0e), or a user with Randomize rights clicking **Randomize** on the record.
   **Worked example, 2026-09-26:** [pi-review/test-6b/](../topics/alerts/pi-review/test-6b/README.md). That update to
   an already-randomized record, done with one import file, was rehearsed on a replica of prod.
   To pick the first Sunday: week 1 is 6 days after `first_monday_1200`, which is the Monday at 12:00
   of the randomization week if randomized Mon–Wed, or of the following week if Thu–Sun. So for a
   text on Sunday *S*, `randomization_date` is the Tuesday or Wednesday six days before *S*.
2. **Restrict the invitation.** Append `and [record-name] = '<test record id>'` to the ASI logic.
   Set **Active** and Save.
3. **Online Designer → "Auto Invitation options" → "Re-evaluate Automated Survey Invitations"**. The
   preview should list **only** the test record.
4. If its `first_monday_1200` is more than 6 days in the past, week 1 texts within a minute.
   Otherwise it waits until that Sunday at noon. **Survey Distribution Tools → Survey Invitation Log**
   shows the scheduled time.
5. Reply as a participant. Answer plan from the 271 test: `0`, `Yes`, `Yes` gives the alcohol-free
   message, "commit to a goal to drink 4 or fewer", then the goal-set message.
6. **Check where the answers landed:** the test record's `sunday` at Weeks 1–12, instance 1. Also
   check the module's **ESMS Conversations** page (project sidebar).
7. **Clean up:** set the ASI **Not Active**, remove the `[record-name]` clause, and Save, **and tick
   `sms_stop` on the test record**. The record's recurrence row survives Not Active. If its
   `randomization_date` passes the go-live guard, switching the ASI on for real releases every
   missed week to the tester's phone, one per minute (the 09-23 incident below). *Added
   2026-09-26.*

## Step 8: verify before activating

1. **The survey renders.** Open `sunday` for an arm-3 record at "Weeks 1–12" (Record Home → the
   `sunday` bubble → "Open survey"). Expected: title *MICA Weekly Check-In*, "Hi <first name>, it's
   the MICA Team checking in.", the drinks question, and **no "SURVEY ERRORS EXIST" page**. Check it
   on a phone too.
2. **The logic.** In the ASI dialog, use "Test logic with a record" on an arm-3 participant who has a
   phone number, a `randomization_date` and `calc_esms_valid = 1`. It should read True. It should
   read False for an arm-1 or arm-2 participant.
3. **No duplicate phone numbers among real participants.** The module binds a reply to the *first*
   record with that number, logs an error (`EnhancedSMSConversation.php:730-733`), and then **saves the
   answer to that first record** (`handleReply()` uses the looked-up record, not the conversation's).
   A shared number sends a participant's answers to someone else's record. The
   2026-09-23 export has heavy sharing among test records: `(412) 901-6892` on 17 records,
   `(330) 622-2938` on 11, and two more pairs. Clean these up or delete the test records before
   activating.

## Step 9: activate (outbound-facing, so do this deliberately)

Set the ASI to **Active** and Save. What happens next:

- **Activating does not, by itself, schedule anyone who already qualifies.** REDCap evaluates an
  ASI's logic when a record is saved. The only cron that re-checks logic on its own handles
  `datediff(…'today'…)` conditions (`AutomatedSurveyInvitationsDatediffChecker*`), and this logic
  has none. For existing arm-3 participants, use **Online Designer → "Auto Invitation options" →
  "Re-evaluate Automated Survey Invitations"** (it previews who would be scheduled before
  committing).
- **New participants are scheduled by the save that makes them eligible.** That is the stamp of
  `randomization_date` (0f). Traced in REDCap's code, not observed live: the stamp's
  `REDCap::saveData()` runs the ASI check *before* auto-calcs (`Records.php:7471` vs `:7506`), so
  that check sees no `first_monday_1200` yet. The auto-calc then saves through `saveData()` again
  without `bypassAlertAsiTrigger` (`Calculate.php:589`), which re-runs the check with the anchor in
  place. Confirmed on 271 for the computing part: a server-side stamp does compute the chain through
  `first_monday_1200`, **provided `time_diff` exists**, and every participant gets `time_diff` from
  `pre_screen`. **Check the first real arm-3 participant's Survey Invitation Log** instead of assuming.
- Each scheduled record gets week 1 at `first_monday_1200 + 6 days`, then every 7 days, up to 12.
- 🔴 **INCIDENT 2026-09-23: old anchors cause a catch-up burst, one "week" per minute, on any
  weekday.** REDCap seeds the recurrence with `first_send_time` = the *computed* send time, even if
  it is in the past (`SurveyScheduler.php:2846`). The mailer then sends the next instance whenever
  `first_send_time + times_sent × 7 days <= NOW` (`emailInvitations`, recurrence query). For a record
  whose anchor is weeks old, that is true again every minute until it catches up: week 1, 2, 3… in
  consecutive minutes. Shared phone numbers multiply it. The 271 test never showed this, because
  weeks were released by hand with the mailer cron parked.
- **Not Active does not stop invitations already queued.** The send query checks `q.status =
  'QUEUED'` and the send time, not `a.active` (`emailInvitations`). Only the recurrence expansion
  checks `a.active = 1`. Queued rows must be deleted in the Survey Invitation Log.
- **Every text in the burst opened a conversation, and each one later sends its own follow-ups:**
  a re-prompt at 60 min, then "You must be busy. We will check in again later." at the Expire
  Conversations time. That was 180 min then and is 24 h from 2026-09-30, so a burst now stays open a
  full day. These come from the module cron `esms_scan_conversation_state`,
  which only runs for projects where the module is enabled (`getProjectsWithModuleEnabled()`).
  **Clean-up: project sidebar → ESMS Conversations → "Delete All Conversations"**, with the module
  still enabled. Disabling the module only pauses them: the conversations stay ACTIVE, and the
  expiry texts fire within a minute of re-enabling.
- **Guard before re-activating:** add `and [day_1_ed_arm_1][randomization_date] >= 'YYYY-MM-DD'`
  (the go-live date) to the logic, so only participants randomized from go-live onward ever qualify.
  It stays true for all 12 weeks of a new participant, and with "Ensure logic is still true" it
  suppresses any leftover old recurrences at send time.
- As of the 2026-09-23 export, **no prod record passes the logic** (no `study_group = 3` or
  `calc_esms_valid = 1` at the enrolment event), so activating today sends nothing until someone is
  randomized to arm 3 and has `randomization_date` set.

To stop everything immediately: set the ASI back to **Not Active**. To stop one participant: tick
`sms_stop` or `study_withdrawn`. "Ensure logic is still true" cancels their remaining weeks.

## Rollback

| Undo | How |
|---|---|
| Invitation | ASI → Not Active (or delete it in the dialog) |
| `sunday` rewrite + new field | Data Dictionary → upload `prod-dd-BEFORE.csv` |
| Repeating | Project Setup → Repeating instruments → untick `sunday` at Weeks 1–12. Do it before any weekly data exists: once instances 2+ hold data, un-repeating strands them. |
| Module | External Modules → Disable. Restore the Twilio webhook to its previous value. |
| Title/branding | Survey settings, values noted in step 1 |

## Known gaps (decide before launch, not blockers for the build)

- **`sunday_complete` never becomes Complete.** The module tracks completion in its own log objects
  only, so reports filtering on `[sunday_complete] = '2'` count zero weeks.
- **Shared phone numbers mis-route replies** (step 8.3). It also affects `sms_stop` texted from a
  shared number.
- **`thursday`** (ASPIRE's second weekly message) is not built. Sunday only.
- **Messages can arrive out of order** (seen 2026-09-28). The module sends each reply's feedback and
  next question as separate texts, 300 ms apart and in the right order (reproduced). **Confirmed in
  Twilio's log:** a 3-segment feedback text was overtaken by the 1-segment question sent a second
  later. Keeping every `sunday` text to one segment removes the cause: six texts need `’`/`—` → plain,
  six need shortening to 160 characters or fewer. Only sending each batch as **one** text guarantees
  the order, and that's a change to the shared ESMS module. See
  [SMS_MESSAGE_ORDER.md](../topics/alerts/SMS_MESSAGE_ORDER.md).
- **The 24-hour timeout counts from the first text, not the last reply.** A question answered in
  hour 23 gets no re-prompt before the timeout. Re-arming the expiry on each reply is a one-line
  module change, not made. See [ESMS_REPROMPT_TIMEOUT.md](../topics/alerts/ESMS_REPROMPT_TIMEOUT.md) §4.
