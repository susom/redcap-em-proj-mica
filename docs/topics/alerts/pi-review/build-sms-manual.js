// Build the PI-facing Arm 3 SMS test manual.
//
//   cd <module root> && NODE_PATH="$PWD/node_modules" node docs/topics/alerts/pi-review/build-sms-manual.js
//
// REWRITTEN THREE TIMES, 2026-09-21. Recording the errors because each was a different class.
//
// v1 -> v2  readability. The original ran to seven pages and explained why after every step, so the
//           instructions were buried in rationale.
// v2 -> v3  correctness. v1 and v2 told the PI to open `consent` and `contact_info` at ARM 3's
//           Day 1. `contact_info` was designated to Arm 1's Day 1 only, so those steps could not
//           work. Chasing that found 13 alerts unable to fire for the same reason.
// v3 -> v4  two corrections from the study team:
//           (a) `contact_info` has since been designated to every arm's Day-1 event. Re-running the
//               structural audit now returns 22 live / 0 dead, so the reminder tests are viable
//               again - and they belong in ARM 3, where the contact fields now exist. The v3
//               "what is broken" page is removed.
//           (b) v3 dropped the `calcrnd` check. The passcode alert requires `[calcrnd]<>''` and
//               that is the clause that actually blocks it: verified on a scratch record with
//               consent + contact_info complete and a phone number, where the full condition still
//               evaluated FALSE because the calc had not populated. It is back as an explicit step.
//
// v4 -> v5  2026-09-21, after the event-prefix fix was applied and tested end to end on PID 271
//           (../../../testing/PID271_ALERT_TEST_MATRIX.md). Two changes, both of which invalidate v4 steps:
//
//           (a) ENROLMENT DATA NOW GOES AT ARM 1's DAY 1, NOT ARM 3's. v4's premise - "the reminder
//               tests belong in Arm 3, where the contact fields now exist" - was the circular one:
//               designating `contact_info` at every arm's Day 1 made the fields *exist* there, but
//               nothing in the study ever *writes* there. MICA enrols everyone at Arm 1's Day 1 and
//               materialises only `study_group` into the assigned arm. All 13 reminder alerts now
//               read `[day_1_ed_arm_1][...]`, so a tester following v4 enters the data where the
//               alerts no longer look and concludes, again, that they are broken.
//               The record still has to BE in Arm 3 - that is what selects Arm 3's alert - but its
//               phone number, date and channel tick live at Arm 1's Day 1.
//
//           (b) THE SAVE HAS TO LAND ON THE ALERT'S OWN FIRING EVENT. Measured: changing
//               `randomization_date` at Arm 1's Day 1 queues nothing, twice over (browser and API);
//               saving at the firing event queues immediately. v4 had this for Test 5 only. It is
//               now explicit in every reminder test.
//
//           Also from the study team: the passcode alert needs the SURVEY COMPLETED, not just the
//           two forms marked Complete - see Test 1.
//
// Verified on PID 271, 2026-09-21, against the post-fix alert definitions, by real sends to a real
// handset where noted:
//   alert 01  gates on consent_complete + contact_info_complete + phonen + calcrnd, all at Day 1
//   alert 03  true at day_1_ed_arm_3 with the enrolment values at day_1_ed_arm_1
//   alert 11  true at month_3_arm_3, SMS DELIVERED to a real handset (record 37)
//   alert 13  true at month_3_arm_3, SMS DELIVERED to a real handset (record 37)
//
// Fixed since v4, so no longer a caveat: alerts 09 and 14 piped [phonen]/[email] unprefixed at
// Month 3 and gave the coordinator blanks. Both now read [day_1_ed_arm_1][...] and were confirmed
// by live email - "Contact: (408) 205-2230 / ..." - in the same run.
//
// v5 -> v6  2026-09-23. Only the weekly-series page changed; tests 1-5 are untouched.
//           v5 said the weekly series was "not on your project yet". It is now configured on the
//           production project (../../../runbooks/arm3-weekly-sms-prod.md): `sunday` rewritten by Data
//           Dictionary upload, Enhanced SMS Conversation enabled, Twilio webhook pointed at the
//           module, and a round-trip text answered on 2026-09-23. The invitation is built but
//           switched OFF, so the page now has two tests:
//             6A  text "hello" -> "Thanks! We will check in soon."  (the PI can run it alone)
//             6B  one real weekly conversation, which needs the study team to prepare a test record
//                 randomized to Arm 3 and switch the invitation on for that record only
//           Both are the procedures in the runbook's "Testing on prod" section.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const HERE = __dirname;
const DATE = '23 September 2026';

const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><style>
  @page { size: Letter; }
  * { box-sizing: border-box; }
  body { font: 10.5pt/1.5 "Helvetica Neue",Arial,sans-serif; color: #1c1c1e; margin: 0; }
  h1 { font: 700 21pt/1.2 "Helvetica Neue",Arial,sans-serif; margin: 0 0 3pt; letter-spacing: -.4pt; }
  .sub { font-size: 10.5pt; color: #5b5b60; margin: 0 0 12pt; }
  .rule { height: 3px; background: #8c1515; margin: 0 0 14pt; }
  h2 { font: 700 12pt/1.3 "Helvetica Neue",Arial,sans-serif; margin: 18pt 0 8pt;
       padding-bottom: 4pt; border-bottom: 1.5px solid #8c1515; color: #8c1515;
       page-break-after: avoid; }
  p { margin: 0 0 7pt; }
  code { font: 9pt "SF Mono",Menlo,monospace; background: #f2f2f4; padding: .5pt 3pt; border-radius: 2pt; }
  kbd { font: 700 9pt "Helvetica Neue",Arial,sans-serif; background: #f2f2f4; border: 1px solid #dcdce0;
        border-radius: 2pt; padding: .5pt 4pt; white-space: nowrap; }

  .rules { border: 1.5px solid #8c1515; border-radius: 3pt; padding: 11pt 14pt 4pt; margin: 0 0 13pt; }
  .rules h3 { font: 700 10.5pt "Helvetica Neue",Arial,sans-serif; margin: 0 0 7pt; color: #8c1515; }
  .rules ol { margin: 0 0 7pt; padding-left: 16pt; }
  .rules li { margin-bottom: 6pt; }
  .rules li b { color: #8c1515; }

  table { border-collapse: collapse; width: 100%; margin: 0 0 10pt; font-size: 9.5pt; }
  th, td { text-align: left; padding: 4.5pt 7pt; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
  /* A row split across a page break reads as two half-rows under a repeated header. */
  tr { page-break-inside: avoid; }
  th { font: 700 8.5pt "Helvetica Neue",Arial,sans-serif; text-transform: uppercase;
       letter-spacing: .4pt; color: #5b5b60; border-bottom: 1.5px solid #c9c9cf; }
  th code { text-transform: none; letter-spacing: 0; }
  td.f { font: 9pt "SF Mono",Menlo,monospace; white-space: nowrap; }

  .test { border: 1px solid #d4d4d8; border-radius: 3pt; margin: 0 0 11pt; page-break-inside: avoid; }
  /* flex, not float: a floated time label drops below a heading that wraps to two lines. */
  .test > h3 { font: 700 11pt "Helvetica Neue",Arial,sans-serif; margin: 0;
               background: #f7f7f8; border-bottom: 1px solid #d4d4d8; padding: 7pt 13pt;
               border-radius: 2pt 2pt 0 0;
               display: flex; justify-content: space-between; align-items: baseline; gap: 12pt; }
  .test > h3 span { flex: none; font-weight: 400; font-size: 9pt; color: #5b5b60; white-space: nowrap; }
  .body { padding: 10pt 13pt 4pt; }
  dl.spec { margin: 0 0 8pt; font-size: 9.5pt; }
  dl.spec dt { float: left; clear: left; width: 78pt; font-weight: 700; color: #5b5b60;
               font-size: 8.5pt; text-transform: uppercase; letter-spacing: .3pt; padding-top: 1.5pt; }
  dl.spec dd { margin: 0 0 4pt 78pt; }
  ol.steps { margin: 0 0 8pt; padding-left: 17pt; }
  ol.steps > li { margin-bottom: 5pt; }
  ol.steps > li::marker { font-weight: 700; color: #8c1515; }
  .pass { background: #f4faf5; border-left: 3px solid #1d6b32; padding: 7pt 11pt;
          margin: 8pt 0 4pt; font-size: 9.5pt; }
  .pass b { color: #1d6b32; }
  .warn { background: #fff8f0; border-left: 3px solid #b06a00; padding: 7pt 11pt;
          margin: 8pt 0 4pt; font-size: 9.5pt; }
  .warn b { color: #8a4b00; }
  .note { background: #f4f7fb; border-left: 3px solid #2c5f9e; padding: 8pt 11pt;
          margin: 8pt 0 10pt; font-size: 9.5pt; }
  .note b { color: #21497a; }
  .stop { background: #fdf3f3; border-left: 3px solid #8c1515; padding: 8pt 11pt;
          margin: 8pt 0 10pt; font-size: 9.5pt; }
  .stop b { color: #8c1515; }
  .nb { font-size: 9pt; color: #5b5b60; margin: 0 0 7pt; }
  .page { page-break-before: always; }
  .script { font-size: 9.5pt; margin: 0 0 9pt; }
  .script div { padding: 2.5pt 0; border-bottom: 1px solid #efeff1; }
  .script .you { padding-left: 74pt; color: #21497a; font-weight: 700; }
  .script .lbl { display: inline-block; width: 70pt; font: 700 8.5pt "Helvetica Neue",Arial,sans-serif;
                 color: #8c1515; text-transform: uppercase; letter-spacing: .3pt; vertical-align: top; }
  .script .txt { display: inline-block; width: calc(100% - 74pt); vertical-align: top; }
</style></head><body>

<h1>MICA Arm 3 — testing the text messages</h1>
<p class="sub">Five alert tests + the weekly series · about 35 minutes · updated ${DATE}</p>
<div class="rule"></div>

<div class="rules">
  <h3>Five rules. Everything else is detail.</h3>
  <p class="nb" style="margin:0 0 6pt;">Rules 1 and 2 changed on 21 September. They are the two that
  fail <i>silently</i> — you get no text, and nothing anywhere tells you why.</p>
  <ol>
    <li><b>The phone number, the date and the SMS tick always go at <i>Arm 1&rsquo;s</i> Day 1 (ED)</b>
      — not at Arm 3&rsquo;s, even for the Arm 3 tests. Every participant is enrolled at Arm
      1&rsquo;s Day 1 and only then placed into their arm, so that is the one event the reminders
      read. The record must still <i>be</i> in Arm 3 — that is what selects Arm 3&rsquo;s version —
      but its details live at Arm 1&rsquo;s Day 1.</li>
    <li><b>Then save something at the event the reminder itself belongs to.</b> Entering the date
      alone is not enough: REDCap only looks for due reminders at an event when something is saved
      <i>there</i>. Each test says which form, and where.</li>
    <li><b>One alert switched on at a time</b> — otherwise you cannot tell which one sent the text.</li>
    <li><b>Type any backdated date on your first save of the form.</b> REDCap fixes the send time
      then and never recalculates it. Get it wrong and you must start a new record.</li>
    <li><b>Never reply STOP to a test text</b> — it blocks your handset for the study permanently.
      Test 3 opts out safely, with a tickbox.</li>
  </ol>
  <p class="nb" style="margin:0 0 7pt;">The texting account is live and every text really sends. Use
  your own mobile number, never a participant's.</p>
</div>

<h2>Before you start — one-time checks</h2>
<p>Ask the study team to confirm these. You cannot fix them yourself, and if any is wrong, no text
will send.</p>
<table>
  <thead><tr><th style="width:34%">Check</th><th>Must be</th></tr></thead>
  <tbody>
    <tr><td>Alerts are in this project</td><td><kbd>Alerts &amp; Notifications</kbd> lists alerts numbered from <b>01 Phone check</b></td></tr>
    <tr><td>Texting scope</td><td><b>“Surveys and Alerts”</b> — if it says “Surveys only”, every alert here is silently undeliverable. <span class="nb">Does not affect the weekly series (tests 6A and 6B), which uses its own texting module.</span></td></tr>
    <tr><td><code>phonen</code> field validation</td><td><b>Phone</b> — without it REDCap will not use the field as a text recipient</td></tr>
  </tbody>
</table>

<h2>The five tests at a glance</h2>
<p><b>One fresh record per test.</b> Note the arm — they differ. Two of the five pass by <i>nothing</i> arriving.</p>
<table>
  <thead><tr><th style="width:6%">Test</th><th style="width:25%">What it proves</th><th style="width:13%">Record's arm</th><th style="width:19%">Date to enter<br><span class="nb">at Arm 1's Day 1</span></th><th style="width:19%">Then save here</th><th>Expected</th></tr></thead>
  <tbody>
    <tr><td><b>1</b></td><td>Passcode confirms the phone number</td><td><b>Arm 1</b></td><td>none needed</td><td>Arm 1, Day 1</td><td>Text, immediately</td></tr>
    <tr><td><b>2</b></td><td>Session reminder after one week</td><td><b>Arm 3</b></td><td><b>today minus 7 days</b></td><td><b>Arm 3</b>, Day 1</td><td>Text, ~90 seconds</td></tr>
    <tr><td><b>3</b></td><td>Opting out stops the texts</td><td><b>Arm 3</b></td><td><b>today minus 7 days</b></td><td><b>Arm 3</b>, Day 1</td><td><b>No text</b></td></tr>
    <tr><td><b>4</b></td><td>Finishing cancels the reminder</td><td><b>Arm 3</b></td><td><b>today minus 7 days</b></td><td><b>Arm 3</b>, Day 1</td><td><b>No text</b></td></tr>
    <tr><td><b>5</b></td><td>Booster reminder, Arm 3's version</td><td><b>Arm 3</b></td><td><b>today minus 92 days</b></td><td><b>Arm 3</b>, Month 3</td><td>Text, ~90 seconds</td></tr>
  </tbody>
</table>
<h2>Test 1 &nbsp;Passcode text</h2>
<div class="test">
  <h3>Confirms the phone number the participant gave us is really theirs<span>~5 min</span></h3>
  <div class="body">
    <dl class="spec">
      <dt>Switch on</dt><dd><b>01 Phone check – passcode by SMS</b></dd>
      <dt>Arrives</dt><dd>Within seconds of saving. No waiting.</dd>
      <dt>Where</dt><dd><b>Arm 1 — Standard Care (SC)</b>, Day 1 (ED)</dd>
    </dl>
    <div class="warn"><b>This one needs the participant survey actually completed.</b> Filling in
      <code>contact_info</code> as a coordinator and stopping there is <i>not</i> enough — the
      passcode is generated by a hidden calculation on that form, and until it has a value the alert
      is blocked. Work through the survey as a participant would, to the end.</div>
    <ol class="steps">
      <li><kbd>Add / Edit Records</kbd> → arm <b>Arm 1: Standard Care (SC)</b> →
        <kbd>Add new record</kbd>. <b>Write down the record number.</b></li>
      <li>Open the <b>Day 1 (ED)</b> event.</li>
      <li>Open <code>consent</code>, fill enough to be plausible, status <b>Complete</b>, save.</li>
      <li>Open <code>contact_info</code> and fill:
        <table style="margin:5pt 0 0;">
          <tbody>
            <tr><td class="f" style="width:110pt">first_name</td><td>your first name</td></tr>
            <tr><td class="f">phonen</td><td><b>your own mobile number</b></td></tr>
            <tr><td class="f">email</td><td>your email address</td></tr>
            <tr><td class="f">choice_fup_delivery</td><td>tick <b>“SMS to [phonen]”</b></td></tr>
          </tbody>
        </table>
      </li>
      <li>Status <b>Complete</b> and save.</li>
      <li><b>Now complete the survey.</b> Open the participant survey link for this record and work
        through it to the end, rather than stopping at <code>contact_info</code>. The passcode is
        only generated once the survey run has produced it.</li>
    </ol>
    <div class="warn"><b>Then look at <code>calcrnd</code> before anything else.</b> It must show a
      <b>4-digit</b> number. That is the passcode, and the alert is <b>not allowed to send while it
      is blank</b> — you would get no text and no explanation. A blank <code>calcrnd</code> is the
      single most common reason this test appears to fail. If it is blank, the survey was not taken
      far enough; go back and finish it. If it shows <b>8 digits</b>, stop and tell the study team:
      a fix has not been applied to this project.
      <span class="nb"> All four must be true before a text goes out: <code>consent</code>
      Complete, <code>contact_info</code> Complete, <code>phonen</code> filled in,
      <code>calcrnd</code> showing four digits.</span></div>
    <div class="pass"><b>PASS</b> — a text arrives reading <i>“MICA: your verification passcode is
      NNNN…”</i>, and those four digits are the ones you just read in <code>calcrnd</code>.</div>
    <ol class="steps" start="7">
      <li><b>Finish the test.</b> Open <code>check_code</code>, type those four digits into
        <code>passcode</code>, save. <code>calc_code_check</code> must show <b>1</b> and the page must
        show the “code correct” message. The text arriving is only half the requirement.</li>
    </ol>
    <div class="warn"><b>Sends once per record, ever.</b> Re-saving will not produce a second text. To
      run this test again, create a new record.</div>
  </div>
</div>

<h2>Test 2 &nbsp;Session reminder after one week</h2>
<div class="test">
  <h3>Nudges a participant randomised a week ago who has not done their MICA session<span>~5 min</span></h3>
  <div class="body">
    <dl class="spec">
      <dt>Switch on</dt><dd><b>03 MICA ED session 1-week reminder (SMS)</b> — and switch test 1's alert off</dd>
      <dt>Arrives</dt><dd>Within about 90 seconds. REDCap checks for due reminders once a minute.</dd>
      <dt>Where</dt><dd>Details at <b>Arm 1's</b> Day 1 (ED); the record sits in <b>Arm 3</b></dd>
    </dl>
    <ol class="steps">
      <li><kbd>Add / Edit Records</kbd> → arm <b>Arm 1: Standard Care (SC)</b> →
        <kbd>Add new record</kbd>. Note the number. Open <b>Day 1 (ED)</b>.
        <span class="nb">Yes, Arm 1 — rule 1. The record moves to Arm 3 in step 4.</span></li>
      <li>On <code>admin</code>: <code>randomization_date</code> = <b>today minus 7 days</b>.
        Leave <code>sms_stop</code> and <code>study_withdrawn</code> unticked. Save.</li>
      <li>On <code>contact_info</code>: <code>first_name</code>, <code>phonen</code> = your mobile,
        tick <b>“SMS to [phonen]”</b>. Save. <span class="nb">No <code>consent</code> needed — this
        alert does not look at it.</span></li>
      <li><b>Now switch the same record to <kbd>Arm 3: MICA + Weekly SMS</kbd></b> using the arm
        selector, and open its <b>Day 1 (ED)</b> event. The record is now in both arms, which is
        exactly how a real Arm 3 participant looks.</li>
      <li><b>Save one form at Arm 3's Day 1</b> — open <code>admin</code> there and save it without
        changing anything. <span class="nb">This is rule 2. The reminder belongs to Arm 3's Day 1
        and REDCap only checks for it when something is saved at that event. Skip this step and you
        will get no text and conclude, wrongly, that it is broken.</span></li>
      <li><b>Do not touch <code>mica_ed_session</code>.</b> The reminder only goes to people who have
        not finished it.</li>
      <li>Wait 90 seconds and check your phone.</li>
    </ol>
    <div class="pass"><b>PASS</b> — a text arrives reading <i>“Hi [your name], it's the MICA Team.
      Your MICA session is ready (about 20 min): [link] Reply STOP to opt out.”</i><br>
      <b>Then tap the link.</b> It must open the MICA session for <i>your</i> record. A link that
      opens nothing, or someone else's record, is a failure even though the text arrived.</div>
  </div>
</div>

<h2>Test 3 &nbsp;Opting out really does stop the texts</h2>
<div class="test">
  <h3>Nothing arriving is the pass. This is the protection that matters most.<span>~4 min</span></h3>
  <div class="body">
    <dl class="spec">
      <dt>Switch on</dt><dd><b>03 MICA ED session 1-week reminder (SMS)</b> — same as test 2</dd>
      <dt>Expected</dt><dd><b>No text at all.</b></dd>
    </dl>
    <ol class="steps">
      <li><b>New record</b>, set up exactly as test 2 — details at <b>Arm 1's</b> Day 1, backdated
        7 days, your mobile, SMS ticked, then moved into Arm 3.</li>
      <li><b>At Arm 1's Day 1</b>, on <code>admin</code>, <b>tick <code>sms_stop</code></b>. Save.
        <span class="nb">Arm 1's, not Arm 3's — the alert reads the opt-out at the same event as
        everything else.</span></li>
      <li>Now save a form at <b>Arm 3's</b> Day 1, as in test 2 step 5, so the reminder is actually
        checked for.</li>
      <li>Wait two minutes.</li>
    </ol>
    <div class="pass"><b>PASS</b> — no text arrives. Now untick <code>sms_stop</code> at Arm 1's
      Day 1, save, then save at Arm 3's Day 1 again and wait 90 seconds: the text should come
      through. That second half is what proves the silence was caused by the opt-out and not by
      something being broken.</div>
    <div class="warn"><b>Do not test this by texting STOP.</b> It works, but the phone company then
      blocks your handset for the study until you text START back, and you would not be able to run
      any further tests. Ticking <code>sms_stop</code> is exactly what the alert itself reads.</div>
  </div>
</div>

<h2>Test 4 &nbsp;Reminders stop once the participant finishes</h2>
<div class="test">
  <h3>If this fails, people who already finished still get chased — worse than no reminders at all<span>~4 min</span></h3>
  <div class="body">
    <dl class="spec">
      <dt>Switch on</dt><dd><b>03 MICA ED session 1-week reminder (SMS)</b></dd>
      <dt>Expected</dt><dd><b>No text at all.</b></dd>
    </dl>
    <ol class="steps">
      <li><b>New record</b>, set up exactly as test 2 — details at <b>Arm 1's</b> Day 1, then moved
        into Arm 3.</li>
      <li><b>This time complete the session:</b> at <b>Arm 3's</b> Day 1 open
        <code>mica_ed_session</code>, status <b>Complete</b>, save.
        <span class="nb">This save doubles as rule 2 — it lands on Arm 3's Day 1, so the reminder is
        genuinely checked for and genuinely cancelled, rather than never having been considered.</span></li>
      <li>Wait two minutes.</li>
    </ol>
    <div class="pass"><b>PASS</b> — no text arrives. The reminder was due, saw the session was
      finished, and cancelled itself.</div>
    <div class="nb">Worth knowing, not worrying about: the cancellation happens on REDCap's
      once-a-minute check, not the instant you save. So there is a window of up to a minute after a
      participant finishes in which a reminder could still go out.</div>
  </div>
</div>

<h2>Test 5 &nbsp;Three-month booster reminder</h2>
<div class="test">
  <h3>Also confirms an Arm 3 participant gets Arm 3's reminder, not Arm 2's<span>~5 min</span></h3>
  <div class="body">
    <dl class="spec">
      <dt>Switch on</dt><dd><b>11 MICA booster session reminder (SMS, arm 3)</b></dd>
      <dt>Arrives</dt><dd>Within about 90 seconds.</dd>
    </dl>
    <ol class="steps">
      <li><b>New record, added in Arm 1.</b> At <b>Arm 1's Day 1 (ED)</b>, on <code>admin</code>:
        <code>randomization_date</code> = <b>today minus 92 days</b>. Save.</li>
      <li>Still at <b>Arm 1's Day 1</b>, on <code>contact_info</code>: <code>first_name</code>,
        <code>phonen</code> = your mobile, tick SMS. Save.</li>
      <li><b>Switch the record to <kbd>Arm 3: MICA + Weekly SMS</kbd>.</b></li>
      <li><b>Now move to Arm 3's <kbd>Month 3</kbd> event</b>, open <code>mica_booster_session</code>
        and <b>save it without completing it</b> — leave the status Incomplete.
        <span class="nb">Rule 2 again, and this is the test where it bites hardest: the reminder
        lives on the Month 3 event and REDCap only looks at it when something is saved there.
        Changing the date at Arm 1's Day 1 will not do it — that was measured. Skip this step and
        you will conclude it is broken.</span></li>
      <li>Wait 90 seconds and check your phone.</li>
    </ol>
    <div class="pass"><b>PASS</b> — a text arrives reading <i>“Hi [your name], it's the MICA Team.
      Your 3-month booster session is ready: [link] Reply STOP to opt out.”</i> Tap the link — it must
      open the <b>booster</b> session, not the Day-1 session.</div>
  </div>
</div>

<div class="page"></div>
<h2>The weekly 12-week text series</h2>

<div class="note">
  <b>Now configured on the production project, but switched off.</b> The weekly invitation is built
  and will not send to anyone until the study team switches it on. It runs on a separate module,
  <b>Enhanced SMS Conversation</b>, so the “Surveys and Alerts” setting on page 1 does not affect it.
  It is not an alert and does not appear under <kbd>Alerts &amp; Notifications</kbd>.
  <br><br>
  Texts go only to participants <b>randomised to Arm 3</b>, starting the first Sunday at noon after
  randomisation. Randomisation on production is being fixed separately; until then, the weekly
  series has no one to send to.
</div>

<h2>Test 6A &nbsp;The texting line answers</h2>
<div class="test">
  <h3>Proves texts reach the study and replies come back. You can do this one alone.<span>~3 min</span></h3>
  <div class="body">
    <dl class="spec">
      <dt>Switch on</dt><dd>Nothing. This works with the weekly invitation switched off.</dd>
      <dt>You need</dt><dd>The study texting number, which the study team will give you</dd>
    </dl>
    <ol class="steps">
      <li><kbd>Add / Edit Records</kbd> → arm <b>Arm 1: Standard Care (SC)</b> →
        <kbd>Add new record</kbd>. Open <b>Day 1 (ED)</b> → <code>contact_info</code>.</li>
      <li><code>phonen</code> = <b>your own mobile number</b>. Save.
        <span class="nb">Use a number that is on no other record. The line matches your number to a
        record, and if several share it, the reply is tied to whichever REDCap finds first.</span></li>
      <li>From that phone, text <b>hello</b> to the study texting number.</li>
    </ol>
    <div class="pass"><b>PASS</b> — within a few seconds you receive <i>“Thanks! We will check in
      soon.”</i> That is the line's automatic answer when no weekly check-in is open.</div>
    <div class="warn"><b>Text “hello”, never STOP or OPTOUT.</b> Those opt your record out of all
      texts (START reverses it).</div>
  </div>
</div>

<h2>Test 6B &nbsp;One real weekly check-in</h2>
<div class="test">
  <h3>The actual Sunday conversation, sent to your phone only<span>~10 min</span></h3>
  <div class="body">
    <dl class="spec">
      <dt>Needs first</dt><dd>Randomisation working on production</dd>
      <dt>Study team</dt><dd>Prepares one test record <b>randomised to Arm 3</b> with your mobile
        number and a randomisation date, then switches the weekly invitation on <b>for that record
        only</b>. No participant can be texted.</dd>
    </dl>
    <ol class="steps">
      <li>Wait for the study team to tell you it is on. The first text arrives within a minute if
        the randomisation date is more than a week ago; otherwise it arrives that Sunday at noon.</li>
      <li>Reply to each text. Suggested answers: <b>0</b>, then <b>Yes</b>, then <b>Yes</b>.</li>
      <li>Check you received, in order: the greeting with your first name, the drinks question, an
        alcohol-free message, “Are you planning on drinking…”, the goal question with a number (4 for
        men, 3 for women), and a closing message.</li>
    </ol>
    <div class="pass"><b>PASS</b> — the messages arrive in that order, your name and the goal number
      are filled in (not blank), and your answers appear on the test record's <code>sunday</code>
      form at <b>Weeks 1–12</b>, week 1.</div>
    <div class="nb">Try a different answer path by asking the study team to run another week: for
      example <b>3</b>, <b>Yes</b>, <b>No</b> (below-limit message, no goal), or <b>7</b>, <b>No</b>
      (above-limit message, “Great! Thanks for letting us know.”).</div>
  </div>
</div>

<p>This is what a participant receives each week. Reply by text; the conversation waits for you.</p>

<div class="script">
  <div><span class="lbl">MICA</span><span class="txt">Hi [first name], it's the MICA Team checking in.</span></div>
  <div><span class="lbl">MICA</span><span class="txt">How many total standard alcohol drinks did you have over the past 7 days?</span></div>
  <div class="you">you reply with a number</div>
  <div><span class="lbl">MICA</span><span class="txt">One tailored message, chosen from the number you gave:<br>
    <b>0 drinks</b> — “Amazing job staying alcohol-free this week!…”<br>
    <b>below your limit</b> — “You reported drinking this week while staying below higher-risk levels…”<br>
    <b>at or above your limit</b> — “This week's drinking was in the range considered unhealthy…”</span></div>
  <div><span class="lbl">MICA</span><span class="txt">Are you planning on drinking alcohol this week?</span></div>
  <div class="you">you reply Yes or No</div>
  <div><span class="lbl">MICA</span><span class="txt"><b>If No</b> — “Great! Thanks for letting us know.” and the week ends.<br>
    <b>If Yes</b> — “Would you be willing to commit to a goal to drink N or fewer drinks on any occasion?”</span></div>
  <div class="you">you reply Yes or No</div>
  <div><span class="lbl">MICA</span><span class="txt">One closing message, different for Yes and for No.</span></div>
  <div><span class="lbl">MICA</span><span class="txt"><b>Week 12 only</b> — “This completes the text messaging intervention part of the MICA study.”</span></div>
</div>

<p class="nb">Every message is different each week — twelve distinct versions of each one, so a
participant never sees the same wording twice. That is 63 messages in total; the full library is in
the separate message-review document, which is the part we would most like your eye on.</p>

<table>
  <thead><tr><th style="width:34%">Also worth knowing</th><th>Detail</th></tr></thead>
  <tbody>
    <tr><td>Reply window</td><td>Leave any question unanswered for <b>60 minutes</b> and it is sent again, starting “We missed your response”. <b>24 hours</b> after the first text, an unfinished week closes with “You must be busy. We will check in again later.” and waits for the next one.</td></tr>
    <tr><td>Cadence</td><td>Sundays, twelve times, starting the first Sunday after randomisation. ASPIRE also texted on Thursdays; MICA does not.</td></tr>
    <tr><td>Opting out</td><td>Replying STOP is designed to stop the weekly texts <i>and</i> record the opt-out on the participant's record automatically. <span class="nb">We have read this in the module's code but have not yet tested it end to end — do not treat it as verified.</span></td></tr>
  </tbody>
</table>

<h2>Record what you found</h2>
<p>Please note the record number for each test. If a text does not arrive, the two questions we will
ask are <b>“which record?”</b> and <b>“what exactly did you type into
<code>randomization_date</code>, and was it on the first save?”</b></p>

<table>
  <thead><tr><th style="width:34%">Test</th><th style="width:14%">Record #</th><th style="width:17%">Text arrived?</th><th style="width:15%">Link opened?</th><th>Notes</th></tr></thead>
  <tbody>
    <tr><td>1 — passcode text (Arm 1)</td><td></td><td></td><td>n/a</td><td></td></tr>
    <tr><td>1 — code accepted (<code>calc_code_check</code> = 1)</td><td></td><td>n/a</td><td>n/a</td><td></td></tr>
    <tr><td>2 — 1-week session reminder</td><td></td><td></td><td></td><td></td></tr>
    <tr><td>3 — opt-out silences it</td><td></td><td>should be <b>NO</b></td><td>n/a</td><td></td></tr>
    <tr><td>4 — finishing cancels it</td><td></td><td>should be <b>NO</b></td><td>n/a</td><td></td></tr>
    <tr><td>5 — booster reminder</td><td></td><td></td><td></td><td></td></tr>
    <tr><td>6A — “hello” gets a reply</td><td></td><td></td><td>n/a</td><td></td></tr>
    <tr><td>6B — weekly check-in, right messages</td><td></td><td></td><td>n/a</td><td></td></tr>
  </tbody>
</table>

<div class="warn"><b>When you are finished, switch every alert off again</b> in
<kbd>Alerts &amp; Notifications</kbd>. The study has not gone live, and an alert left on will keep
acting on any record anyone creates afterwards.</div>

<h2>If something does not work</h2>
<table>
  <thead><tr><th style="width:40%">What you see</th><th>Almost always</th></tr></thead>
  <tbody>
    <tr><td>No text on any test</td><td>Texting scope is “Surveys only” instead of “Surveys and Alerts”.</td></tr>
    <tr><td>Passcode text never arrives</td><td><code>calcrnd</code> is blank — see the box in test 1. Or <code>consent</code>/<code>contact_info</code> left at <b>Unverified</b> rather than <b>Complete</b>.</td></tr>
    <tr><td>No <code>consent</code> to open</td><td>You are in Arm 3. Test 1 is in <b>Arm 1</b>.</td></tr>
    <tr><td>A timed reminder never arrives</td><td>Rule 2 — the date was changed after the first save. Start a new record.</td></tr>
    <tr><td>An email arrives instead of a text</td><td>Only “Email to [email]” was ticked. Tick the SMS option.</td></tr>
    <tr><td>Booster reminder never arrives</td><td>Step 3 of test 5 was skipped — something must be saved at the <b>Month 3</b> event.</td></tr>
    <tr><td>A test worked once, never again</td><td>Expected for the passcode: once per record. Create a new record.</td></tr>
    <tr><td>Test 6A: no reply to “hello”</td><td>Your number isn't saved in <code>phonen</code> at Arm 1's Day 1 (another event doesn't count). Otherwise send the study team the time you texted; the texting account's log shows exactly why.</td></tr>
    <tr><td>Test 6B: a message shows a blank name or goal number</td><td>Stop and tell the study team. The record is missing <code>first_name</code> or sex at Arm 1's Day 1.</td></tr>
  </tbody>
</table>
<p class="nb">Anything not on this list — send us the record number and roughly what time you saved.
We can see exactly what REDCap decided, and why.</p>

</body></html>`;

const FOOTER = `<div style="width:100%;font:7.5pt 'Helvetica Neue',Arial,sans-serif;color:#8a8a90;
  padding:0 16mm;display:flex;justify-content:space-between;">
  <span>MICA Arm 3 — SMS test manual — ${DATE}</span>
  <span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>`;

(async () => {
  const out = path.join(HERE, 'MICA_Arm3_SMS_Test_Manual.pdf');
  const b = await chromium.launch();
  const p = await b.newPage();
  await p.setContent(html, { waitUntil: 'load' });
  await p.pdf({
    path: out, format: 'Letter', printBackground: true,
    displayHeaderFooter: true, headerTemplate: '<span></span>', footerTemplate: FOOTER,
    margin: { top: '18mm', bottom: '16mm', left: '16mm', right: '16mm' },
  });
  await b.close();
  console.log(`wrote ${out} (${(fs.statSync(out).size / 1024).toFixed(0)} KB)`);
})();
