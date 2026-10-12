# Weekly SMS: 1-hour re-prompt per question, 24-hour timeout

**2026-09-30.** Reproduced and fixed on localhost (reference PID 279, `MICA v3`). **Prod (PID 35968)
still needs two things:** the fixed Enhanced SMS Conversation module deployed, and one setting
changed. See [§5](#5-prod).

## 1. The report

The PI tested one weekly check-in on prod. They wrote: *"there was no one hour re-prompt as there
should be for each question we ask before timing out at three hours."* The requirement is
*"Re-prompts should be 1 hour. Timeout message at 24 hours."*

What the phone showed:

| Phone time | Text |
|---|---|
| (earlier) | "Hi …, it's the MICA Team checking in." + the drinks question |
| 3:43 PM | the drinks question again, word for word |
| | *15* → feedback → "Are you planning on drinking alcohol this week?" → *Yes* → the goal question |
| 5:43 PM | "You must be busy. We will check in again later." |

## 2. Reproduction

[`simulate-esms-timing.php`](../phase-3-handoff/scripts/simulate-esms-timing.php) runs the module's
real code:

- the `@ESMS` send through REDCap's `Message::send()`;
- replies through `pages/inbound.php`;
- `cronScanConversationState()` once per simulated minute.

It swaps in a recording Twilio client and moves time by winding the conversation's stored
timestamps back. The file header explains the method.

Unfixed module, prod's settings (60 / 180), replies one minute after the first re-prompt:

```
 +0:00  MICA Hi Sim, it's the MICA Team checking in.
 +0:00  MICA Thinking back over the past 7 days, … Please reply with a number.
 +1:00  MICA Thinking back over the past 7 days, … Please reply with a number.     <- the 3:43 PM text
 +1:01  YOU  15
 +1:01  MICA This week's drinking was in the range considered unhealthy. …
 +1:01  MICA Are you planning on drinking alcohol this week?
 +1:02  YOU  Yes
 +1:02  MICA Would you be willing to commit to a goal of drinking 14 or fewer drinks …?
 +2:02  MICA (empty text - Twilio refuses it, error 21602; nothing arrives)        <- the missing re-prompt
 +3:00  MICA You must be busy. We will check in again later.                         <- the 5:43 PM text
Logging: "Reminder sent for dquant", "Reminder sent for gset", "Expired Conversation" - all with no record
```

That is the PI's screen minute for minute. 3:43 PM is +1:00 and 5:43 PM is +3:00, which puts the
first text at 2:43 PM.

## 3. Three causes

**A. A question with branching logic gets an empty re-prompt, and Twilio drops it.** This is the
bug the PI hit.

- The cron builds the re-prompt with `new FormManager(…, $CS->getRecordId(), …)`
  (`EnhancedSMSConversation.php:820`).
- `getRecordId()` always returns null. `redcap_email` saves the record in the log's `record` column
  (`"record" => $record_id`, `:143`). But `SimpleEmLogObject::MAIN_COLUMNS` loads a `record_id`
  *parameter*, which nothing ever writes. The debug log shows it: "Unable to identify requested
  value by key record_id".
- With no record, `gset`'s branching `[drink_fut] = '1'` evaluates false, so FormManager skips the
  question. The re-prompt body becomes `""`, and Twilio rejects an empty body with error 21602.
- The same null leaves the cron's "Reminder sent" and "Expired Conversation" log entries without a
  record. It would also blank any piping in a re-prompt.
- `dquant` and `drink_fut` have no branching logic, so their re-prompts did send.

**B. "We missed your response." is never sent.** The cron reads `default-reminder-text` (`:819`),
but the config dialog saves the text as `reminder-text-warning`. So the one re-prompt that did
arrive (3:43 PM) looked like a duplicate. Earlier docs said this text was live; it never was.

**C. The timeout counts from the first text, not from the last reply.** `setExpiryTs()` runs only
when the conversation opens (`:161`); a reply only re-arms the re-prompt (`:550`). With 180 minutes,
any question sent after the 2-hour mark could never get its 1-hour re-prompt: the timeout arrived
first.

## 4. The fix

**Module** (`enhanced_sms_conversation_v9.9.9`, against `a991791` = `origin/main`). **Reverted
locally on 2026-09-30 at the user's request, so the module is unchanged. The diff is kept here
only as a record.** The verification runs below used it.

```diff
 classes/SimpleEmLogObject.php
-    const MAIN_COLUMNS = ['log_id', 'timestamp', 'ui_id','ip','project_id','record_id', 'message'];
+    const MAIN_COLUMNS = ['log_id', 'timestamp', 'ui_id','ip','project_id','record', 'message'];
 classes/ConversationState.php  getRecordId()
-        return $this->getValue('record_id');
+        return $this->getValue('record');
 EnhancedSMSConversation.php:819
-    $this->getProjectSetting('default-reminder-text', $project_id);
+    $this->getProjectSetting('reminder-text-warning', $project_id);
```

**Setting:** Expire Conversations `180` → `1440`. Reminder Delay stays `60`. Applied to PID 279,
and `apply-esms-config.php` now writes it.

**Verified on PID 279** (fixed module, 60 / 1440). Transcripts are in the table below.

| Run | Replies at minute | Result |
|---|---|---|
| PI's timing | 61 = 15, 62 = Yes | re-prompts at +1:00 (`dquant`) and +2:02 (`gset`), each starting "We missed your response.", `gset` with its thresholds piped. Timeout at **+24:00**. |
| each question left over an hour | 90 = 15, 200 = Yes | one re-prompt per question: +1:01 `dquant`, +2:30 `drink_fut`, +4:20 `gset`. Still open past the old 3-hour mark. Timeout at +24:00. |
| completes | 61 = 15, 62 = Yes, 63 = Yes | `gg_1` feedback, state COMPLETE, nothing sent afterwards |
| fixed module, old 60 / 180 | 61 = 15, 62 = Yes | `gset` re-prompt now arrives at +2:02; timeout at +3:00. This isolates cause A from the setting. |

Every "Reminder sent" and "Expired Conversation" entry now carries the record.

**What the participant sees now.** Each question is re-prompted once, one hour after it was sent,
if there's no reply. The re-prompt is "We missed your response." + the question + the module's reply
hint (`[1] Yes, [0] No` for yes/no questions). The reply hint was always part of the module's
re-prompt format but was never seen, because those re-prompts never sent. A reply re-arms the
re-prompt for the next question. 24 hours after the first text, an unfinished check-in gets the
timeout text.

**Edge case:** the 24 hours run from the first text. A question answered in hour 23 gets no
re-prompt before the timeout. If the PI means "24 hours of silence", the module has to re-arm the
expiry on each reply: one `$CS->setExpiryTs()` next to `:550`. That is not done.

## 5. Prod

1. **Confirm prod has the same bug before asking for a deploy.**
   - In Logging for 35968 on the PI's test day, around 4:4x PM, look for a project-level entry
     "Error sending Twilio message from number …" with "Message body is required". That is the
     dropped `gset` re-prompt.
   - Cause A doesn't depend on any setting, so the entry should be there.
   - If it isn't, prod's module differs from `a991791`. Find out how before going on.
2. **Check prod's installed version: Control Center → External Modules → Enhanced SMS
   Conversation.** The patch is against `origin/main` `a991791`. Prod runs a released version
   directory, which may be older. Getting this onto prod takes a **new tagged release** of
   `susom/enhanced_sms_conversation`; a merge to `main` is not enough.
3. **Deploy that release.**
   - The module is shared: ASPIRE uses it too. After the deploy, ASPIRE's re-prompts start with
     their configured "Missing Text Reminder", which is what their setting asks for.
   - **No migration.** Conversations opened by the old code already have the record in the log's
     `record` column, so weeks in progress re-prompt correctly from the first cron tick after the
     deploy.
4. **PID 35968 → External Modules → Enhanced SMS Conversation → Configure → Expire Conversations =
   `1440`.** Leave Reminder Delay at `60`.
5. **Acceptance: the PI repeats their test on a handset.** The harness stubs Twilio, so only this
   proves delivery. Leave `dquant` unanswered past an hour, answer, then leave `gset` unanswered past
   an hour. Expected: two re-prompts starting "We missed your response.", then the timeout 24 h after
   the first text.
   - Rebuild and send the PI manual (`build-sms-manual.js`, reply-window row) only after steps 3–4.
     It already describes the new behaviour.

Step 4 alone fixes the timeout, but not the missing `gset` re-prompt or the missing "We missed your
response.". Those need step 3.

## 6. Unrelated, seen in the same screenshot

"commit to a goal of drinking␣␣or fewer drinks" has a double space where the weekly limit should
be. The label pipes `[day_1_ed_arm_1][calc_dquant_threshold]`, a calc that exists on 279 but not on
271, so it is likely new on prod too. The PI's record was probably saved before the calc existed and
never recalculated. On 279, a record saved after the calc existed pipes `14`.
