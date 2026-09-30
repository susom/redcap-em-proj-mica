// Alert 13 (arm-3 booster 1-week reminder, SMS) end to end on record 37, via the browser.
//
//   docker exec <web> php .../scripts/e2e-admin-form-user.php 271 setup
//   node e2e/alert-13-sms.js
//   docker exec <web> php .../scripts/e2e-admin-form-user.php 271 teardown
//
// WHY THE SAVE LANDS ON month_3_arm_3 AND NOT ON THE ANCHOR. Measured in §7b of
// docs/alerts/PID271_ALERT_TEST_MATRIX.md: changing `randomization_date` at event 1104 - the value
// the alert actually reads - queues nothing, twice over (browser and API). The save has to land on
// the alert's own firing event. So this opens the booster-session form at Month 3 and saves it,
// which is also what a coordinator does when chasing a participant who has not attended.
//
// It is left INCOMPLETE on purpose: every booster alert is gated on
// `[mica_booster_session_complete]<>'2'`, so marking it Complete is the one thing that would
// correctly cancel the reminder.
//
// SCOPE OF WHAT THIS CAN SEND. At event 1114 the only SMS alerts are 11 and 13; 11 already has a
// sent row for record 37 and is suppressed by the unique key. So alert 13 is the only text this
// can produce. Alert 14 (CRC email, +105) also becomes due and goes to MailHog.

const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.MICA_BASE || 'http://redcap.local';
const USER = process.env.MICA_E2E_USER || 'e2e_admin_form';
const PASS = process.env.MICA_E2E_PASS || 'E2eAdminForm!2026';
const PID = process.env.MICA_PID || '271';
const RECORD = process.env.MICA_RECORD || '37';
const EVENT = process.env.MICA_EVENT || '1114';                       // month_3_arm_3
const FORM = process.env.MICA_FORM || 'mica_booster_session';

const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

(async () => {
  const browser = await chromium.launch({ args: ['--host-rules=MAP redcap.local 127.0.0.1'] });
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
  const page = await ctx.newPage();

  try {
    await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="username"]').first().fill(USER);
    await page.locator('input[name="password"]').first().fill(PASS);
    await page.locator('#login_btn').first().click();
    await page.waitForLoadState('domcontentloaded');
    check('signed in', !(await page.locator('input[name="password"]').count()));

    const url = `${BASE}/redcap_v17.2.3/DataEntry/index.php?pid=${PID}&id=${RECORD}&page=${FORM}&event_id=${EVENT}`;
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    check('booster form open at month_3_arm_3',
          /Month 3/i.test(await page.locator('body').innerText()) ||
          (await page.locator('select[name="mica_booster_session_complete"]').count()) > 0);

    // Keep the form Incomplete — Complete would correctly cancel the reminder.
    const status = page.locator('select[name="mica_booster_session_complete"]').first();
    if (await status.count()) {
      await status.selectOption('0');
      check('completion status left Incomplete', (await status.inputValue()) === '0');
    }

    await page.screenshot({ path: `${SHOTS}/alert13-before-save.png` });

    const saveBtn = page.locator('button[name="submit-btn-saverecord"], input[name="submit-btn-saverecord"]').first();
    check('save button present', await saveBtn.count() > 0);
    await Promise.all([page.waitForLoadState('domcontentloaded'), saveBtn.click()]);
    await page.waitForTimeout(1500);

    const body = await page.locator('body').innerText();
    check('save completed without a validation block', !/could not be saved/i.test(body));
    await page.screenshot({ path: `${SHOTS}/alert13-after-save.png` });

  } catch (e) {
    fail++;
    console.log('  FAIL  exception — ' + e.message.split('\n')[0]);
    await page.screenshot({ path: `${SHOTS}/alert13-error.png` }).catch(() => {});
  } finally {
    await browser.close();
  }

  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
