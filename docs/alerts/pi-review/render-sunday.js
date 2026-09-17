// Render the arm-3 weekly-SMS survey (`sunday`) exactly as a participant would receive it, and
// report what actually displays. Read-only: types into `dquant` to fire client-side branching but
// never submits, so no data is written.
const { chromium } = require('playwright');
const fs = require('fs');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const LINK = process.argv[2];
const OUT = process.argv[3] || '/tmp/sunday';

(async () => {
  const b = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const p = await b.newPage({ viewport: { width: 900, height: 1200 } });

  await p.goto(LINK, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1200);

  const report = async (stage) => {
    const visible = await p.evaluate(() => {
      const out = [];
      document.querySelectorAll('tr[id$="-tr"]').forEach((tr) => {
        if (tr.offsetParent === null) return;               // hidden by branching
        const id = tr.id.replace(/-tr$/, '');
        const txt = (tr.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 90);
        if (txt) out.push({ id, txt });
      });
      return out;
    });
    console.log(`\n--- ${stage}: ${visible.length} visible field row(s) ---`);
    visible.forEach((v) => console.log(`  ${v.id.padEnd(18)} ${v.txt}`));
    await p.screenshot({ path: `${OUT}-${stage}.png`, fullPage: true });
    return visible;
  };

  console.log('title:', await p.title());
  const loginBox = await p.locator('input[name="password"], #survey_login_form').count();
  console.log('survey-login gate present:', loginBox > 0);

  await report('asis');

  // Fire the drinking-threshold branches without submitting.
  const dq = p.locator('input[name="dquant"]');
  if (await dq.count()) {
    await dq.fill('10');
    await dq.blur();
    await p.waitForTimeout(900);
    await report('dquant10');
  } else {
    console.log('dquant input not present');
  }

  await b.close();
})();
