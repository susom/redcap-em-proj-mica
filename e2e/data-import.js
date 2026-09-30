// A CSV through REDCap's own Data Import Tool: upload, review, "Import Data" - what a coordinator does.
//
//   node e2e/data-import.js <pid> <csv> [normal|overwrite] [--review-only]
//
// Signs in as the throwaway user from e2e-admin-form-user.php (override with MICA_E2E_USER/PASS).
// Reports REDCap's own verdict: validation errors on the review page, or "Import Successful!".
// Used to prove an import file before it is handed to someone for production - see
// docs/alerts/pi-review/test-6b/.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const BASE = process.env.MICA_BASE || 'http://redcap.local';
const USER = process.env.MICA_E2E_USER || 'e2e_admin_form';
const PASS = process.env.MICA_E2E_PASS || 'E2eAdminForm!2026';
const V = 'redcap_v17.2.3';
const SHOTS = path.join(__dirname, 'shots');
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

const [pid, csv, mode = 'normal'] = process.argv.slice(2);
const reviewOnly = process.argv.includes('--review-only');
if (!pid || !csv) { console.error('usage: node e2e/data-import.js <pid> <csv> [normal|overwrite] [--review-only]'); process.exit(2); }

(async () => {
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const p = await (await browser.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
  await p.goto(`${BASE}/${V}/index.php`, { waitUntil: 'domcontentloaded' });
  await p.fill('input[name="username"]', USER);
  await p.fill('input[name="password"]', PASS);
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('#login_btn')]);
  await p.waitForTimeout(1200);

  await p.goto(`${BASE}/${V}/index.php?route=DataImportController:index&pid=${pid}`, { waitUntil: 'networkidle' });
  await p.selectOption('select[name="async"]', '0');            // import now, not in the background
  await p.setInputFiles('#uploadedfile', csv);
  await p.selectOption('select[name="overwriteBehavior"]', mode);
  await p.selectOption('select[name="format"]', 'rows');
  await Promise.all([p.waitForLoadState('networkidle'), p.click('#submit')]);

  const review = (await p.locator('body').innerText()).replace(/\s+/g, ' ');
  await p.screenshot({ path: `${SHOTS}/data-import-review.png`, fullPage: true });
  const canImport = await p.locator('button[name="updaterecs"]').count();
  const errorBox = review.match(/.{0,80}(error|cannot be imported|not valid|invalid).{0,200}/i)?.[0];
  console.log(`review: ${canImport ? 'READY' : 'BLOCKED'}${errorBox && !canImport ? ' - ' + errorBox : ''}`);
  const warn = review.match(/.{0,60}warning.{0,200}/i)?.[0];
  if (warn) console.log(`review warning: ${warn}`);
  if (!canImport || reviewOnly) { await browser.close(); process.exit(canImport ? 0 : 1); }

  // REDCap appends its CSRF token to the form after load; submitting before that is rejected as
  // "Multiple tabs/windows open! Your changes were not saved" (same trap as weekly-sms-dd-upload.js).
  await p.waitForFunction(() => window.REDCap && REDCap.appendCsrfTokenToFormComplete === true, null, { timeout: 15000 });
  await Promise.all([p.waitForLoadState('networkidle'), p.locator('button[name="updaterecs"]').first().click()]);
  await p.waitForTimeout(1500);
  const done = (await p.locator('body').innerText()).replace(/\s+/g, ' ');
  await p.screenshot({ path: `${SHOTS}/data-import-done.png`, fullPage: true });
  const ok = /Import Successful/i.test(done);
  console.log(`import: ${ok ? 'OK - Import Successful!' : 'NOT CONFIRMED - ' + done.slice(0, 300)}`);
  await browser.close();
  process.exit(ok ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(1); });
