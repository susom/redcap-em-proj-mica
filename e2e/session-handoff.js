// Session-handoff E2E: the two pages a participant can land on when the arm-1 chain ends.
//
//   docker exec <web> php .../scripts/verify-ed-session-link.php 257     # prints the URLs to use
//   node e2e/session-handoff.js
//
// Covers the *destination*, not the redirect. The redirect itself is one row in
// `redcap_surveys.end_survey_redirect_url` and is asserted server-side by
// verify-ed-session-link.php; what needs a browser is whether the participant sees something.
//
// Why this suite exists at all: REDCap's survey redirect is all-or-nothing. The guard at
// `Surveys/index.php:1833` tests the redirect template BEFORE piping, so `[ed_session_url]` piping to
// an empty string still reaches `redirect('')` - measured as `302` with `Location:` empty and a body
// of **zero bytes**. A participant who finished screening saw a blank screen. That is the regression
// this file exists to catch, which is why `C4` asserts a non-trivial body length rather than just
// "the page loaded".
const { chromium, devices } = require('playwright');
const fs = require('fs');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const BASE = process.env.MICA_BASE || 'http://redcap.local';
const PID = process.env.MICA_PID || '257';

const page = (state) =>
  `${BASE}/api/?type=module&prefix=proj_mica&page=pages%2FsessionHandoff&NOAUTH&pid=${PID}&state=${state}`;

const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

/**
 * Nothing on either page may hint at the allocation.
 *
 * `done` is what a Standard Care participant sees, and "you have no MICA session" would tell them
 * they are in the control arm. That is unblinding, and it is the kind of thing that gets written
 * into a page nobody reviewed - so it is asserted rather than trusted.
 */
const UNBLINDING = /\barms?\b|standard care|control group|intervention|study.?group|not randomi/i;

async function run(mobile) {
  const label = mobile ? 'mobile' : 'desktop';
  console.log(`\n=== ${label} ===`);
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const ctx = await browser.newContext(
    mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1280, height: 800 } },
  );

  for (const state of ['pending', 'done']) {
    const p = await ctx.newPage();
    const errs = [];
    p.on('pageerror', (e) => errs.push(String(e.message).slice(0, 140)));

    const res = await p.goto(page(state), { waitUntil: 'domcontentloaded' });
    check(`C1 ${label}/${state}: page responds 200`, res.status() === 200, String(res.status()));

    const text = (await p.innerText('body')).replace(/\s+/g, ' ').trim();
    check(`C2 ${label}/${state}: a message is shown`, text.length > 20, text.slice(0, 60));
    // The regression guard. A blank body is exactly what the empty-redirect bug produced.
    check(`C3 ${label}/${state}: the body is not blank`, text.length > 0);
    check(`C4 ${label}/${state}: nothing reveals the allocation`, !UNBLINDING.test(text),
      UNBLINDING.exec(text)?.[0] ?? '');

    const spill = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check(`C5 ${label}/${state}: no horizontal overflow`, spill <= 1, `${spill}px`);

    const card = await p.locator('.card').first().boundingBox();
    check(`C6 ${label}/${state}: the card fits the viewport`, card !== null
      && card.width <= (mobile ? 390 : 1280) && card.x >= 0, card ? Math.round(card.width) + 'px' : 'missing');

    // A participant scaling their text up is exactly the participant reading this on a phone.
    const fontPx = await p.locator('.card p').first().evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    check(`C7 ${label}/${state}: body text is at least 16px`, fontPx >= 16, `${fontPx}px`);

    check(`C8 ${label}/${state}: no JS errors`, errs.length === 0, errs.join(' | '));

    await p.screenshot({ path: `${SHOTS}/handoff-${label}-${state}.png` });
    await p.close();
  }

  // An unknown state must fall back to the safe message rather than erroring or echoing input.
  const p = await ctx.newPage();
  await p.goto(page('<script>alert(1)</script>'), { waitUntil: 'domcontentloaded' });
  const html = await p.content();
  const text = (await p.innerText('body')).replace(/\s+/g, ' ').trim();
  check(`C9 ${label}: an unknown state falls back to the completion message`, text.length > 20, text.slice(0, 50));
  check(`C10 ${label}: the state parameter is never echoed into the page`, !html.includes('alert(1)'));

  await p.close();
  await ctx.close();
  await browser.close();
}

(async () => {
  await run(false);
  await run(true);
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
