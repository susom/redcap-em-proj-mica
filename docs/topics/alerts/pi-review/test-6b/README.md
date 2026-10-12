# Test 6B on prod: one Sunday text to the PI, Sunday 2026-09-27 at noon

Prepared 2026-09-26 from the prod export `NEWTESTMICAR01_2026-09-26_1317.REDCap.xml` (114 records).
Rehearsed end to end on a local replica built from that export (PID 277), including the Data Import
Tool upload. **Nothing has been changed on prod.**

The PI's question was "Test 6B didn't really have any directions". The 6B page says the study team
prepares a record and switches the invitation on; that part was never written down as steps. These
are those steps.

## What the file does

[`record-89-sunday-2026-09-27.csv`](record-89-sunday-2026-09-27.csv) updates **record 89** at
Day 1 (ED) (Arm 1). It changes nothing else:

| Field | Now on prod | After import | Why |
|---|---|---|---|
| `first_name` | blank | `Brian` | the first text reads "Hi Brian, it's the MICA Team checking in." |
| `phonen` | blank | `(412) 901-6892` | the PI's number, from his Test 6A record 124 ("Brian test", suffbp@stanford.edu) |
| `dummy_email` | blank | `noreply@stanford.edu` | the `sunday` invitation is an email the texting module intercepts; REDCap needs an address to send it (`@DEFAULT` doesn't apply on import) |
| `randomization_date` | `2026-09-25` | `2026-09-23` | the first text goes 6 days after `first_monday_1200`, the Monday at 12:00 of the randomization week (Mon–Wed) or of the next week (Thu–Sun). 09-25 is a Friday, which gives Oct 4. Tue 09-22 or Wed 09-23 gives **Sun 09-27 12:00**, and both pass the invitation's `>= 2026-09-22` guard |

**Why record 89.** An import can't put anyone in Arm 3. The invitation requires
`[day_1_ed_arm_1][study_group] = '3'`, and that field at that event is the randomization target,
which REDCap refuses to import. Record 89 is the one record that is already properly randomized to
Arm 3: `study_group = 3` at Arm 1, placed in Arm 3, male (goal "4 or fewer"), `time_diff = 0`, and no
phone on it yet. The PI's own record 124 isn't randomized, and a Randomize click would draw from the
allocation table, so it couldn't be guaranteed Arm 3.

## Steps (study team), in this order

1. **Online Designer → `sunday` → Automated Invitations → Weeks 1–12 (Arm 3).** At the end of the
   logic, add a new line:
   ```
   and [record-name] = '89'
   ```
   Set **Active**, then **Save**. Do this *before* importing: the import's save is what schedules
   the invitation.
   **Send time:** as configured it's **Sunday 12:00 PM** (noon), not midnight. For another time T,
   change Step 4 ("Send the invitation … after First Monday at 12:00") to 6 days + (T − 12) hours,
   e.g. **6 days 5 hours** for 5:00 PM, or **5 days 21 hours** for 9:00 AM. Set it in this step,
   before importing. REDCap doesn't move an invitation that's already queued (verified on the
   replica), so a change after the import means deleting the queued row in the Survey Invitation
   Log and clicking Re-evaluate.
2. **Data Import Tool** → upload `record-89-sunday-2026-09-27.csv` with the defaults (CSV, rows,
   "No, blank values will be ignored") → review shows record 89 → **Import Data**.
3. **Survey Distribution Tools → Survey Invitation Log:** one row, record 89, `sunday`, Weeks 1–12,
   scheduled **09/27/2026 12:00**. If there's no row, use Online Designer → Auto Invitation options →
   **Re-evaluate Automated Survey Invitations**; the preview should list only record 89.
4. **Tell the PI:** the first text arrives Sunday at noon from the study number. He replies, for
   example, `0`, then `Yes`, then `Yes`. Pass means the messages arrive in order, his name and the
   goal number are filled in, and the answers show on record 89's `sunday` form at Weeks 1–12,
   week 1.
5. **At about 12:05 Sunday, confirm it went.** Nothing reports a failure on its own: the email goes
   to `noreply@stanford.edu`, and the module aborts silently. Check two places:
   - **Survey Invitation Log:** the row shows as sent.
   - **External Modules → Enhanced SMS Conversation → View Logs**, or the **ESMS Conversations**
     page: a new conversation for record 89.

   If the PI doesn't reply, he gets "We missed your response." after 60 minutes, and the
   conversation expires at 180.
6. **Cleanup (Sunday afternoon): all three, not just the first two.**
   - Set the invitation back to **Not Active**.
   - Remove the `[record-name]` line and **Save**.
   - **Tick `sms_stop` on record 89** (`admin`, Day 1 (ED) (Arm 1)).

   The third is required. Record 89's 12-week recurrence survives Not Active, and its date (09-23)
   still passes the go-live guard. So the day the invitation is switched on for real, REDCap would
   release every missed week to the PI's phone, one per minute. That's the 09-23 incident in the
   runbook: the recurring query sends while `first_send_time + times_sent × 7 days <= NOW` and the
   invitation is Active (`SurveyScheduler.php:2893-2910`). With `sms_stop` ticked, the module refuses
   to text the record, and the invitation's own logic is false for it.

## Checked on the replica (PID 277)

| Check | Result |
|---|---|
| Import through REDCap's Data Import Tool page (`e2e/data-import.js`) | review READY, **Import Successful!** |
| Date chain recomputed by the import | `weekday` 3, `calc_monday_diff` −2, `first_monday` 2026-09-21, `first_monday_1200` 2026-09-21 12:00 |
| `calc_esms_valid` | 0 → **1** |
| Invitation queued by the import itself | record 89, instance 1, **2026-09-27 12:00:00** (server time, America/Los_Angeles), recurrence starting at the same time |
| Address at send time | REDCap reads `dummy_email` from any event of the record (`SurveyScheduler.php:3182-3200`), so Arm 1's value is used |
| Who qualifies without the `[record-name]` line | **only record 89**, out of all 114 records |
| Alerts fired by the import | **none**: all 22 alerts in the prod export are deactivated (`email_deleted = 1`; REDCap only processes `email_deleted = 0`, `Alerts.php:170`). Several last sent on 09-22 and 09-25. If they're switched back on, the ED-session reminders (02–04) would qualify for record 89, which is another reason for `sms_stop` in step 6 |
| Where his replies are saved | the module takes the first record `getData` returns for his number: **89** (before 124) |

Afterwards, the replica's queued invitation was deleted and its invitation set back to Not Active,
so nothing sends locally.

## Things to know

- **His number will be on two records, 89 and 124.** The module binds each reply to the *first*
  record with that number and **saves the answer to that record**
  (`EnhancedSMSConversation.php`: `getRecordIdByCellNumber()`, then `handleReply()`), logging an
  error. 89 sorts first, so this test works. With a number shared across records the other way
  round, answers would land on the wrong record, not merely log an error.
- **"Ensure logic is still true before sending" is unticked on prod** (the runbook said to tick
  it). The runbook's reason, "sends one instead of twelve", is wrong: the 12-week recurrence is
  created either way (`SurveyScheduler.php:2834-2850`). What is lost is REDCap re-checking the logic
  before each weekly send. Withdrawal and STOP still stop the texts only because the module checks
  them itself. Decide this before launch; it doesn't affect this test.
- Record 89's `randomization_date` no longer matches the day it was actually randomized (09-25).
  It's a test record.

## Replica notes (for re-running this)

- `e2e/eligibility-calc.js create` builds the replica. A file **with records** takes several
  minutes, and the create step now waits for it and prints REDCap's error page if one comes back.
- This instance stores files in Google Cloud Storage, which refused writes on 2026-09-26 (403,
  "prohibited by organization's policy"), so the export's 20 logo attachments made project creation
  fail. Strip `<redcap:OdmAttachmentGroup>` and `<redcap:Attachment>` from the scratch copy. Also
  strip `<redcap:AlertsGroup>`, so a replica holding real phone numbers and emails can't send
  anything.
- **The XML import does not store calculated values.** Run Data Quality rule H
  (`Calculate::saveCalcFields`) before trusting the replica. After that, every calc this test uses
  matched the prod export.
