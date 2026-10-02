// Critical finding -> CRC email within 5 minutes of End Session (PID 271).
// See docs/phase-3-handoff/31-critical-finding-crc-notify.md.
//
//   docker run -d --rm --name mica_mailpit --network redcap_2023_1_redcap_network \
//       --network-alias mailhog -p 18025:8025 axllent/mailpit:latest        # the mail sink
//   docker exec -w /var/www/html <web> php modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts/e2e-crc-reviewer.php 271 setup
//   docker exec -w /var/www/html <web> php modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts/seed-rand-test.php 271 CRIT01
//   node e2e/critical-notify.js CRIT01 <tsr survey link for CRIT01>
//   ... e2e-crc-reviewer.php 271 teardown; docker stop mica_mailpit
//
// The tsr link is optional: with it, the participant submits `tsr` first and the trigger randomizes
// the record. A Standard Care allocation has no session by design, so the script stops there and
// says so - seed another record.
//
// The session is behind Survey Login on last name, which a seeded record does not have (a real
// participant types it on `contact_info`). Without it REDCap shows "no login fields can be displayed"
// and the chat never opens, so the script stores one through REDCap::saveData() when it is missing.
//
// Who the email goes to (2026-10-02). The notice goes to the Reviewer role, or to the Reviewer
// notification addresses (`notify-reviewer-emails`) when that setting is filled in. This script looks
// for MICA_CRC_EMAIL (default crc-e2e@example.org) in the sink, so leave that setting blank for the
// run, include the CRC address in it, or set MICA_CRC_EMAIL to a named address. e2e-crc-reviewer.php
// setup prints who the notice will reach and warns when it is not the CRC.
//
// The mail sink is not optional. Local msmtp points at `mailhog:1025`, which does not resolve unless
// something answers to that name, and without it REDCap::email() fails: the notice row then reads
// `failed`, which looks like a module bug and is not one.
//
// What "notified" means here, and why the clock starts where it does. The requirement is that a CRC
// hears about a critical finding within 5 minutes of the session ending. The clock starts at the
// participant's End Session confirm and stops when the email reaches the CRC's mailbox (the sink's
// receipt time), so it includes the cron wait, the scan itself and delivery. Every step between is
// read from the database and printed as a timeline, so a miss says which step used the time.
//
// Failure shapes this is built to tell apart. `manual_review_required` also emails the CRC, often
// inside 5 minutes, with "could not be screened" - a notice, but not the one the requirement asks
// for, so it FAILS here. So does a `citation_mismatch` or `refusal`, which go straight to that path.
const { chromium, devices } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const MAILPIT = process.env.MICA_MAILPIT || 'http://localhost:18025';
const CRC = process.env.MICA_CRC_EMAIL || 'crc-e2e@example.org';
const LAST = process.env.MICA_E2E_LAST_NAME || 'Testerson';
const LIMIT_S = 300;
const WAIT_S = Number(process.env.MICA_WAIT_SECONDS || 720);
const PID = 271;
const CHAT = '#chatbot_ui_container[data-bootstrap]';

const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

const RECORD = process.argv[2];
const TSR_LINK = process.argv[3];
if (!RECORD) { console.error('usage: node e2e/critical-notify.js <record> [tsr survey link]'); process.exit(2); }
const R = RECORD.replace(/'/g, '');

// Synthetic, and unambiguous on purpose: the test is about the pipeline, not the model's judgement.
// MICA_TURNS=runbook uses the six messages the prod runbook (docs 32, step 6) tells staff to type, so a
// failure on prod can be replayed here word for word.
const TURN_SETS = {
  short: [
    'Hi. Honestly this week has been really bad. I have been drinking most of a bottle of vodka every day.',
    'I do not want to be alive anymore. I have saved up a full bottle of my sleeping pills and I am going to take all of them tonight when I get home.',
  ],
  runbook: [
    'I got brought into the ED last night after drinking.',
    'I drink most of a bottle of vodka most days now.',
    'I lost my job last month and my partner moved out.',
    "Honestly I don't see the point of anything anymore.",
    "I've been saving my sleeping pills. I have a full bottle at home.",
    "I'm going to take all of them tonight when I get home.",
  ],
};
const TURNS = TURN_SETS[process.env.MICA_TURNS || 'short'];
if (!TURNS) { console.error(`MICA_TURNS must be one of: ${Object.keys(TURN_SETS).join(', ')}`); process.exit(2); }

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

const sql = (q) => execSync(
  `docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -N -e ${JSON.stringify(q)} 2>/dev/null`,
).toString().trim();
const rows = (q) => sql(q).split('\n').filter(Boolean).map(l => l.split('\t'));

const mail = async () => {
  const r = await fetch(`${MAILPIT}/api/v1/messages?limit=200`);
  if (!r.ok) throw new Error(`mail sink answered ${r.status} at ${MAILPIT}`);
  return ((await r.json()).messages || []).filter(m => (m.To || []).some(t => t.Address === CRC));
};

// Only this record's "findings ready" or "could not be screened" notice counts. The CRC's mailbox also
// gets the acknowledgement monitor's reminders about EARLIER sessions ("N finding(s) past the
// 60-minute acknowledgment window"), and taking one of those for this session's notice once passed A8
// and A9 while the scan was still failing.
const noticeFor = async (seen) => {
  for (const m of (await mail()).filter(x => !seen.has(x.ID))) {
    if (!/ready for review|could not be screened/.test(m.Subject)) continue;
    const full = await (await fetch(`${MAILPIT}/api/v1/message/${m.ID}`)).json();
    // The text part has CRLF line endings, so `\r?\n`; the line end also stops CRIT01 matching CRIT010.
    const escaped = RECORD.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    if (new RegExp(`Record: ${escaped}(\\r?\\n|$)`).test(full.Text)) return m;
  }
  return null;
};

const composer = p => p.locator(`${CHAT} textarea, ${CHAT} input:not([type=hidden]), ${CHAT} [contenteditable]`).first();

/**
 * Send one turn and wait for the model's answer to come back, rather than for a fixed time.
 *
 * HTTP 200 is not an answer. When the provider fails, SecureChatAI rewrites the failure into a polite
 * assistant message and MICA returns it with `provider_error: true` - still a 200. A run with the AI
 * Hub unreachable once "answered" every turn this way.
 */
async function say(p, text) {
  await composer(p).fill(text);
  const answered = p.waitForResponse(r => /prefix=proj_mica/.test(r.url()) && /callAI/.test(r.request().postData() || ''),
    { timeout: 120000 }).catch(() => null);
  const btns = p.locator(`${CHAT} button`);
  await btns.nth((await btns.count()) - 1).click({ force: true });
  const r = await answered;
  await p.waitForTimeout(1500);
  if (!r) return { ok: false, detail: 'no callAI response' };
  const body = await r.text().catch(() => '');
  // MICA's result is itself JSON-encoded inside the framework's response, so its quotes arrive escaped.
  const failed = /\\?"provider_error\\?"\s*:\s*true/.test(body);
  return { ok: r.status() === 200 && !failed, detail: failed ? 'provider_error: the model did not answer' : `HTTP ${r.status()}` };
}

(async () => {
  // The sink has to be up before anything is spent: a randomized record cannot be re-run.
  const before = new Set((await mail()).map(m => m.ID));
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });

  // ---- R: randomize (optional) -------------------------------------------------------------
  if (TSR_LINK) {
    console.log(`\nR  participant submits tsr (${RECORD})`);
    const p = await (await browser.newContext({ viewport: { width: 1400, height: 950 } })).newPage();
    await p.goto(TSR_LINK, { waitUntil: 'networkidle' });
    await p.locator('input[name="alc_tx___radio"][value="0"]').check();
    await Promise.all([p.waitForLoadState('load'), p.locator('button[name="submit-btn-saverecord"]').click()]);
    await p.waitForLoadState('networkidle');
    await p.close();
  }
  const group = sql(`SELECT value FROM redcap_data7 WHERE project_id=${PID} AND record='${R}' AND field_name='study_group' LIMIT 1`);
  const link = sql(`SELECT value FROM redcap_data7 WHERE project_id=${PID} AND record='${R}' AND field_name='ed_session_url' LIMIT 1`);
  if (group === '1') { console.log(`  ${RECORD} was allocated Standard Care, which has no session. Seed another record.`); process.exit(3); }
  check('R1 record is in a session arm with a session link', /^[23]$/.test(group) && /^https?:\/\//.test(link), `group ${group}`);
  if (!/^https?:\/\//.test(link)) { await browser.close(); process.exit(1); }

  // ---- S: the session ------------------------------------------------------------------------
  if (!sql(`SELECT value FROM redcap_data7 WHERE project_id=${PID} AND record='${R}' AND field_name='last_name' LIMIT 1`)) {
    const row = JSON.stringify([{ record_id: RECORD, redcap_event_name: 'day_1_ed_arm_1', last_name: LAST }]);
    execSync(`docker exec -w /var/www/html redcap_2023_1_web php -r '$_GET["pid"]=${PID}; define("NOAUTH",true); require "redcap_connect.php"; `
      + `$r = REDCap::saveData(["project_id"=>${PID}, "dataFormat"=>"json", "data"=>base64_decode("${Buffer.from(row).toString('base64')}")]); `
      + `if ($r["errors"]) { fwrite(STDERR, json_encode($r["errors"])); exit(1); }'`);
  }
  console.log(`\nS  participant session (${RECORD}, arm ${group})`);
  const p = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await p.goto(link, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(2500);
  const login = p.locator('input[name="last_name"]').first();
  if (await login.count()) {
    await login.fill(LAST);
    await p.getByRole('button', { name: /log ?in/i }).first().click();
    await p.waitForLoadState('domcontentloaded');
    await p.waitForTimeout(4000);
  }
  check('S1 the chat opens', await composer(p).count() > 0);
  for (const [i, t] of TURNS.entries()) {
    const { ok, detail } = await say(p, t);
    check(`S${i + 2} turn ${i + 1} answered by the model`, ok, detail);
  }
  await p.screenshot({ path: `${SHOTS}/critical-notify-session.png`, fullPage: true });

  await p.locator('button.end_session').first().click();
  await p.waitForTimeout(1200);
  const t0 = Date.now();
  await p.locator('button', { hasText: /^End session$/ }).first().click();
  await p.waitForTimeout(6000);
  await browser.close();

  // ---- T: the clock ----------------------------------------------------------------------------
  console.log(`\nT  End Session confirmed at ${new Date(t0).toISOString()}; watching for up to ${WAIT_S}s`);
  const seen = new Set();
  const mark = (key, text) => { if (!seen.has(key)) { seen.add(key); console.log(`     +${String(Math.round((Date.now() - t0) / 1000)).padStart(3)}s  ${text}`); } };
  let job, notice, email;
  while (Date.now() - t0 < WAIT_S * 1000) {
    [job] = rows(`SELECT id, status, attempts, created FROM redcap_entity_mica_scan_job WHERE project_id=${PID} AND record='${R}' ORDER BY id DESC LIMIT 1`);
    if (job) {
      mark(`job:${job[1]}:${job[2]}`, `scan job ${job[0]}: ${job[1]} (attempts ${job[2]})`);
      for (const [id, st, ms] of rows(`SELECT id, run_status, latency_ms FROM redcap_entity_mica_scan_run WHERE job_id=${job[0]} ORDER BY id`)) {
        mark(`run:${id}`, `scan run ${id}: ${st} (${ms} ms at the provider)`);
      }
      [notice] = rows(`SELECT id, status, recipient_count, IFNULL(subject,''), IFNULL(error,''), sent_at FROM redcap_entity_mica_notification WHERE project_id=${PID} AND record='${R}' AND notification_type='reviewers_ready' ORDER BY id DESC LIMIT 1`);
      if (notice) mark(`notice:${notice[1]}`, `reviewers_ready notice: ${notice[1]} to ${notice[2]} recipient(s)${notice[4] ? ' — ' + notice[4] : ''}`);
    }
    email = await noticeFor(before);
    if (email) { mark('email', `email in ${CRC}'s mailbox: "${email.Subject}"`); break; }
    if (notice && notice[1] !== 'sent') break;
    await new Promise(r => setTimeout(r, 5000));
  }

  // ---- A: assertions -------------------------------------------------------------------------
  console.log('\nA  outcome');
  check('A1 a scan job was queued at End Session', !!job);
  check('A2 the scan finished ready_for_review (not manual review)', job && job[1] === 'ready_for_review', job ? job[1] : 'no job');
  // model_output_json is the attempt's wrapper (ScanRunner::runPayload); the model's answer is inside it.
  const [run] = job ? rows(`SELECT run_status, JSON_UNQUOTE(JSON_EXTRACT(model_output_json, '$.model_output.overall_urgency')), IFNULL(JSON_EXTRACT(model_output_json, '$.provider_schema_withheld'), '') FROM redcap_entity_mica_scan_run WHERE job_id=${job[0]} ORDER BY id DESC LIMIT 1`) : [];
  check('A3 the last scan run is ok', run && run[0] === 'ok', run ? run[0] : 'no run');
  check('A4 the model rated the session critical', run && run[1] === 'critical', run ? run[1] : '-');
  check('A4b the run row names what the provider was not sent', run && /uniqueItems/.test(run[2]), run ? run[2] || 'nothing recorded' : '-');
  const critical = sql(`SELECT COUNT(*) FROM redcap_data7 WHERE project_id=${PID} AND record='${R}' AND field_name='finding_urgency' AND value='critical'`);
  check('A5 a critical finding is on the record for review', Number(critical) >= 1, `${critical} instance(s)`);
  check('A6 the reviewers_ready notice was sent', notice && notice[1] === 'sent' && Number(notice[2]) >= 1, notice ? `${notice[1]}, ${notice[2]} recipient(s)` : 'none');
  check('A7 its subject says critical', notice && /highest urgency: critical/.test(notice[3]), notice ? notice[3] : '-');
  check(`A8 the email reached ${CRC}`, !!email, email ? email.Subject : 'nothing in the sink');
  const took = email ? Math.round((Date.parse(email.Created) - t0) / 1000) : null;
  check(`A9 End Session -> CRC email within ${LIMIT_S}s`, took !== null && took <= LIMIT_S, took === null ? 'no email' : `${took}s`);

  // ---- M: the email as the CRC reads it, desktop and phone -----------------------------------
  if (email) {
    console.log('\nM  the email');
    const full = await (await fetch(`${MAILPIT}/api/v1/message/${email.ID}`)).json();
    check('M1 the body names the record and the dashboard', full.Text.includes(`Record: ${RECORD}`) && /prefix=proj_mica/.test(full.Text));
    // REDCap derives the text part itself, rewriting each anchor to "URL (URL)"; the link's wrap style must not break that.
    const pair = full.Text.match(/(https?:\/\/[^\s()]+) \((https?:\/\/[^\s()]+)\)/);
    check('M1b the plain-text part reads the link as "URL (URL)"', !!pair && pair[1] === pair[2], pair ? pair[0].slice(0, 60) : 'no link');
    check('M2 the body carries no participant words', !TURNS.some(t => full.Text.includes(t.slice(0, 40))) && !/pills|vodka/i.test(full.Text));
    const b = await chromium.launch();
    for (const mobile of [false, true]) {
      const ctx = await b.newContext(mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1000, height: 700 } });
      const mp = await ctx.newPage();
      await mp.setContent(`<!doctype html><meta name="viewport" content="width=device-width"><body style="font-family:sans-serif;margin:16px"><h3 style="margin:0 0 12px">${email.Subject.replace(/</g, '&lt;')}</h3>${full.HTML}</body>`);
      const overflow = await mp.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      await mp.screenshot({ path: `${SHOTS}/critical-notify-email-${mobile ? 'mobile' : 'desktop'}.png`, fullPage: true });
      check(`M${mobile ? 4 : 3} ${mobile ? 'phone' : 'desktop'}: no horizontal scroll`, overflow <= 0, `${overflow}px`);
      await ctx.close();
    }
    await b.close();
  }

  console.log(`\n  ${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
