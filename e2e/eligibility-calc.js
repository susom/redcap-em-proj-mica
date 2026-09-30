// Screening-eligibility E2E against a project built from the PI's REDCap XML.
// See docs/screening/ELIGIBILITY_CALC_PI_XML_2026-09-25.md.
//
//   docker exec <web> php .../scripts/e2e-admin-form-user.php 271 setup --create-projects
//   node e2e/eligibility-calc.js create <xml>       # builds the project, prints its pid
//   node e2e/eligibility-calc.js run <pid> broken    # reproduce: what a participant sees today
//   node e2e/eligibility-calc.js fix <pid> docs/screening/calc_screen_result.txt
//                                                   # paste the equation in the Online Designer
//   node e2e/eligibility-calc.js run <pid> fixed     # every screening path, after the fix
//   node e2e/eligibility-calc.js gate <pid>          # pre_screen auto-continue condition (Survey Settings)
//   node e2e/eligibility-calc.js run <pid> gated     # the stop-action cases again, after the gate
//   node e2e/eligibility-calc.js wrongarm <pid> 2    # screen through arm 2's public link
//   node e2e/eligibility-calc.js walk <pid> '<answers json>' <tag> [expect-end]
//                                                   # one participant, reported - any structure
//   node e2e/eligibility-calc.js stops <pid> [leak]  # every pre_screen stop action ends the flow there
//                                                   # (`leak`: assert the unfixed behaviour instead)
//   docker exec <web> php .../scripts/e2e-admin-form-user.php 271 teardown
//
// MICA_XML_TAG=<tag> labels the project `create` builds (e.g. 1646 for the PI's 16:46 export).
//
// <xml> is the PI's export with <redcap:AlertsGroup> and <redcap:SurveysSchedulerGroup> removed, so a
// local run cannot send email or SMS; neither takes part in the screening flow.
//
// Everything goes through REDCap's own pages as a NON-super user: the New Project page, the public
// survey link a coordinator opens on the ED tablet, and the Online Designer the PI edits in. The
// stored result is read back from the database after every path, because the message on the page
// comes from the JavaScript copy of the equation and the stored value from the PHP copy; the two
// have to agree.
const { chromium, devices } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const BASE = process.env.MICA_BASE || 'http://redcap.local';
const USER = process.env.MICA_E2E_USER || 'e2e_admin_form';
const PASS = process.env.MICA_E2E_PASS || 'E2eAdminForm!2026';
const V = 'redcap_v17.2.3';

const SHOTS = path.join(__dirname, 'shots');
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

const sql = (q) => execSync(
  `docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -N -e ${JSON.stringify(q)} 2>/dev/null`,
).toString().trim();

async function login(p) {
  await p.goto(`${BASE}/${V}/index.php`, { waitUntil: 'domcontentloaded' });
  const user = p.locator('input[name="username"]').first();
  if (!(await user.count())) return true;
  await user.fill(USER);
  await p.locator('input[name="password"]').first().fill(PASS);
  // #login_btn: REDCap's button has an id and no type attribute, so the obvious selectors miss it.
  await p.locator('#login_btn').first().click();
  await p.waitForLoadState('domcontentloaded');
  await p.waitForTimeout(1200);
  return !(await p.locator('input[name="password"]').count());
}

// ---------------------------------------------------------------------------------------------
// create: New Project -> "Upload a REDCap project XML file"
// ---------------------------------------------------------------------------------------------
async function create(xml) {
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();
  p.on('dialog', (d) => d.accept());
  check('signed in as the throwaway (non-super) user', await login(p));
  await p.goto(`${BASE}/index.php?action=create`, { waitUntil: 'networkidle' });
  await p.fill('#app_title', `ELIG REPRO - PI XML ${process.env.MICA_XML_TAG || ''} ${new Date().toISOString().slice(0, 16)}`);
  await p.selectOption('#purpose', '0'); // Practice / just for fun
  await p.check('#project_template_radio2');
  await p.setInputFiles('input[name="odm"]', xml);
  await p.waitForTimeout(1500); // the page reads the file's header before it enables Create
  await p.screenshot({ path: `${SHOTS}/elig-create-form.png`, fullPage: true });
  // A file WITH records takes minutes to import; closing the browser early aborts REDCap's request.
  // But REDCap can also answer with a page of errors instead of redirecting, so don't wait blind:
  // stop as soon as a page that is not the create form has loaded, and report what it says.
  await p.click('#createProjectBtn');
  const deadline = Date.now() + Number(process.env.MICA_CREATE_TIMEOUT || 900000);
  while (Date.now() < deadline && !/pid=\d+/.test(p.url())) {
    await p.waitForTimeout(3000);
    const stillForm = await p.locator('#createProjectBtn').count().catch(() => 1);
    const loading = await p.evaluate(() => document.readyState !== 'complete').catch(() => true);
    if (!stillForm && !loading && !/pid=\d+/.test(p.url())) break;
  }
  const pid = new URL(p.url()).searchParams.get('pid');
  await p.screenshot({ path: `${SHOTS}/elig-create-done.png`, fullPage: true });
  if (!pid) console.log(`  create page said: ${(await p.innerText('body').catch(() => '')).replace(/\s+/g, ' ').slice(0, 600)}`);
  check('project created from the XML', /^\d+$/.test(pid || ''), p.url());
  await browser.close();
  console.log(`\nPID=${pid}`);
  return pid;
}

// ---------------------------------------------------------------------------------------------
// run: one participant per case, through the public survey link, as the ED tablet would be used
// ---------------------------------------------------------------------------------------------
const MSG = {
  eligible: '#desc_eligible-tr',
  ineligible: '#desc_ineligible-tr',
  hazard: '#desc_ineligible_hazard-tr',
  cannabis: '#cann_elig-tr',
};

// Pre-screen answers every case shares; a case overrides what it tests. s_room is the case marker
// the database is searched by, so it must be unique per run.
const BASE_ANSWERS = { s_cc: 'E2E eligibility', s_sex: '1', sc_age: '40', english: '1', prison: '0', goodcand: '1', md_assent: '1', s_interest: '1' };

// expect.calc is the stored value; expect.shows the eligibility messages on screen; expect.end
// where the participant lands: 'consent' (auto-continue) or 'ack' (thank-you page); expect.stop
// the instrument whose stop action fired, if any.
//
// A stop action does NOT end the chain in REDCap 17.2.3: it ends the current survey, and
// auto-continue still carries the participant to the next one (Surveys/index.php:2789 only skips
// auto-continue when stop_action_delete_response = 1; the PI's surveys have 0). So a stop case is
// walked to wherever it really ends, and `leak: true` marks a case whose ending is the finding, not
// the fix: it is asserted as observed so a regression - or a repair of the survey settings - shows.
const CASES = [
  { id: 'M-elig', why: 'male, AUDIT-C 8, phone', a: { days_dr: '3', typ_drink: '3', days_binge: '2', phone: '1' },
    expect: { calc: '1', shows: ['eligible'], end: 'consent' } },
  { id: 'F-elig', why: 'female, AUDIT-C 3 (her threshold), phone, not pregnant', a: { s_sex: '2', days_dr: '1', typ_drink: '1', days_binge: '1', phone: '1', preg: '0' },
    expect: { calc: '1', shows: ['eligible'], end: 'consent' } },
  { id: 'M-neg3', why: 'male, AUDIT-C 3 (one below his threshold), cannabis < monthly', a: { days_dr: '1', typ_drink: '1', days_binge: '1', cann: '2' },
    expect: { calc: '0', shows: ['ineligible'], end: 'ack' } },
  { id: 'F-neg-cann', why: 'female, AUDIT-C 2, cannabis weekly', a: { s_sex: '2', days_dr: '1', typ_drink: '1', days_binge: '0', cann: '4' },
    expect: { calc: '0', shows: ['ineligible', 'cannabis'], end: 'ack' } },
  { id: 'M-never', why: 'male, never drinks (AUDIT-C blank)', a: { days_dr: '0' },
    expect: { calc: '0', shows: ['ineligible'], end: 'ack' } },
  { id: 'M-age70', why: 'male, 70, AUDIT-C 8, phone (hazardous, too old)', a: { sc_age: '70', days_dr: '3', typ_drink: '3', days_binge: '2', phone: '1' },
    expect: { calc: '2', shows: ['hazard'], end: 'ack' } },
  { id: 'F-age17', why: 'female, 17, AUDIT-C 4, phone, not pregnant (hazardous, too young)', a: { s_sex: '2', sc_age: '17', days_dr: '2', typ_drink: '1', days_binge: '1', phone: '1', preg: '0' },
    expect: { calc: '2', shows: ['hazard'], end: 'ack' } },
  { id: 'F-preg', why: 'female, AUDIT-C 4, pregnant -> stop action on screen2', a: { s_sex: '2', days_dr: '2', typ_drink: '1', days_binge: '1', phone: '1', preg: '1' },
    expect: { calc: '2', shows: ['hazard'], end: 'ack', stop: 'screen2' } },
  { id: 'M-nophone', why: 'male, AUDIT-C 8, no phone -> stop action on screen2', a: { days_dr: '3', typ_drink: '3', days_binge: '2', phone: '0' },
    expect: { calc: '2', shows: ['hazard'], end: 'ack', stop: 'screen2' } },
  { id: 'M-prison', why: 'male, in prison, AUDIT-C 8, phone -> stop action on pre_screen', a: { prison: '1', days_dr: '3', typ_drink: '3', days_binge: '2', phone: '1' },
    expect: { calc: '2', shows: ['hazard'], end: 'ack', stop: 'pre_screen' } },
  // The leak: gates that exist only as stop actions and are not in the calc. `english` comes before
  // `prison` on the form, so its stop leaves prison blank and the calc's own blank-guard holds; the
  // gates after `prison` (goodcand, md_assent, s_interest) have no such luck.
  { id: 'M-noenglish', why: 'male, preferred language not English, otherwise eligible -> stop action on pre_screen', a: { english: '0', days_dr: '3', typ_drink: '3', days_binge: '2', phone: '1' },
    expect: { calc: '', shows: [], end: 'ack', stop: 'pre_screen', leak: true } },
  { id: 'M-notinterested', why: 'male, not interested in screening, otherwise eligible -> stop action on pre_screen', a: { s_interest: '0', days_dr: '3', typ_drink: '3', days_binge: '2', phone: '1' },
    expect: { calc: '1', shows: ['eligible'], end: 'consent', stop: 'pre_screen', leak: true } },
];

// After `gate`: pre_screen auto-continues only when every pre_screen gate passed, so a stop action on
// pre_screen really ends the flow there. Cases not listed keep their `expect`.
const GATED = {
  'M-prison': { calc: '', shows: [], end: 'ack', stop: 'pre_screen', pages: 'pre_screen > ack' },
  'M-noenglish': { calc: '', shows: [], end: 'ack', stop: 'pre_screen', pages: 'pre_screen > ack' },
  'M-notinterested': { calc: '', shows: [], end: 'ack', stop: 'pre_screen', pages: 'pre_screen > ack' },
};
const GATED_RUN = ['M-elig', 'F-elig', 'M-never', 'F-preg', 'M-prison', 'M-noenglish', 'M-notinterested'];

// What the PI's XML does today, before the fix: the equation box is empty, so every case stores
// a blank, no eligibility message shows, and nobody reaches consent.
const BROKEN = { calc: '', shows: [], end: 'ack' };

async function pageKind(p) {
  if (await p.locator('#surveyacknowledgment').count()) return 'ack';
  if (await p.locator('#agree_sud-tr').count()) return 'consent';
  // Not #desc_eligible-tr: the PI's 16:46 export moved that message to the top of `consent`.
  if (await p.locator('#desc_ineligible-tr').count()) return 'eligibility';
  if (await p.locator('#phone-tr').count()) return 'screen2';
  if (await p.locator('#days_dr-tr').count()) return 'auditc';
  if (await p.locator('#s_room-tr').count()) return 'pre_screen';
  return 'unknown';
}

/** Close REDCap's soft range-check notice ("outside the suggested range") if it opened. */
async function closeNotices(p) {
  const dlg = p.locator('.ui-dialog:visible').filter({ hasNot: p.locator('#stopActionPrompt') });
  if (await dlg.count()) {
    const btn = dlg.first().locator('.ui-dialog-buttonpane button, button.ui-dialog-titlebar-close').first();
    if (await btn.count()) await btn.click().catch(() => {});
  }
}

/** Answer every field of `answers` that is on this page and visible; returns true if a stop action ended it. */
async function answerPage(p, answers) {
  for (const [f, v] of Object.entries(answers)) {
    const row = p.locator(`#${f}-tr`);
    if (!(await row.count()) || !(await row.isVisible())) continue;
    const radio = p.locator(`input[name="${f}___radio"][value="${v}"]`);
    if (await radio.count()) {
      await radio.check();
      await p.waitForTimeout(150);
      const stop = p.locator('.ui-dialog:has(#stopActionPrompt)');
      if (await stop.isVisible().catch(() => false)) {
        await Promise.all([
          p.waitForLoadState('load'),
          stop.locator('.ui-dialog-buttonpane button', { hasText: 'End Survey' }).click(),
        ]);
        await p.waitForLoadState('networkidle');
        return true;
      }
    } else {
      await p.locator(`input[name="${f}"]`).fill(v);
      await p.keyboard.press('Tab');
      await p.waitForTimeout(250);
      await closeNotices(p);
    }
  }
  return false;
}

async function submitPage(p) {
  await Promise.all([p.waitForLoadState('load'), p.locator('button[name="submit-btn-saverecord"]').first().click()]);
  await p.waitForLoadState('networkidle');
}

/** Walk one participant from the public link to wherever the flow ends - through any stop action. */
async function walk(p, link, answers, shotTag) {
  await p.goto(link, { waitUntil: 'networkidle' });
  const seen = { shows: [], end: null, stop: null, screen2Blank: null, pages: [] };
  for (let guard = 0; guard < 12; guard++) {
    const kind = await pageKind(p);
    seen.pages.push(kind);
    if (kind === 'ack' || kind === 'consent') {
      seen.end = kind;
      // 16:46 structure: "Congratulations! You are eligible" opens the consent form.
      if (kind === 'consent' && await p.locator(MSG.eligible).isVisible().catch(() => false)) seen.shows.push('eligible (on consent)');
      await p.screenshot({ path: `${SHOTS}/elig-${shotTag}-end.png`, fullPage: true });
      return seen;
    }
    if (kind === 'eligibility') {
      for (const [name, sel] of Object.entries(MSG)) if (await p.locator(sel).isVisible()) seen.shows.push(name);
      seen.spill = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      await p.screenshot({ path: `${SHOTS}/elig-${shotTag}-result.png`, fullPage: true });
      await submitPage(p);
      continue;
    }
    if (kind === 'screen2') {
      seen.screen2Blank = !(await p.locator('#phone-tr').isVisible()) && !(await p.locator('#preg-tr').isVisible());
      if (seen.screen2Blank) await p.screenshot({ path: `${SHOTS}/elig-${shotTag}-screen2.png`, fullPage: true });
    }
    if (kind === 'unknown') {
      seen.end = 'unknown';
      seen.unknownText = (await p.innerText('body').catch(() => '')).replace(/\s+/g, ' ').trim().slice(0, 240);
      await p.screenshot({ path: `${SHOTS}/elig-${shotTag}-unknown.png`, fullPage: true });
      return seen;
    }
    if (await answerPage(p, answers)) {
      // "End Survey" already submitted the page; carry on from wherever REDCap sent us.
      seen.stop = kind;
      await p.screenshot({ path: `${SHOTS}/elig-${shotTag}-after-stop.png`, fullPage: true });
      continue;
    }
    await submitPage(p);
  }
  seen.end = 'loop';
  return seen;
}

// Each project's data lives in its own redcap_dataN table (271/272: redcap_data7, 279: redcap_data5).
const dataTables = {};
const dataTable = (pid) => (dataTables[pid] ||= sql(`SELECT data_table FROM redcap_projects WHERE project_id=${Number(pid)}`));

function stored(pid, marker) {
  const rec = sql(`SELECT record FROM ${dataTable(pid)} WHERE project_id=${pid} AND field_name='s_room' AND value='${marker}' LIMIT 1`);
  const get = (f) => sql(`SELECT value FROM ${dataTable(pid)} WHERE project_id=${pid} AND record='${rec}' AND field_name='${f}' LIMIT 1`);
  return { rec, calc: get('calc_screen_result'), audit: get('audit_c_score') };
}

async function publicLink(browser, pid) {
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();
  await login(p);
  // Survey Distribution Tools creates the public link on first visit, as it does for a coordinator.
  await p.goto(`${BASE}/${V}/Surveys/invite_participants.php?pid=${pid}`, { waitUntil: 'networkidle' });
  const link = await p.locator('#longurl').inputValue();
  await ctx.close();
  return link;
}

async function run(pid, mode) {
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const link = await publicLink(browser, pid);
  check('public survey link from Survey Distribution Tools', /\/surveys\/\?s=\w+/.test(link), link);
  const stamp = Date.now().toString(36);
  const cases = mode === 'broken' ? CASES.filter((c) => ['M-elig', 'F-elig', 'F-neg-cann', 'M-never'].includes(c.id))
    : mode === 'gated' ? CASES.filter((c) => GATED_RUN.includes(c.id)) : CASES;
  for (const c of cases) {
    // cann_elig branches on [cann], not on the calc, so it shows even while the calc is broken.
    const exp = mode === 'broken' ? { ...BROKEN, shows: c.expect.shows.filter((m) => m === 'cannabis') }
      : mode === 'gated' && GATED[c.id] ? { ...GATED[c.id], leak: false } : c.expect;
    for (const mobile of mode !== 'fixed' ? [false] : [false, ...(['M-elig', 'F-neg-cann', 'M-age70'].includes(c.id) ? [true] : [])]) {
      const tag = `${mode}-${c.id}${mobile ? '-mobile' : ''}`;
      const marker = `E2E-${c.id}-${stamp}${mobile ? 'm' : ''}`;
      console.log(`\n${tag}: ${c.why}`);
      const ctx = await browser.newContext(mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } });
      const p = await ctx.newPage();
      // s_room first: it is the marker the record is found by, and a stop action submits the page
      // the moment it fires, with whatever has been answered so far.
      const seen = await walk(p, link, { s_room: marker, ...BASE_ANSWERS, ...c.a }, tag);
      await ctx.close();
      const s = stored(pid, marker);
      const lk = exp.leak ? ' [LEAK - observed, not wanted]' : '';
      check(`${tag}  stored calc_screen_result = '${exp.calc}'${lk}`, s.calc === exp.calc, `record ${s.rec}: '${s.calc}' (audit_c_score '${s.audit}')`);
      check(`${tag}  messages shown: [${exp.shows}]${lk}`, JSON.stringify(seen.shows) === JSON.stringify(exp.shows), `[${seen.shows}]`);
      check(`${tag}  flow ends at ${exp.end}${lk}`, seen.end === exp.end, `${seen.pages.join(' > ')}`);
      if (exp.pages) check(`${tag}  pages walked: ${exp.pages}`, seen.pages.join(' > ') === exp.pages, seen.pages.join(' > '));
      check(`${tag}  stop action fired on: ${c.expect.stop || 'none'}`, (seen.stop || undefined) === c.expect.stop, `${seen.stop || 'none'}`);
      if (seen.spill !== undefined) check(`${tag}  no horizontal overflow on the result page`, seen.spill <= 1, `${seen.spill}px`);
      if (seen.screen2Blank) console.log(`  NOTE  ${tag}  screen2 had no visible question (phone and preg hidden) - screenshot elig-${tag}-screen2.png`);
    }
  }
  await browser.close();
}

// ---------------------------------------------------------------------------------------------
// fix: paste the equation into the Online Designer's Calculation Equation box, as the PI would
// ---------------------------------------------------------------------------------------------
async function fix(pid, formulaFile) {
  const formula = fs.readFileSync(formulaFile, 'utf8').trim();
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();
  const alerts = [];
  p.on('dialog', async (d) => { alerts.push(d.message()); await d.accept(); });
  check('signed in', await login(p));
  await p.goto(`${BASE}/${V}/Design/online_designer.php?pid=${pid}&page=screen_eligibility`, { waitUntil: 'networkidle' });
  // First visit to the Online Designer shows a one-time "New drag and drop behavior" tip that covers
  // the field icons; a user dismisses it with its own button.
  const tip = p.locator('.popover button.btn-primary');
  if (await tip.isVisible().catch(() => false)) await tip.click();
  // The pencil on the field's row opens the Edit Field dialog.
  await p.locator('#design-calc_screen_result a[data-field-action="edit-field"]').click();
  await p.locator('#element_enum').waitFor({ state: 'visible' });
  const before = await p.locator('#element_enum').inputValue();
  check('Calculation Equation box is empty before the fix (the bug)', before.trim() === '', JSON.stringify(before.slice(0, 60)));
  // Focusing the box opens REDCap's Logic Editor; the formula goes in there, like a paste.
  await p.locator('#element_enum').focus();
  await p.locator('#rc-ace-editor').waitFor({ state: 'visible' });
  await p.evaluate((f) => { const ed = ace.edit('rc-ace-editor'); ed.setValue(f, 1); }, formula);
  await p.waitForTimeout(400);
  await p.screenshot({ path: `${SHOTS}/elig-fix-logic-editor.png` });
  await p.locator('.ui-dialog:has(#rc-ace-editor-dialog) .ui-dialog-buttonpane button').nth(1).click(); // Update & Close Editor
  await p.waitForTimeout(1200); // REDCap validates the equation as soon as the editor closes
  const verdict = (await p.locator('#element_enum_Ok').innerText().catch(() => '')).trim();
  check("REDCap's own validator accepts the equation", /ok|valid/i.test(verdict) && !/error|invalid/i.test(verdict), verdict);
  await p.screenshot({ path: `${SHOTS}/elig-fix-field-dialog.png` });
  await p.locator('.ui-dialog:has(#div_add_field) .ui-dialog-buttonpane button', { hasText: 'Save' }).click();
  await p.waitForLoadState('networkidle');
  await p.waitForTimeout(1500);
  const saved = sql(`SELECT element_enum FROM redcap_metadata WHERE project_id=${pid} AND field_name='calc_screen_result'`);
  const norm = (s) => s.replace(/\\n|\r?\n/g, ' ').replace(/\s+/g, ' ').trim();
  check('the saved equation is the formula', norm(saved) === norm(formula), norm(saved).slice(0, 90));
  if (alerts.length) console.log('  browser alerts:', alerts);
  await browser.close();
}

// ---------------------------------------------------------------------------------------------
// gate: pre_screen's "auto-continue to next survey" condition, in Survey Settings, as the PI would
// ---------------------------------------------------------------------------------------------
/**
 * pre_screen's stop actions, read from the project: [{ field, stop }]. Only yes/no stops are
 * handled, which is every stop the PI's exports have had; anything else is refused rather than
 * guessed at.
 */
function preScreenStops(pid) {
  const rows = sql(`SELECT field_name, element_type, stop_actions FROM redcap_metadata WHERE project_id=${pid} AND form_name='pre_screen' AND stop_actions IS NOT NULL AND stop_actions<>'' ORDER BY field_order`);
  return rows.split('\n').filter(Boolean).map((r) => {
    const [field, type, stop] = r.split('\t');
    if (!['yesno', 'truefalse'].includes(type) || !['0', '1'].includes(stop)) {
      throw new Error(`pre_screen stop action on ${field} (${type}, codes '${stop}') is not a single yes/no stop`);
    }
    return { field, stop };
  });
}

/**
 * The auto-continue condition: every stop-action question answered with the other option. Derived,
 * not hardcoded, because the PI's exports differ - `md_assent` exists in the 10:58 export and not in
 * 16:46 or PID 279, and a condition naming a deleted field stops everyone at pre-screen.
 * PID 279: [english] = '1' AND [prison] = '0' AND [goodcand] = '1' AND [s_interest] = '1'
 */
function preScreenGate(pid) {
  return preScreenStops(pid).map(({ field, stop }) => `[${field}] = '${stop === '0' ? '1' : '0'}'`).join(' AND ');
}

async function gate(pid) {
  const PRE_SCREEN_GATE = preScreenGate(pid);
  console.log(`  condition: ${PRE_SCREEN_GATE}`);
  const sid = sql(`SELECT survey_id FROM redcap_surveys WHERE project_id=${pid} AND form_name='pre_screen'`);
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();
  p.on('dialog', (d) => d.accept());
  check('signed in', await login(p));
  await p.goto(`${BASE}/${V}/Surveys/edit_info.php?pid=${pid}&survey_id=${sid}`, { waitUntil: 'networkidle' });
  const box = p.locator('#end_survey_redirect_next_survey_logic');
  check('auto-continue is on, with no condition (the leak)', !(await box.isDisabled()) && (await box.inputValue()).trim() === '', JSON.stringify(await box.inputValue()));
  await box.focus();
  await p.locator('#rc-ace-editor').waitFor({ state: 'visible' });
  await p.evaluate((f) => { ace.edit('rc-ace-editor').setValue(f, 1); }, PRE_SCREEN_GATE);
  await p.locator('.ui-dialog:has(#rc-ace-editor-dialog) .ui-dialog-buttonpane button').nth(1).click(); // Update & Close Editor
  await p.waitForTimeout(1200);
  const verdict = (await p.locator('#end_survey_redirect_next_survey_logic_Ok').innerText().catch(() => '')).trim();
  check("REDCap's own validator accepts the condition", /valid/i.test(verdict) && !/invalid|error/i.test(verdict), verdict);
  await p.screenshot({ path: `${SHOTS}/elig-gate-survey-settings.png` });
  await Promise.all([p.waitForLoadState('load'), p.locator('#surveySettingsSubmit').click()]);
  await p.waitForLoadState('networkidle');
  const saved = sql(`SELECT end_survey_redirect_next_survey_logic FROM redcap_surveys WHERE survey_id=${sid}`);
  check('the saved condition is the gate', saved.replace(/\s+/g, ' ').trim() === PRE_SCREEN_GATE, saved);
  await browser.close();
}

// ---------------------------------------------------------------------------------------------
// wrongarm: screen through ANOTHER arm's public link. `pre_screen` is designated to Day 1 of every
// arm, so Survey Distribution Tools offers one public link per arm (invite_participants.php:289).
// Only arm 1 carries `screen_eligibility` and `consent`.
// ---------------------------------------------------------------------------------------------
/** An arm's public screening link, as invite_participants.php:277 builds it - no login needed. */
function publicLinkCli(pid, armNum = 1) {
  const eventId = sql(`SELECT e.event_id FROM redcap_events_metadata e JOIN redcap_events_arms a ON a.arm_id=e.arm_id WHERE a.project_id=${pid} AND a.arm_num=${armNum} ORDER BY e.day_offset, e.event_id LIMIT 1`);
  const sid = sql(`SELECT survey_id FROM redcap_surveys WHERE project_id=${pid} AND form_name='pre_screen'`);
  const hash = execSync(`docker exec -w /var/www/html redcap_2023_1_web php -r '$_GET["pid"]=${pid}; define("NOAUTH",true); require "redcap_connect.php"; echo Survey::getSurveyHash(${sid}, ${eventId});'`).toString().trim();
  return { hash, link: `${BASE}/surveys/?s=${hash}` };
}

async function wrongArm(pid, armNum) {
  const { hash, link } = publicLinkCli(pid, armNum);
  check(`arm ${armNum} public screening link exists`, /^\w{6,}$/.test(hash), link);
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();
  const marker = `E2E-wrongarm${armNum}-${Date.now().toString(36)}`;
  const c = CASES.find((x) => x.id === 'M-elig');
  const seen = await walk(p, link, { s_room: marker, ...BASE_ANSWERS, ...c.a }, `wrongarm${armNum}`);
  const title = (await p.locator('#surveytitle').innerText().catch(() => '')).trim();
  await ctx.close();
  await browser.close();
  const rec = sql(`SELECT record FROM ${dataTable(pid)} WHERE project_id=${pid} AND field_name='s_room' AND value='${marker}' LIMIT 1`);
  const arms = sql(`SELECT GROUP_CONCAT(arm ORDER BY arm) FROM redcap_record_list WHERE project_id=${pid} AND record='${rec}'`);
  console.log(`  pages walked: ${seen.pages.join(' > ')}; stopped on survey "${title}"`);
  check(`an eligible man screened via the arm ${armNum} link never sees the eligibility page`, !seen.pages.includes('eligibility'), seen.pages.join(' > '));
  check(`... and never reaches consent`, !seen.pages.includes('consent'), seen.end || 'n/a');
  check(`... and his record is in arm ${armNum} only, before any randomization`, arms === String(armNum), `record ${rec} arms ${arms}`);
}

// ---------------------------------------------------------------------------------------------
// walk: one participant with the given answers, reported rather than asserted - for a project whose
// structure the CASES table does not describe (e.g. the PI's 16:46 export, which has no calc).
//   node e2e/eligibility-calc.js walk <pid> '{"sc_age":"70","days_dr":"3",...}' <tag> [expect-end]
// ---------------------------------------------------------------------------------------------
async function walkOne(pid, answersJson, tag, expectEnd) {
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const { link } = publicLinkCli(pid, 1);
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();
  const marker = `E2E-walk-${tag}-${Date.now().toString(36)}`;
  const seen = await walk(p, link, { s_room: marker, ...BASE_ANSWERS, ...JSON.parse(answersJson) }, `walk-${tag}`);
  await ctx.close();
  await browser.close();
  const rec = sql(`SELECT record FROM ${dataTable(pid)} WHERE project_id=${pid} AND field_name='s_room' AND value='${marker}' LIMIT 1`);
  const arms = sql(`SELECT GROUP_CONCAT(arm ORDER BY arm) FROM redcap_record_list WHERE project_id=${pid} AND record='${rec}'`);
  console.log(`  ${tag}: pages ${seen.pages.join(' > ')} | stop on ${seen.stop || 'none'} | messages [${seen.shows}] | ends ${seen.end} | record ${rec} arms ${arms}`);
  if (seen.unknownText) console.log(`    unrecognised page text: ${seen.unknownText}`);
  if (expectEnd) check(`${tag} ends at ${expectEnd}`, seen.end === expectEnd, seen.end);
}

// ---------------------------------------------------------------------------------------------
// stops: every pre_screen stop action, read from the project, walked through "End Survey".
//   node e2e/eligibility-calc.js stops <pid>          # asserts the fix: each ends at pre_screen
//   node e2e/eligibility-calc.js stops <pid> leak     # asserts the bug: each continues to AUDIT-C
// Plus an eligible control that must still continue. The first stop also runs on a phone viewport.
// ---------------------------------------------------------------------------------------------
async function stops(pid, mode) {
  const leak = mode === 'leak';
  const { link } = publicLinkCli(pid, 1);
  const sid = sql(`SELECT survey_id FROM redcap_surveys WHERE project_id=${pid} AND form_name='pre_screen'`);
  const cond = sql(`SELECT IFNULL(end_survey_redirect_next_survey_logic,'') FROM redcap_surveys WHERE survey_id=${sid}`);
  console.log(`  pre_screen auto-continue condition: ${cond.trim() ? cond.replace(/\s+/g, ' ') : '(none)'}`);
  const stamp = Date.now().toString(36);
  const eligible = { days_dr: '3', typ_drink: '3', days_binge: '2', phone: '1' };
  const cases = preScreenStops(pid).map(({ field, stop }, i) => ({ id: `stop-${field}`, a: { [field]: stop, ...eligible }, stop: true, mobile: i === 0 }));
  cases.push({ id: 'control-eligible', a: eligible, stop: false, mobile: false });
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  for (const c of cases) {
    for (const mobile of c.mobile ? [false, true] : [false]) {
      const tag = `${c.id}${mobile ? '-mobile' : ''}`;
      const ctx = await browser.newContext(mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } });
      const p = await ctx.newPage();
      const seen = await walk(p, link, { s_room: `E2E-${tag}-${stamp}`, ...BASE_ANSWERS, ...c.a }, `stops-${tag}`);
      const ackText = seen.end === 'ack' ? (await p.locator('#surveyacknowledgment').innerText().catch(() => '')).replace(/\s+/g, ' ').trim() : '';
      const spill = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      await ctx.close();
      const pages = seen.pages.join(' > ');
      if (!c.stop) {
        check(`${tag}: no stop action fires`, !seen.stop, seen.stop || 'none');
        check(`${tag}: continues past pre_screen into AUDIT-C`, seen.pages[1] === 'auditc', pages);
      } else if (leak) {
        check(`${tag}: End Survey shown, then carried on to AUDIT-C [the bug]`, seen.stop === 'pre_screen' && seen.pages.includes('auditc'), pages);
      } else {
        check(`${tag}: "End Survey" fires on pre_screen`, seen.stop === 'pre_screen', seen.stop || 'none');
        check(`${tag}: ends on pre_screen's thank-you page, AUDIT-C never opens`, pages === 'pre_screen > ack', pages);
        console.log(`        page says: "${ackText.slice(0, 120)}"`);
        check(`${tag}: no horizontal overflow`, spill <= 1, `${spill}px`);
      }
    }
  }
  await browser.close();
  return stamp;
}

(async () => {
  const [cmd, a1, a2] = process.argv.slice(2);
  if (cmd === 'create') await create(a1);
  else if (cmd === 'run') await run(a1, a2 || 'fixed');
  else if (cmd === 'fix') await fix(a1, a2);
  else if (cmd === 'gate') await gate(a1);
  else if (cmd === 'wrongarm') await wrongArm(a1, a2 || '2');
  else if (cmd === 'walk') await walkOne(a1, a2, process.argv[5] || 'case', process.argv[6]);
  else if (cmd === 'stops') await stops(a1, a2);
  else { console.error('usage: see header'); process.exit(2); }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
