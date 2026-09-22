// `close` empty-redirect E2E: a participant submits the study's closing survey and must land on a
// page, not a white screen. See docs/phase-3-handoff/29-close-empty-redirect.md.
//
//   node e2e/close-empty-redirect.js 'done=<close link>' 'session=<close link>' ...
//
// Each argument is `<expected>=<un-submitted close survey link>`, where <expected> is
//   done     the handoff page with state=done   (close at any event but the screening one; arm 1)
//   pending  the handoff page with state=pending (the screening close of an unrandomized record)
//   session  a survey link, i.e. the ED session (the screening close of an arm-2/3 record)
// Mint the links with REDCap::getSurveyLink(); a submitted link cannot be re-tested on the submit
// path, only on the revisit path, which this also walks.
//
// Why a browser and not curl: the bug is `302` with an EMPTY `Location:`, which Chromium renders as
// a blank page with no error. The only honest assertion is "the participant can read something".
const { chromium, devices } = require('playwright');
const fs = require('fs');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

const cases = process.argv.slice(2).map((a) => {
  const i = a.indexOf('=');
  return { expect: a.slice(0, i), url: a.slice(i + 1) };
});
if (!cases.length || cases.some((c) => !['done', 'pending', 'session'].includes(c.expect) || !c.url)) {
  console.error("usage: node e2e/close-empty-redirect.js 'done=<url>' 'session=<url>' ...");
  process.exit(2);
}

// The local docker image's red "LOCALHOST" instance banner reads `.navbar` on survey pages, which
// have none. Not the module, not on production - ignored by its exact message and nothing wider.
const LOCAL_BANNER_ERROR = /^Cannot read properties of undefined \(reading 'offsetHeight'\)$/;

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

const landedWhereExpected = (expect, url) => expect === 'session'
  ? /\/surveys\/(index\.php)?\?s=\w+/.test(url)
  : /page=pages%2FsessionHandoff/.test(url) && new RegExp(`[?&]state=${expect}\\b`).test(url);

async function inspect(p, label, expect) {
  const url = p.url();
  check(`${label}: landed where expected (${expect})`, landedWhereExpected(expect, url), url);
  // The session link opens behind REDCap's Survey Login dialog, which renders after
  // DOMContentLoaded; read too early and a working page looks exactly like the bug.
  await p.waitForLoadState('networkidle');
  const text = (await p.innerText('body').catch(() => '')).replace(/\s+/g, ' ').trim();
  // The regression guard: the bug's page has a body of zero bytes.
  check(`${label}: the page is not blank`, text.length > 20, text.slice(0, 70));
  const spill = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  check(`${label}: no horizontal overflow`, spill <= 1, `${spill}px`);
}

async function run(mobile) {
  const tag = mobile ? 'mobile' : 'desktop';
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });

  for (const [n, c] of cases.entries()) {
    // The first run submits; every later one (the second viewport) is a revisit of a completed
    // response, which is REDCap's other redirect path (`Surveys/index.php:1821`).
    const ctx = await browser.newContext(
      mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1280, height: 800 } },
    );
    const p = await ctx.newPage();
    const errs = [];
    p.on('pageerror', (e) => {
      if (!LOCAL_BANNER_ERROR.test(String(e.message))) errs.push(String(e.message).slice(0, 140));
    });

    await p.goto(c.url, { waitUntil: 'domcontentloaded' });
    const submit = p.locator('button[name="submit-btn-saverecord"]');
    if (await submit.count()) {
      console.log(`\n=== ${tag} #${n + 1} submit ${c.url}`);
      await Promise.all([p.waitForNavigation({ waitUntil: 'domcontentloaded' }), submit.first().click()]);
      await p.waitForLoadState('domcontentloaded');
      await inspect(p, `${tag} #${n + 1} submit`, c.expect);
    } else {
      console.log(`\n=== ${tag} #${n + 1} revisit ${c.url}`);
      await inspect(p, `${tag} #${n + 1} revisit`, c.expect);
    }
    check(`${tag} #${n + 1}: no JS errors`, errs.length === 0, errs.join(' | '));
    await p.screenshot({ path: `${SHOTS}/close-${tag}-${n + 1}.png`, fullPage: true });
    await ctx.close();
  }
  await browser.close();
}

(async () => {
  await run(false);
  await run(true);
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
