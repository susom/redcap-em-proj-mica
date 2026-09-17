// Build the PI-facing Arm 3 SMS test manual.
//
//   cd <module root> && NODE_PATH="$PWD/node_modules" node docs/alerts/pi-review/build-sms-manual.js
//
// Every field location, alert condition and timing figure below was read from redcap_metadata /
// redcap_alerts on PID 257 on 2026-09-15 - NOT from README.md or TEST_PLAN_LOCALHOST.md, both of
// which still describe the pre-baseline-split layout and would send the PI to the wrong form.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const HERE = __dirname;
const DATE = '15 September 2026';

const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><style>
  @page { size: Letter; }
  * { box-sizing: border-box; }
  body { font: 10.5pt/1.55 "Charter","Georgia",serif; color: #1c1c1e; margin: 0; }
  h1 { font: 700 20pt/1.25 "Helvetica Neue",Arial,sans-serif; margin: 0 0 4pt; letter-spacing: -.3pt; }
  h2 { font: 700 13pt/1.3 "Helvetica Neue",Arial,sans-serif; margin: 19pt 0 7pt;
       padding-bottom: 4pt; border-bottom: 1.5px solid #8c1515; color: #8c1515; }
  h3 { font: 700 10.5pt/1.35 "Helvetica Neue",Arial,sans-serif; margin: 14pt 0 3pt; }
  p { margin: 0 0 7pt; }
  .sub { font: 400 10.5pt/1.4 "Helvetica Neue",Arial,sans-serif; color: #5b5b60; margin-bottom: 13pt; }
  .rule { height: 3px; background: #8c1515; margin: 0 0 13pt; }
  .tldr { background: #fbf6f6; border: 1px solid #e3cccc; padding: 10pt 13pt 5pt; margin: 0 0 13pt; }
  .tldr p { margin: 0 0 6pt; }
  .tldr b { color: #8c1515; }
  ol, ul { margin: 0 0 8pt; padding-left: 18pt; }
  li { margin-bottom: 6pt; }
  ol.steps > li { margin-bottom: 9pt; }
  ol.steps > li::marker { font-weight: 700; color: #8c1515; }
  table { border-collapse: collapse; width: 100%; margin: 0 0 10pt; font-size: 9.5pt; }
  th, td { text-align: left; padding: 4.5pt 7pt; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
  th { font: 700 8.5pt "Helvetica Neue",Arial,sans-serif; text-transform: uppercase;
       letter-spacing: .4pt; color: #5b5b60; border-bottom: 1.5px solid #c9c9cf; }
  /* Field names are case-sensitive identifiers the PI has to match exactly in REDCap, so the
     header's uppercasing must not reach them. */
  th code { text-transform: none; letter-spacing: 0; }
  td.f { font: 9pt "SF Mono",Menlo,monospace; white-space: nowrap; }
  kbd { font: 700 9pt "Helvetica Neue",Arial,sans-serif; background: #f2f2f4; border: 1px solid #dcdce0;
        border-radius: 2pt; padding: .5pt 4pt; }
  code { font: 9pt "SF Mono",Menlo,monospace; background: #f2f2f4; padding: .5pt 3pt; border-radius: 2pt; }
  .warn { background: #fff8f0; border: 1px solid #ecd9bd; border-radius: 2pt; padding: 8pt 11pt 3pt;
          margin: 8pt 0; font: 400 9.5pt/1.45 "Helvetica Neue",Arial,sans-serif; page-break-inside: avoid; }
  .warn b { color: #8a4b00; }
  .pass { background: #f4faf5; border: 1px solid #cfe5d4; border-radius: 2pt; padding: 8pt 11pt 3pt;
          margin: 8pt 0; font: 400 9.5pt/1.45 "Helvetica Neue",Arial,sans-serif; page-break-inside: avoid; }
  .pass b { color: #1d6b32; }
  .test { border: 1px solid #d4d4d8; border-radius: 3pt; padding: 11pt 13pt 5pt; margin: 0 0 12pt; }
  /* h3 must not be orphaned at a page foot, but the box itself may split - some are taller
     than a page and forcing them whole left half-empty pages. */
  .test h3, h2 { page-break-after: avoid; }
  .test h3 { margin-top: 0; }
  .meta { font: 400 9pt/1.4 "Helvetica Neue",Arial,sans-serif; color: #5b5b60; margin: 0 0 8pt; }
  .page { page-break-before: always; }
  .nb { font: 400 9pt/1.45 "Helvetica Neue",Arial,sans-serif; color: #5b5b60; }
</style></head><body>

<h1>Testing the Arm 3 text messages — step by step</h1>
<div class="sub">A hands-on walkthrough: create a participant, set the dates, get real texts &nbsp;·&nbsp; ${DATE}</div>
<div class="rule"></div>

<div class="tldr">
  <p><b>What this covers.</b> Three text messages that Arm 3 participants really receive, all built
  and working today: the <b>phone-confirmation passcode</b>, the <b>1-week session reminder</b>, and
  the <b>3-month booster reminder</b>. You will create one test participant and receive all three on
  your own phone.</p>
  <p><b>Time needed:</b> about 25 minutes, most of it typing the participant's details once.</p>
  <p><b>What you need:</b> a REDCap login with rights to add records, and your mobile phone.</p>
  <p><b>Not covered:</b> the weekly 12-week message series. That one is not built yet and is waiting
  on two decisions from you — see the separate weekly-SMS document.</p>
</div>

<div class="warn">
  <p><b>Real texts, to a real phone, costing real money.</b> The study's texting account is live.
  Every message in this manual will actually arrive on the number you enter. Use your own mobile,
  and don't put a participant's number in a test record.</p>
  <p><b>Never reply STOP to test opting out.</b> The phone company blocks your handset for the
  study permanently until you text START back, which would end your ability to test anything.
  Section 6 shows the safe way to test opt-out.</p>
</div>

<h2>0 &nbsp;Before you start — ask your coordinator to confirm three things</h2>

<p>These are one-time setup items. If any is missing, no text can send no matter what you do, and
you would be left guessing why. A five-minute check now saves an hour.</p>

<table>
  <thead><tr><th>Needs to be true</th><th>Where</th></tr></thead>
  <tbody>
    <tr><td>The 21 study alerts exist in this project</td><td><b>Project Setup → Alerts &amp; Notifications.</b> You should see a numbered list starting “01 Phone check”. If the list is empty they have not been imported into this project yet — that has to happen first.</td></tr>
    <tr><td>Texting is switched on for alerts, not just surveys</td><td>The Twilio setting must be <b>“Surveys and Alerts”</b>. If it is “Surveys only”, every alert in this manual is silently undeliverable.</td></tr>
    <tr><td>The phone field is validated as a phone number</td><td><code>phonen</code> must have validation <b>Phone</b>. Without it REDCap refuses to use it as a text recipient.</td></tr>
  </tbody>
</table>

<h3>Then: switch on one alert at a time</h3>
<p>In <b>Alerts &amp; Notifications</b>, leave everything deactivated and enable only the alert each
test names. This is the single most useful habit in this whole manual: if five alerts are live at
once and a text arrives, you cannot tell which one sent it.</p>

<div class="page"></div>
<h2>1 &nbsp;Create your test participant in Arm 3</h2>

<ol class="steps">
  <li><b>Go to</b> <kbd>Add / Edit Records</kbd>.</li>
  <li><b>Choose the arm first.</b> In the arm dropdown pick <b>Arm 3: MICA + Weekly SMS</b>. This
      matters — Arm 3 is the only arm with the weekly-SMS schedule, and two of the three alerts
      below check which arm the participant is in before sending.</li>
  <li><b>Click</b> <kbd>Add new record</kbd>. Note the record number you get; you will need it.</li>
  <li><b>Open the</b> <kbd>Day 1 (ED)</kbd> <b>event.</b> Everything in sections 2–4 happens here.</li>
</ol>

<div class="nb"><p>Why pick the arm by hand rather than letting <code>study_group</code> do it: the
module can place a record into its randomized arm automatically, but choosing Arm 3 up front is one
click and removes any doubt about where the record landed. You will still set
<code>study_group = 3</code> in the next section, because that is the field the study's reports and
message text read.</p></div>

<h2>2 &nbsp;Fill in the <code>admin</code> instrument — the four fields that control texting</h2>

<p>Open the <b>admin</b> instrument at Day 1 (ED). These four fields are what make the difference
between “no text ever arrives” and “the right text arrives at the right time”.</p>

<table>
  <thead><tr><th>Field</th><th>What to enter</th><th>Why it matters</th></tr></thead>
  <tbody>
    <tr><td class="f">randomization_date</td><td><b>See section 3 — the date depends on which test you are running. Get this right on your first save.</b></td><td>Every timed reminder counts days from this date. It is the clock for the whole study.</td></tr>
    <tr><td class="f">study_group</td><td><b>3 — MICA + Weekly SMS</b></td><td>Records the allocation. Message text pipes this in, so a wrong value shows up in the text the participant reads.</td></tr>
    <tr><td class="f">sms_stop</td><td><b>Leave unticked</b></td><td>Ticking it suppresses every text. Section 6 uses this deliberately.</td></tr>
    <tr><td class="f">study_withdrawn</td><td><b>Leave unticked</b></td><td>Same — withdrawn participants get nothing.</td></tr>
  </tbody>
</table>

<p>Set the instrument status to <b>Complete</b> and save.</p>

<div class="warn">
  <p><b>The one mistake that wastes a whole test run.</b> REDCap works out when a reminder is due
  the <i>first</i> time you save a date into <code>randomization_date</code>, and then never
  recalculates it. If you save today's date and afterwards go back and change it to a date in the
  past, the reminder keeps its original schedule and <b>nothing will arrive</b> — with no error to
  tell you why.</p>
  <p>So: <b>type the backdated date on your very first save of this form.</b> If you get it wrong,
  don't try to correct it — go back to section 1 and create a fresh record. That is genuinely
  faster than unpicking it.</p>
</div>

<h2>3 &nbsp;Which date to enter</h2>

<p>Rather than waiting three months for a reminder, you set the randomization date in the past so
the reminder is already due. Pick the row for the test you want and count back from today.</p>

<table>
  <thead><tr><th>Test</th><th>Enter as <code>randomization_date</code></th><th>Which text arrives</th></tr></thead>
  <tbody>
    <tr><td>Section 4 — passcode</td><td><b>Today's date</b> (any date works)</td><td>Passcode text, immediately</td></tr>
    <tr><td>Section 5 — 1-week session reminder</td><td><b>Today minus 7 days</b></td><td>Session reminder, within about 90 seconds</td></tr>
    <tr><td>Section 7 — 3-month booster reminder</td><td><b>Today minus 92 days</b></td><td>Booster reminder, within about 90 seconds</td></tr>
  </tbody>
</table>

<p class="nb">You cannot do all three on one record in one sitting, because they want different
dates. The simplest approach is one record per test — records are free, and each one keeps its own
clean evidence of what happened.</p>

<h2>4 &nbsp;Test one — the phone-confirmation passcode</h2>

<div class="test">
  <p class="meta"><b>Activate only:</b> “01 Phone check – passcode by SMS” &nbsp;·&nbsp;
  <b>Arrives:</b> within seconds of saving, no waiting &nbsp;·&nbsp;
  <b>Sends:</b> once per record, ever</p>

  <ol class="steps">
    <li><b>Open the</b> <code>consent</code> <b>instrument</b>, fill enough to be plausible, set the
        status to <b>Complete</b>, save.</li>
    <li><b>Open the</b> <code>contact_info</code> <b>instrument.</b> This is the form that sends the
        passcode. Fill in:
      <table style="margin-top:5pt">
        <tbody>
          <tr><td class="f">first_name</td><td>your name — it is piped into other messages</td></tr>
          <tr><td class="f">phonen</td><td><b>your own mobile number</b></td></tr>
          <tr><td class="f">email</td><td>your email address</td></tr>
          <tr><td class="f">choice_fup_delivery</td><td>tick <b>“SMS to [phonen]”</b></td></tr>
        </tbody>
      </table>
    </li>
    <li><b>Check the passcode field filled itself in.</b> <code>calcrnd</code> should now show a
        <b>4-digit</b> number. If it shows 8 digits, stop and tell the study team — a fix has not
        been applied to this project.</li>
    <li><b>Set the status to Complete and save.</b> The text is sent during the save itself.</li>
    <li><b>Check your phone.</b></li>
  </ol>

  <div class="pass"><p><b>Pass:</b> a text arrives reading <i>“MICA: your verification passcode is
  NNNN. Enter it on the Check Code form to confirm this is the right phone number.”</i> — where NNNN
  is the same 4 digits you just saw in <code>calcrnd</code>.</p></div>

  <ol class="steps" start="6">
    <li><b>Close the loop — this is the actual point of the test.</b> Open the
        <code>check_code</code> instrument, type those 4 digits into <code>passcode</code>, save.
        <code>calc_code_check</code> must compute <b>1</b>, and the page should show the
        “code correct” message rather than the “code wrong” one. The text arriving is only half the
        requirement; this is the half that proves the phone number is confirmed.</li>
  </ol>

  <div class="warn"><p><b>One passcode test per record.</b> This alert deliberately sends only once
  per participant, so re-saving the form will not produce a second text. To test it again, create a
  new record.</p></div>
</div>

<h2>5 &nbsp;Test two — the 1-week session reminder</h2>

<div class="test">
  <p class="meta"><b>Activate only:</b> “03 MICA ED session 1-week reminder (SMS)” &nbsp;·&nbsp;
  <b>Arrives:</b> within about 90 seconds &nbsp;·&nbsp;
  <b>Date needed:</b> <code>randomization_date</code> = today minus 7 days</p>

  <p>This is the nudge for a participant who was randomized a week ago and still has not done their
  MICA session. Use a <b>fresh record</b> (section 1), with the backdated date on the first save
  (section 2).</p>

  <ol class="steps">
    <li><b>On</b> <code>admin</code>: <code>randomization_date</code> = today minus 7 days,
        <code>study_group</code> = 3. Save.</li>
    <li><b>On</b> <code>contact_info</code>: <code>first_name</code>, <code>phonen</code> = your
        mobile, and tick <b>“SMS to [phonen]”</b>. Save.</li>
    <li><b>Leave the</b> <code>mica_ed_session</code> <b>instrument alone.</b> The reminder only
        goes to people who have <i>not</i> finished their session — completing it is how the
        reminder gets cancelled, which is section 8.</li>
    <li><b>Wait about 90 seconds</b> and check your phone. REDCap checks for due reminders once a
        minute, so this is not instant like the passcode.</li>
  </ol>

  <div class="pass"><p><b>Pass:</b> a text arrives reading <i>“Hi [your name], it's the MICA Team.
  Your MICA session is ready (about 20 min): [link] Reply STOP to opt out.”</i></p>
  <p><b>Then tap the link.</b> It must open the MICA session for <i>your</i> record. A link that
  opens nothing, or someone else's record, is a failure even though the text arrived — so please do
  tap it.</p></div>

  <div class="warn"><p><b>If nothing arrives after three minutes,</b> the most likely cause is the
  date order described in section 2. Create a fresh record and enter the backdated date on the first
  save. The second most likely cause is that “SMS to [phonen]” was not ticked — if you tick
  <i>only</i> email, this alert correctly stays silent and the email version sends instead.</p></div>
</div>

<h2>6 &nbsp;Test three — opting out actually stops the texts</h2>

<div class="test">
  <p class="meta"><b>Activate only:</b> “03 MICA ED session 1-week reminder (SMS)” &nbsp;·&nbsp;
  <b>Expected result: no text at all</b></p>

  <p>A test where nothing happening <i>is</i> the pass. Worth doing, because it is the protection
  that matters most to a participant who has asked you to stop.</p>

  <ol class="steps">
    <li><b>Create a fresh record</b> exactly as in section 5 — backdated date, your mobile, SMS
        ticked.</li>
    <li><b>Before saving</b> <code>contact_info</code>, go to <code>admin</code> and
        <b>tick</b> <code>sms_stop</code>. Save.</li>
    <li><b>Wait two minutes.</b></li>
  </ol>

  <div class="pass"><p><b>Pass:</b> no text arrives. Untick <code>sms_stop</code>, save again, wait
  90 seconds, and the text should then come through — which proves the silence was caused by the
  opt-out and not by something else being broken.</p></div>

  <div class="warn"><p><b>Please don't test this by texting STOP.</b> That works, but the phone
  company then blocks your handset for the study's number until you text START back, and you would
  not be able to run any further tests. Ticking <code>sms_stop</code> is exactly what the alert
  itself reads, so it is a faithful test.</p>
  <p>Worth knowing: if a real participant texts STOP, the phone company stops their texts
  immediately — but REDCap is never told. Someone has to tick this box by hand. That is a gap we
  have flagged separately.</p></div>
</div>

<h2>7 &nbsp;Test four — the 3-month booster reminder (Arm 3 specific)</h2>

<div class="test">
  <p class="meta"><b>Activate only:</b> “11 MICA booster session reminder (SMS, arm 3)” &nbsp;·&nbsp;
  <b>Date needed:</b> <code>randomization_date</code> = today minus 92 days</p>

  <p>There are separate booster alerts for Arm 2 and Arm 3, and this test also confirms an Arm 3
  participant gets the <b>Arm 3</b> one rather than Arm 2's.</p>

  <ol class="steps">
    <li><b>Create a fresh record in Arm 3.</b> On <code>admin</code> at Day 1 (ED):
        <code>randomization_date</code> = <b>today minus 92 days</b>, <code>study_group</code> = 3.
        Save.</li>
    <li><b>On</b> <code>contact_info</code> at Day 1 (ED): <code>first_name</code>,
        <code>phonen</code>, tick SMS. Save.</li>
    <li><b>Now move to the</b> <kbd>Month 3</kbd> <b>event</b> and open
        <code>mica_booster_session</code>. <b>Save it without completing it</b> — leave the status
        Incomplete.
        <div class="nb" style="margin-top:4pt">This save is not busywork. The booster reminder is
        attached to the Month 3 event, and REDCap only looks at it when something is saved
        <i>there</i>. Without this step the reminder is never even considered, and you would
        conclude it was broken.</div></li>
    <li><b>Wait about 90 seconds</b> and check your phone.</li>
  </ol>

  <div class="pass"><p><b>Pass:</b> a text arrives reading <i>“Hi [your name], it's the MICA Team.
  Your 3-month booster session is ready: [link] Reply STOP to opt out.”</i> Tap the link — it must
  open the <b>booster</b> session (not the Day-1 session) for your record.</p></div>

  <p class="nb">If you want the second booster nudge as well, repeat with
  <code>randomization_date</code> = today minus <b>98</b> days and activate “13 MICA booster session
  1-week reminder (SMS, arm 3)” instead.</p>
</div>

<h2>8 &nbsp;Test five — reminders stop once the participant finishes</h2>

<div class="test">
  <p class="meta"><b>Activate:</b> “03 MICA ED session 1-week reminder (SMS)” &nbsp;·&nbsp;
  <b>Expected result: no text</b></p>

  <p>The most important test in this manual. If this one fails, participants who have already
  finished their session still get chased — which is worse than having no reminders at all.</p>

  <ol class="steps">
    <li><b>Create a fresh record</b> as in section 5 — backdated 7 days, your mobile, SMS ticked.</li>
    <li><b>This time, complete the</b> <code>mica_ed_session</code> <b>instrument</b> — set its
        status to <b>Complete</b> and save.</li>
    <li><b>Wait two minutes.</b></li>
  </ol>

  <div class="pass"><p><b>Pass:</b> no text arrives. The reminder was due, saw that the session was
  finished, and cancelled itself.</p></div>

  <p class="nb">A detail worth knowing rather than worrying about: the cancellation happens on
  REDCap's next once-a-minute check, not the instant you save. So there is a window of up to a
  minute after a participant finishes in which a reminder could still go out. With a one-minute
  check that is small, but it is not zero.</p>
</div>

<h2>9 &nbsp;Recording what you found</h2>

<p>Please note the record number for each test — that is how we trace any failure back to what
actually happened in the database. If a text does not arrive, the two questions we will ask are
<b>“which record?”</b> and <b>“what exactly did you type into <code>randomization_date</code>, and
was it on the first save?”</b></p>

<table>
  <thead><tr><th>Test</th><th>Record</th><th>Text arrived?</th><th>Link worked?</th><th>Notes</th></tr></thead>
  <tbody>
    <tr><td>4 — passcode</td><td></td><td></td><td>n/a</td><td></td></tr>
    <tr><td>4 — code accepted (<code>calc_code_check</code> = 1)</td><td></td><td>n/a</td><td>n/a</td><td></td></tr>
    <tr><td>5 — 1-week reminder</td><td></td><td></td><td></td><td></td></tr>
    <tr><td>6 — opt-out silences it</td><td></td><td>should be NO</td><td>n/a</td><td></td></tr>
    <tr><td>7 — booster reminder (arm 3)</td><td></td><td></td><td></td><td></td></tr>
    <tr><td>8 — finishing cancels it</td><td></td><td>should be NO</td><td>n/a</td><td></td></tr>
  </tbody>
</table>

<h3>When you are done</h3>
<p>Please <b>deactivate every alert again</b> in Alerts &amp; Notifications. The study has not gone
live, and an alert left switched on will keep acting on any record anyone creates afterwards.</p>

<h2>10 &nbsp;If something does not work</h2>

<table>
  <thead><tr><th>What you see</th><th>Most likely cause</th></tr></thead>
  <tbody>
    <tr><td>No text at all, on any test</td><td>One of the three setup items in section 0 — most often Twilio set to “Surveys only” rather than “Surveys and Alerts”.</td></tr>
    <tr><td>Passcode text never arrives</td><td><code>consent</code> or <code>contact_info</code> left at <b>Unverified</b> instead of <b>Complete</b>. The alert checks for Complete specifically.</td></tr>
    <tr><td>Passcode is 8 digits, not 4</td><td>A formula fix has not been applied to this project. Tell the study team before going further.</td></tr>
    <tr><td>Timed reminder never arrives</td><td>The date-order trap in section 2. Start a fresh record.</td></tr>
    <tr><td>An email arrives instead of a text</td><td>Only “Email to [email]” was ticked in <code>choice_fup_delivery</code>. Tick the SMS option.</td></tr>
    <tr><td>Booster reminder never arrives</td><td>Step 3 of section 7 skipped — something must be saved at the <b>Month 3</b> event.</td></tr>
    <tr><td>A test worked once and never again</td><td>Expected for the passcode, which sends once per record. Create a new record.</td></tr>
  </tbody>
</table>

<p>Anything not on this list, send us the record number and roughly what time you saved — we can see
exactly what REDCap decided and why.</p>

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
