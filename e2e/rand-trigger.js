// Randomization trigger E2E (PID 271): both ways a record gets randomized, and the one that must not.
// See docs/randomization/TRIGGER_TSR_COMPLETE_OR_MANUAL.md.
//
//   docker exec <web> php .../scripts/seed-rand-test.php 271 TRIGTSR01     # and TRIGNEG01, TRIGMAN01
//   docker exec <web> php .../scripts/e2e-admin-form-user.php 271 setup --randomize
//   node e2e/rand-trigger.js <tsr survey link for TRIGTSR01> [TRIGTSR01,TRIGNEG01,TRIGMAN01]
//
// Every step consumes its record (a randomized record cannot be un-randomized), so a re-run needs
// freshly seeded records named in the second argument.
//   docker exec <web> php .../scripts/e2e-admin-form-user.php 271 teardown
//
//   A  participant submits `tsr` as a survey          -> randomized by the trigger, placed in its arm
//   N  staff save `tsr` as Incomplete on data entry   -> NOT randomized
//      ... then save it Complete                      -> randomized by the trigger
//   M  staff click Randomize on `admin` (tsr not done) -> randomized manually, placed in its arm
//
// Staff steps run as a NON-super user with Randomize rights. A super user gets random_perform=1 from
// UserRights::getSuperUserPrivileges() whatever the project says, so testing as one proves nothing
// about a coordinator.
//
// Assertions read the database, not the page: the page can say "randomized" while the module's arm
// placement - the half that gives the participant a session - silently failed.
const { chromium, devices } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const BASE = process.env.MICA_BASE || 'http://redcap.local';
const USER = process.env.MICA_E2E_USER || 'e2e_admin_form';
const PASS = process.env.MICA_E2E_PASS || 'E2eAdminForm!2026';
const PID = 271, EVENT = 1104, RID = 8;
const DE = `${BASE}/redcap_v17.2.3/DataEntry/index.php?pid=${PID}&event_id=${EVENT}`;

const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

const tsrLink = process.argv[2];
if (!tsrLink) { console.error('usage: node e2e/rand-trigger.js <tsr survey link> [tsrRec,negRec,manRec]'); process.exit(2); }
const [TSR_REC, NEG_REC, MAN_REC] = (process.argv[3] || 'TRIGTSR01,TRIGNEG01,TRIGMAN01').split(',');

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

const sql = (q) => execSync(
  `docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -N -e ${JSON.stringify(q)} 2>/dev/null`,
).toString().trim();

/** Everything that says "this record was randomized AND placed", read straight from the tables. */
function state(record) {
  const r = record.replace(/'/g, '');
  return {
    group: sql(`SELECT value FROM redcap_data7 WHERE project_id=${PID} AND record='${r}' AND event_id=${EVENT} AND field_name='study_group'`),
    audit: sql(`SELECT description FROM redcap_log_event8 WHERE project_id=${PID} AND pk='${r}' AND description LIKE 'Randomize record%' ORDER BY log_event_id`),
    slot: sql(`SELECT aid FROM redcap_randomization_allocation WHERE rid=${RID} AND is_used_by='${r}'`),
    arms: sql(`SELECT GROUP_CONCAT(arm ORDER BY arm) FROM redcap_record_list WHERE project_id=${PID} AND record='${r}'`),
    placed: sql(`SELECT COUNT(*) FROM redcap_external_modules_log WHERE project_id=${PID} AND record='${r}' AND message='record added to its randomized arm'`),
    link: sql(`SELECT value FROM redcap_data7 WHERE project_id=${PID} AND record='${r}' AND field_name='ed_session_url' LIMIT 1`),
  };
}

function assertRandomized(tag, record, how) {
  const s = state(record);
  check(`${tag}  study_group written at Day 1 (ED)`, /^[123]$/.test(s.group), `'${s.group}'`);
  check(`${tag}  audit row: ${how}`, s.audit.split('\n').includes(how), s.audit.replace(/\n/g, ' | '));
  check(`${tag}  an allocation slot is charged to the record`, /^\d+$/.test(s.slot), `aid ${s.slot}`);
  // Groups 2/3 put the record in a second arm; Standard Care stays in arm 1 only.
  const wantArms = s.group === '1' ? '1' : `1,${s.group}`;
  check(`${tag}  record is in its arm(s)`, s.arms === wantArms, `arms ${s.arms}, want ${wantArms}`);
  if (s.group !== '1') check(`${tag}  module logged the arm placement`, Number(s.placed) >= 1, `${s.placed} row(s)`);
  check(`${tag}  ed_session_url stored`, /^https?:\/\//.test(s.link), s.link.slice(0, 70));
  return s;
}

function assertNotRandomized(tag, record) {
  const s = state(record);
  check(`${tag}  study_group still empty`, s.group === '', `'${s.group}'`);
  check(`${tag}  no randomization audit row`, s.audit === '', s.audit);
  check(`${tag}  no allocation slot charged`, s.slot === '', s.slot);
  check(`${tag}  record in arm 1 only`, s.arms === '1', s.arms);
}

async function login(p) {
  await p.goto(`${BASE}/redcap_v17.2.3/index.php`, { waitUntil: 'domcontentloaded' });
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

/** Save & Stay. The id is duplicated (the button bar and the floating save tip), so call REDCap's own handler. */
async function saveAndStay(p) {
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), p.evaluate(() => dataEntrySubmit('submit-btn-savecontinue'))]);
}

/** `tsr` with "no treatment" - branching hides every other required field. */
async function answerTsr(p) {
  await p.locator('input[name="alc_tx___radio"][value="0"]').check();
}

(async () => {
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });

  // ---- A: participant, survey --------------------------------------------------------------
  console.log(`\nA  participant submits tsr (${TSR_REC})`);
  assertNotRandomized('A0', TSR_REC);
  for (const mobile of [true, false]) {
    // Mobile only renders and screenshots the page, and goes FIRST: after the desktop submit the link
    // is spent, and a screenshot of a spent link says nothing about the form.
    const ctx = await browser.newContext(mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } });
    const p = await ctx.newPage();
    await p.goto(tsrLink, { waitUntil: 'networkidle' });
    await p.screenshot({ path: `${SHOTS}/rand-trigger-tsr-${mobile ? 'mobile' : 'desktop'}.png`, fullPage: true });
    if (!mobile) {
      await answerTsr(p);
      await Promise.all([p.waitForLoadState('load'), p.locator('button[name="submit-btn-saverecord"]').click()]);
      await p.waitForLoadState('networkidle');
      check('A   survey submit left the tsr page', !p.url().includes(new URL(tsrLink).search), p.url().slice(0, 90));
    }
    await ctx.close();
  }
  assertRandomized('A', TSR_REC, 'Randomize record (via trigger)');

  // ---- staff session -----------------------------------------------------------------------
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();
  check('    signed in as non-super user with Randomize rights', await login(p));

  // ---- N: staff save tsr Incomplete, then Complete ----------------------------------------
  console.log(`\nN  staff save tsr on data entry (${NEG_REC})`);
  await p.goto(`${DE}&id=${NEG_REC}&page=tsr`, { waitUntil: 'networkidle' });
  await answerTsr(p);
  await p.selectOption('select[name="tsr_complete"]', '0');
  await saveAndStay(p);
  assertNotRandomized('N1 (Incomplete)', NEG_REC);

  await p.selectOption('select[name="tsr_complete"]', '2');
  await saveAndStay(p);
  assertRandomized('N2 (Complete)', NEG_REC, 'Randomize record (via trigger)');

  // ---- M: manual Randomize button, tsr never touched --------------------------------------
  console.log(`\nM  staff click Randomize on admin (${MAN_REC})`);
  assertNotRandomized('M0', MAN_REC);
  await p.goto(`${DE}&id=${MAN_REC}&page=admin`, { waitUntil: 'networkidle' });
  const btn = p.locator(`#redcapRandomizeBtn${RID}`);
  check('M   Randomize button is shown', await btn.isVisible());
  await btn.click();
  const dlg = p.locator(`.ui-dialog:has(#randomizeDialog${RID})`);
  await dlg.waitFor({ state: 'visible' });
  await dlg.locator('.ui-dialog-buttonpane button:has-text("Randomize")').click();
  await dlg.locator('.darkgreen').waitFor({ state: 'visible', timeout: 15000 });
  const msg = (await dlg.locator('.darkgreen').innerText()).replace(/\s+/g, ' ').trim();
  check('M   dialog confirms the allocation', /randomized/i.test(msg), msg);
  await p.screenshot({ path: `${SHOTS}/rand-trigger-manual-desktop.png` });
  // Checked BEFORE the admin form is saved: the placement must come from the Randomize click itself,
  // not depend on the coordinator remembering to save the form afterwards.
  assertRandomized('M', MAN_REC, 'Randomize record');
  await ctx.close();

  // Mobile render of the randomized admin form - REDCap core, but it is what a coordinator on a
  // phone sees.
  const mctx = await browser.newContext({ ...devices['iPhone 13'] });
  const mp = await mctx.newPage();
  await login(mp);
  await mp.goto(`${DE}&id=${MAN_REC}&page=admin`, { waitUntil: 'networkidle' });
  const already = mp.locator(`#alreadyRandomizedText${RID}`);
  check('M   mobile: admin form shows the record as already randomized', await already.isVisible(),
    (await already.innerText().catch(() => '')).trim());
  await mp.screenshot({ path: `${SHOTS}/rand-trigger-manual-mobile.png`, fullPage: true });
  await mctx.close();

  await browser.close();
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
