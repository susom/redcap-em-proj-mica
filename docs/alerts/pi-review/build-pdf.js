// Build the PI-facing Arm 3 weekly-SMS review PDF.
//
//   cd <module root> && NODE_PATH="$PWD/node_modules" node docs/alerts/pi-review/build-pdf.js
//
// The message library is read from message-library.tsv (exported from redcap_metadata) rather than
// retyped, so the PI reviews the copy that is actually in the project. The screenshot is embedded
// as base64 so the PDF is a single self-contained file that can be emailed.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const HERE = __dirname;
const DATE = '15 September 2026';

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

// ---- message library ---------------------------------------------------------------------------
const rows = fs.readFileSync(path.join(HERE, 'message-library.tsv'), 'utf8')
  .split('\n').filter(Boolean)
  .map((l) => { const [f, ...r] = l.split('\t'); return { field: f, text: r.join('\t').trim() }; });

const byPrefix = (p) => rows.filter((r) => new RegExp(`^${p}_\\d+$`).test(r.field))
  .sort((a, b) => +a.field.split('_')[1] - +b.field.split('_')[1]);
const one = (f) => (rows.find((r) => r.field === f) || {}).text || '';

const GROUPS = [
  { key: 'ar', title: 'A — Drank nothing this week', when: 'Shown when the participant reports 0 drinks.',
    status: 'never displays today', rows: byPrefix('ar') },
  { key: 'sd', title: 'B — Drank, but below the risk threshold', when: 'Shown when drinks are below the participant’s threshold (4 or 5, by birth sex).',
    status: 'all 12 display at once today', rows: byPrefix('sd') },
  { key: 'bd', title: 'C — Drank above the risk threshold', when: 'Shown when drinks are at or above the threshold.',
    status: 'all 12 display at once today', rows: byPrefix('bd') },
  { key: 'gp', title: 'D — Not ready to set a goal', when: 'Shown when the participant plans to drink but declines a limit.',
    status: 'never displays today', rows: byPrefix('gp') },
  { key: 'gg', title: 'E — Committed to a goal', when: 'Shown when the participant plans to drink and accepts a limit.',
    status: 'never displays today', rows: byPrefix('gg') },
];

// 60 situational messages + the opening line, the "not drinking next week" reply, and the week-12
// sign-off. Matches the 63 rows in message-library.tsv; asserted so the count in the prose cannot
// drift from the content.
const total = GROUPS.reduce((n, g) => n + g.rows.length, 0) + 3;
if (total !== rows.length) throw new Error(`message count ${total} != ${rows.length} rows in TSV`);

const groupHtml = GROUPS.map((g) => `
  <h3>${esc(g.title)} <span class="cnt">${g.rows.length} messages</span></h3>
  <p class="when">${g.when} <span class="flag">Currently: ${esc(g.status)}</span></p>
  <table class="msgs">
    <thead><tr><th>Week</th><th>Message</th></tr></thead>
    <tbody>${g.rows.map((r) => `<tr><td class="wk">${r.field.split('_')[1]}</td><td>${esc(r.text)}</td></tr>`).join('')}</tbody>
  </table>`).join('');

const img = fs.readFileSync(path.join(HERE, 'sunday-crop.png')).toString('base64');

// ---- document ----------------------------------------------------------------------------------
const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><style>
  @page { size: Letter; }
  * { box-sizing: border-box; }
  body { font: 10.5pt/1.55 "Charter","Georgia",serif; color: #1c1c1e; margin: 0; }
  h1 { font: 700 21pt/1.25 "Helvetica Neue",Arial,sans-serif; margin: 0 0 4pt; letter-spacing: -.3pt; }
  h2 { font: 700 13pt/1.3 "Helvetica Neue",Arial,sans-serif; margin: 20pt 0 7pt;
       padding-bottom: 4pt; border-bottom: 1.5px solid #8c1515; color: #8c1515; }
  h3 { font: 700 10.5pt/1.3 "Helvetica Neue",Arial,sans-serif; margin: 15pt 0 2pt; }
  p { margin: 0 0 7pt; }
  .sub { font: 400 10.5pt/1.4 "Helvetica Neue",Arial,sans-serif; color: #5b5b60; margin-bottom: 14pt; }
  .rule { height: 3px; background: #8c1515; margin: 0 0 13pt; }
  /* Tint plus a hairline border, and nothing else. The maroon is already carried by the rule above
     this box, the headings and the bold lead-ins inside it; a heavy left bar on top of that was a
     third cue doing the same job. */
  .tldr { background: #fbf6f6; border: 1px solid #e3cccc; padding: 11pt 13pt 6pt; margin: 0 0 14pt; }
  .tldr p { margin: 0 0 6pt; }
  .tldr b { color: #8c1515; }
  ol, ul { margin: 0 0 8pt; padding-left: 17pt; }
  li { margin-bottom: 5pt; }
  .step { font-weight: 700; }
  code { font: 9pt "SF Mono",Menlo,monospace; background: #f2f2f4; padding: .5pt 3pt; border-radius: 2pt; }
  table { border-collapse: collapse; width: 100%; margin: 0 0 10pt; font-size: 9.5pt; }
  th, td { text-align: left; padding: 4.5pt 7pt; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
  th { font: 700 8.5pt "Helvetica Neue",Arial,sans-serif; text-transform: uppercase;
       letter-spacing: .4pt; color: #5b5b60; border-bottom: 1.5px solid #c9c9cf; }
  .msgs td.wk { width: 34pt; text-align: center; font-weight: 700; color: #8c1515; }
  .cnt { font: 400 8.5pt "Helvetica Neue",Arial,sans-serif; color: #7a7a80; }
  .when { font: 400 9pt/1.4 "Helvetica Neue",Arial,sans-serif; color: #5b5b60; margin: 0 0 6pt; }
  .flag { color: #a33; font-style: italic; }
  /* The figure is deliberately under a page break (see the .page before section 1) and sized so
     that it, its caption and the two paragraphs that explain it share one page. Letting it flow
     after the summary box instead pushed it whole onto the next page and left a half-empty one. */
  figure { margin: 8pt 0 12pt; page-break-inside: avoid; }
  figure img { width: 86%; display: block; margin: 0 auto; border: 1px solid #d4d4d8; }
  figcaption { font: 400 8.5pt/1.4 "Helvetica Neue",Arial,sans-serif; color: #5b5b60; margin-top: 5pt; }
  .decision { border: 1px solid #d4d4d8; border-radius: 3pt; padding: 10pt 12pt 4pt; margin: 0 0 10pt;
              page-break-inside: avoid; }
  .decision h3 { margin-top: 0; }
  /* A full hairline in the blue family rather than a left bar: this sits inside an already-bordered
     decision card, so it needs to read as a distinct inset, and a closed box does that without
     repeating the card's own edge treatment on one side only. */
  .ask { background: #f4f7fb; border: 1px solid #cfdcea; border-radius: 2pt; padding: 7pt 10pt;
         margin: 7pt 0 0; font: 400 9.5pt/1.45 "Helvetica Neue",Arial,sans-serif; }
  .ok { color: #1d6b32; font-weight: 700; }
  .scope { font: 400 9pt/1.45 "Helvetica Neue",Arial,sans-serif; color: #5b5b60;
           border-left: 2px solid #c9c9cf; padding-left: 9pt; margin: 0 0 4pt; }
  .scope b { color: #3a3a3e; }
  .page { page-break-before: always; }
</style></head><body>

<h1>MICA Arm 3 — the weekly text messages</h1>
<div class="sub">What we can check today, and two decisions we need from you &nbsp;·&nbsp; ${DATE}</div>
<div class="rule"></div>

<div class="tldr">
  <p><b>The short version.</b> The weekly texts are not yet switched on — nothing in the study
  schedules them, so there is no “wait for the text to arrive” check we can run for you today.</p>
  <p>When we opened the weekly message survey as a participant would see it, REDCap stopped it with
  an error and refused to let the page continue. The survey is still carrying settings from the
  earlier ASPIRE/TRAM study it was copied from.</p>
  <p><b>The good news:</b> texting itself works. This project already sends real SMS to real phones,
  and we have confirmed Arm 3 keeps to its own schedule. What is missing is the weekly
  <i>cadence</i> — and that needs two decisions from you before we can build it.</p>
  <p><b>What we would like from you:</b> the two answers in section 3, and — whenever suits — a read of
  the ${total} messages in the appendix. That review is genuinely on the critical path and does not
  depend on any of the technical work.</p>
</div>

<p class="scope"><b>Which copy of the study this describes.</b> Everything below was checked on the
development copy of MICA that we build and test against. The copy on the Stanford server is set up
separately — some changes have to be re-applied there by hand — so we are re-running these same
checks there and will confirm or correct this document. We do not expect a different answer, but we
would rather tell you that up front than have you read this as a report on the server copy.</p>

<div class="page"></div>
<h2>1 &nbsp;What the weekly survey looks like right now</h2>

<p>This is the real participant link for a test Arm 3 participant, opened in a browser. Nothing is
staged or simulated:</p>

<figure>
  <img src="data:image/png;base64,${img}" alt="The weekly SMS survey as it renders today">
  <figcaption><b>Top of the weekly survey, as a participant would see it today.</b> Four things to
  notice: the red box is REDCap refusing to run the survey; the greeting reads
  “Hi <b>_____</b>” because the name is being looked up in a study event that no longer exists; the
  page opens with “It’s the <b>TRAM</b> Team checking in”; and the encouraging message
  (“You stayed within lower-risk drinking levels”) is stacked directly on top of the concerned one
  (“This week’s drinking was in the range considered unhealthy”).</figcaption>
</figure>

<p>All 24 feedback messages display at the same time — both the “you stayed within your limits”
set and the “this week was above healthy limits” set. We tried entering 10 drinks to see whether
the right set would be picked; nothing changed. The comparison the survey uses is looking for a
number that is no longer where it expects it, so it cannot tell the two situations apart.</p>

<p>Separately, 37 of the messages — the whole “drank nothing this week” set and both goal-setting
sets — never appear at all. They are set to show in “week 1” through “week 12”, and Arm 3
currently has a single combined “Weeks 1–12” period rather than twelve separate weeks. That is the
heart of decision A below.</p>

<h2>2 &nbsp;What already works, so you know where the gap is</h2>

<table>
  <thead><tr><th>Capability</th><th>Status</th></tr></thead>
  <tbody>
    <tr><td>A text message leaves the study and arrives on a real phone</td><td class="ok">Confirmed</td></tr>
    <tr><td>Participant phone numbers are accepted as text recipients</td><td class="ok">Confirmed</td></tr>
    <tr><td>Arm 3 keeps to its own schedule — no messages leak to other arms</td><td class="ok">Confirmed</td></tr>
    <tr><td>The passcode text and the opt-out confirmation (both Arm 3)</td><td class="ok">Clean and MICA-branded</td></tr>
    <tr><td>Weekly message content</td><td>Written — <b>needs your review</b></td></tr>
    <tr><td>Something that sends the weekly message</td><td><b>Not built — needs decisions A &amp; B</b></td></tr>
    <tr><td>The weekly survey itself</td><td><b>Needs rewiring after A is answered</b></td></tr>
  </tbody>
</table>

<p>In other words: the pipes are in and tested. What is missing is the timetable, and the timetable
is a study-design question rather than a technical one — which is why we are asking you rather
than guessing.</p>

<h2>3 &nbsp;The two decisions we need</h2>

<div class="decision">
  <h3>Decision A — How should the 12 weekly messages sit in the study calendar?</h3>
  <p>Arm 3 currently has one combined “Weeks 1–12” period, so there is exactly one slot for twelve
  messages. The earlier ASPIRE study used twelve separate weekly slots instead.</p>
  <p>This matters because the messages you will read in the appendix are written as
  <i>twelve different messages</i> — a distinct note for week 1, week 2, and so on. That design
  only works if there are twelve slots to put them in.</p>
  <div class="ask"><b>What we need:</b> confirm that each of the 12 weeks should carry its own
  distinct message (our reading of how the content is written), or tell us the same message may
  repeat. Your answer determines how we rebuild the survey, so we would rather not assume.</div>
</div>

<div class="decision">
  <h3>Decision B — Once a week, or twice a week?</h3>
  <p>ASPIRE texted participants twice weekly — a Sunday check-in and a Thursday message, 24 sends
  over 12 weeks. MICA has only the Sunday form built.</p>
  <div class="ask"><b>What we need:</b> Sunday only (12 sends), or Sunday plus a midweek message
  (24 sends)? If it is the latter, we will also need the midweek message content.</div>
</div>

<div class="decision">
  <h3>One thing to be aware of — opting out of texts</h3>
  <p>If a participant replies STOP, the phone carrier blocks further texts immediately, so the
  participant <i>is</i> protected. But REDCap is never told, so the study record still shows them as
  receiving texts. Today, someone on the study team has to tick the opt-out box by hand.</p>
  <div class="ask"><b>No decision needed now</b> — but it is worth adding to the coordinator’s
  routine: on any STOP report, tick the opt-out box on the participant’s admin form.</div>
</div>

<h2>4 &nbsp;What happens after you answer</h2>
<ol>
  <li>We set up the weekly slots in Arm 3 to match your answer to A.</li>
  <li>We rewire the weekly survey onto those slots, fix the greeting so it uses the participant’s
      real first name, and make the feedback pick the correct message instead of showing all of
      them.</li>
  <li>We change “TRAM Team” to “MICA Team” and give the survey a proper title.</li>
  <li>We add the scheduler that actually sends the weekly message, with the same protections the
      other MICA messages already use — nothing goes out to a participant who has withdrawn or
      opted out.</li>
  <li><b>Then</b> we run the delivery test you originally asked for, on a real handset, and send
      you the result.</li>
</ol>
<p>Steps 1–4 are ours. Only step 1 waits on you.</p>

<h2>5 &nbsp;If you would like to see it live</h2>
<p>We would normally give you a link to click, but the survey in section 1 lives on a development
server that is only reachable from inside our setup — a link would simply fail to open on your
machine, which would tell you nothing useful.</p>
<p>So instead: <b>say the word and we will screen-share it with you</b>, in about ten minutes. We
can scroll through the live survey, show the stacked messages and the blank greeting, and try any
“what if we answer it this way” you would like to see. That is also a good moment to walk through
the two decisions in section 3 if you would rather talk them through than write them down.</p>

<div class="page"></div>
<h2>Appendix &nbsp;The weekly message library — ${total} messages for your review</h2>
<p>This is the exact wording currently stored in the study, grouped by the situation that triggers
each message. The “Week” column is the week the message is written for. Reviewing this does not
depend on any of the technical work above, so it can happen in parallel — and it is the piece only
you can sign off.</p>

<h3>Opening line <span class="cnt">every week</span></h3>
<p class="when"><span class="flag">Currently renders as “Hi _____” — the first name is not being
found.</span></p>
<table class="msgs"><tbody>
  <tr><td class="wk">—</td><td>${esc(one('sun_sms_start'))}</td></tr>
</tbody></table>

<h3>The two questions asked each week</h3>
<table class="msgs"><tbody>
  <tr><td class="wk">1</td><td>How many total standard alcohol drinks did you have over the past 7 days?</td></tr>
  <tr><td class="wk">2</td><td>Are you planning on drinking alcohol this week? → if yes: Would you be willing to commit to a goal to drink less than [your threshold]?</td></tr>
</tbody></table>

${groupHtml}

<h3>Closing messages</h3>
<table class="msgs"><tbody>
  <tr><td class="wk">—</td><td><b>After declining to drink next week:</b> ${esc(one('no_plan'))}</td></tr>
  <tr><td class="wk">12</td><td><b>End of the programme:</b> ${esc(one('sun_end_week_12'))}</td></tr>
</tbody></table>

</body></html>`;

// Page furniture goes through Playwright rather than a position:fixed element — a fixed footer is
// painted once per page by Chromium's print path and lands on top of any tall figure.
const FOOTER = `<div style="width:100%;font:7.5pt 'Helvetica Neue',Arial,sans-serif;color:#8a8a90;
  padding:0 16mm;display:flex;justify-content:space-between;">
  <span>MICA Arm 3 weekly SMS — PI review — ${DATE}</span>
  <span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>`;

(async () => {
  const out = path.join(HERE, 'MICA_Arm3_Weekly_SMS_PI_Review.pdf');
  const b = await chromium.launch();
  const p = await b.newPage();
  await p.setContent(html, { waitUntil: 'load' });
  await p.pdf({
    path: out, format: 'Letter', printBackground: true,
    displayHeaderFooter: true, headerTemplate: '<span></span>', footerTemplate: FOOTER,
    margin: { top: '18mm', bottom: '16mm', left: '16mm', right: '16mm' },
  });
  await b.close();
  console.log(`wrote ${out} (${(fs.statSync(out).size / 1024).toFixed(0)} KB, ${total} messages)`);
})();
