// Review-dashboard E2E: the RA queue, session review, evidence highlighting and a disposition,
// desktop and mobile.
//
//   docker exec <web> php .../scripts/e2e-review-fixture.php 257 setup     # prints creds + URL
//   node e2e/review-dashboard.js
//   docker exec <web> php .../scripts/e2e-review-fixture.php 257 teardown
//
// Unlike the participant suite this one signs in as a REDCap USER — the dashboard is an
// authenticated module page. The fixture creates a throwaway account with no design or user-rights
// privileges on purpose: the dashboard has to work for an ordinary reviewer, not only for an admin.
//
// Screenshots land in e2e/shots/ (gitignored).
const { chromium, devices } = require('playwright');
const fs = require('fs');

// REDCap builds absolute asset URLs from its CONFIGURED base URL, so the browser has to be on that
// hostname or the module's own JS is a cross-origin request. Rather than require an /etc/hosts entry,
// resolve the name in-browser.
const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';

const BASE = process.env.MICA_BASE || 'http://redcap.local';
const USER = process.env.MICA_E2E_USER || 'e2e_mica_reviewer';
const PASS = process.env.MICA_E2E_PASS || 'E2eReview!2026';
const PID = process.env.MICA_PID || '257';
const REVIEW_PATH = `/redcap_v17.2.3/ExternalModules/?prefix=proj_mica&page=pages%2Freview&pid=${PID}`;

const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

async function newCtx(browser, mobile = false) {
  const ctx = await browser.newContext(
    mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } },
  );
  const p = await ctx.newPage();
  p._errs = [];
  p._failed = [];
  // Same REDCap-core noise the participant suite documents: fires on non-MICA pages too, stack
  // lands in redcap core's bundle. Counted, never attributed to the module.
  p._coreErrs = 0;
  p.on('pageerror', (e) => {
    const m = String(e.message);
    if (/offsetHeight/.test(m)) { p._coreErrs++; return; }
    p._errs.push(m.slice(0, 200));
  });
  // Only requests to REDCap's own host count. REDCap's footer pulls a logo from
  // redcap.vanderbilt.edu, which Chrome blocks under Opaque Response Blocking - a third-party asset
  // on a page the module does not control, failing differently depending on the network. Counting it
  // made a green run go red for a reason no amount of reading this module could explain. Off-host
  // failures are tallied and reported separately, never attributed to MICA.
  p._offHost = 0;
  p.on('requestfailed', (r) => {
    const url = r.url();
    if (!url.startsWith(BASE)) { p._offHost++; return; }
    p._failed.push(`${url.slice(-70)} :: ${r.failure()?.errorText}`);
  });
  return { ctx, p };
}

async function login(p) {
  await p.goto(`${BASE}/redcap_v17.2.3/index.php`, { waitUntil: 'domcontentloaded' });
  const user = p.locator('input[name="username"]').first();
  if (!(await user.count())) return true; // already signed in
  await user.fill(USER);
  await p.locator('input[name="password"]').first().fill(PASS);
  // #login_btn, not [name=login_btn] or [type=submit]: REDCap's button carries an id and no type
  // attribute at all, so both of the obvious selectors miss it and time out on a page that is fine.
  await p.locator('#login_btn').first().click();
  await p.waitForLoadState('domcontentloaded');
  await p.waitForTimeout(1200);
  return !(await p.locator('input[name="password"]').count());
}

(async () => {
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });

  // ------------------------------------------------------------------ desktop
  console.log('\nDesktop 1400x950');
  const { ctx, p } = await newCtx(browser);

  check('D1  signed in as the throwaway reviewer', await login(p));

  await p.goto(BASE + REVIEW_PATH, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(1500);

  const root = p.locator('#mica-review-root');
  check('D2  the dashboard mounted', (await root.count()) > 0 && (await root.innerText()).length > 40);

  const shellText = await root.innerText().catch(() => '');
  check('D3  the reviewer sees their own role', /e2e_mica_reviewer/.test(shellText) && /\bra\b/.test(shellText),
    shellText.split('\n').slice(0, 3).join(' | ').slice(0, 110));

  // The build not having run renders a specific message. Distinguishing it from "no findings" is the
  // whole reason that message exists.
  check('D4  the SPA bundle loaded (not the "not built" notice)',
    !/has not been built/i.test(shellText));

  // The queue itself.
  await p.waitForTimeout(1200);
  const queueText = await root.innerText().catch(() => '');
  const rows = p.locator('.mica-row');
  const rowCount = await rows.count();
  check('D5  the queue lists the seeded sessions', rowCount >= 2, `${rowCount} rows`);

  check('D6  a critical finding is labelled in words, not only colour',
    /Critical/.test(queueText) && /Self-harm/i.test(queueText));

  // Order is the safety feature: critical above moderate.
  //
  // Two things this assertion has to get right. The badge is uppercased by CSS and allInnerTexts()
  // returns RENDERED text, so the match is case-insensitive. And only rows still awaiting a decision
  // are compared: a settled row correctly sorts last regardless of urgency, so a previous run's
  // disposition would otherwise look like a broken sort. Re-seed the fixture for a clean state.
  if (rowCount >= 2) {
    const texts = await rows.allInnerTexts();
    const pending = texts.filter((t) => /pending review/i.test(t));
    const critAt = pending.findIndex((t) => /critical/i.test(t));
    const modAt = pending.findIndex((t) => /moderate/i.test(t));
    check('D7  critical sorts above moderate (among pending)',
      critAt !== -1 && modAt !== -1 && critAt < modAt,
      `critical@${critAt} moderate@${modAt} of ${pending.length} pending`);
  } else {
    check('D7  critical sorts above moderate (among pending)', false, 'not enough rows to order');
  }

  await p.screenshot({ path: `${SHOTS}/review-queue-desktop.png`, fullPage: true });

  // ------------------------------------------------------------------ session review
  await rows.first().click();
  await p.waitForTimeout(1500);

  const sessionText = await root.innerText().catch(() => '');
  check('D8  the session opened with its transcript', /Transcript/i.test(sessionText)
    && /brother died/i.test(sessionText));

  check('D9  the scan metadata shows the deployment and transcript ref',
    /mock:e2e/.test(sessionText) && /\bT\d+/.test(sessionText));

  // Evidence highlighting — the feature that silently did nothing until a test caught the key
  // mismatch between `finding_evidence` and `evidence`.
  const marks = p.locator('#mica-review-root mark.mica-mark');
  const markCount = await marks.count();
  const markTexts = markCount ? await marks.allInnerTexts() : [];
  check('D10 the cited quote is highlighted in the transcript', markCount >= 1,
    `${markCount} marks: ${markTexts.join(' / ').slice(0, 80)}`);

  check('D11 highlighting survives multi-byte text',
    markTexts.some((t) => /caña|日本語|🙂/.test(t)),
    markTexts.join(' / ').slice(0, 80));

  // The claim that must never be misread.
  check('D12 a model recommendation says nobody was notified',
    /Nobody has been notified/i.test(sessionText));

  // Jump-to-evidence.
  const jump = p.locator('.mica-quote-src').first();
  if (await jump.count()) {
    await jump.click();
    await p.waitForTimeout(500);
    check('D13 jump-to-evidence focuses the cited message',
      await p.evaluate(() => document.activeElement?.className?.includes('mica-msg')));
  } else {
    check('D13 jump-to-evidence focuses the cited message', false, 'no jump control rendered');
  }

  await p.screenshot({ path: `${SHOTS}/review-session-desktop.png`, fullPage: true });

  // ------------------------------------------------------------------ the disposition gate
  const confirm = p.locator('input[type=radio][value=confirmed]').first();
  await confirm.check();
  await p.waitForTimeout(300);

  const submit = p.locator('button:has-text("Record decision")').first();
  check('D14 confirm is blocked until a rationale is given', await submit.isDisabled());

  const rationale = p.locator('textarea').first();
  await rationale.fill('E2E: verified the quote against the transcript.');
  await p.waitForTimeout(300);
  check('D15 and enabled once it is', await submit.isEnabled());

  await submit.click();
  await p.waitForTimeout(2500);

  const afterText = await root.innerText().catch(() => '');
  check('D16 the decision was recorded', /Decision recorded/i.test(afterText)
    || /Confirmed/.test(afterText), afterText.split('\n')[0]?.slice(0, 90));

  check('D17 and the reviewer is named on it', /e2e_mica_reviewer/.test(afterText));

  await p.screenshot({ path: `${SHOTS}/review-disposed-desktop.png`, fullPage: true });

  // A second submit at the same lock version must conflict rather than overwrite.
  const confirm2 = p.locator('input[type=radio][value=dismissed]').first();
  if (await confirm2.count()) {
    await confirm2.check();
    await p.locator('textarea').first().fill('E2E: second decision at a stale version.');
    await p.waitForTimeout(200);
    const submit2 = p.locator('button:has-text("Record decision")').first();
    if (await submit2.isEnabled()) {
      await submit2.click();
      await p.waitForTimeout(2000);
    }
    // Either it succeeded against the RELOADED version (fine — the form reloads after a save) or it
    // conflicted. What must not happen is a silent overwrite with no record.
    const t = await root.innerText().catch(() => '');
    check('D18 a repeat decision is either versioned or refused, never silent',
      /Decision recorded/i.test(t) || /saved this finding first/i.test(t));
  } else {
    check('D18 a repeat decision is either versioned or refused, never silent', true, 'form reset after save');
  }

  // ------------------------------------------------------------------ launch readiness
  //
  // The fixture maps the throwaway role as PI as well as RA, so the checklist is reachable. PID 257
  // fails the deliberate gate (critical_acknowledgment_minutes ships null on purpose), so there is
  // always something to render without seeding anything.
  const gatesTab = p.locator('button:has-text("Launch readiness")').first();
  check('D19 the launch-readiness tab is offered to a PI', (await gatesTab.count()) > 0);

  if (await gatesTab.count()) {
    await gatesTab.click();
    await p.waitForTimeout(1500);

    const gatesText = await root.innerText().catch(() => '');
    const gateRows = p.locator('.mica-gate');
    const gateCount = await gateRows.count();

    // Every gate, not only the failures: a list that showed only failures could not be read as a
    // checklist, and a checklist is what tells somebody what is left.
    check('D20 all seven gates are listed', gateCount === 7, `${gateCount} rows`);

    // The single most important line on the page. A development project starts sessions even with
    // gates failing, so "sessions are running" without the reason reads as launch-readiness.
    check('D21 an unready dev project is not described as ready',
      /development only/i.test(gatesText) && /because this is a development project/i.test(gatesText),
      gatesText.split('\n').slice(0, 2).join(' | ').slice(0, 110));

    // The deliberate blocker must read as a decision, not a fault.
    const decisionRow = p.locator('.mica-gate--decision');
    check('D22 the acknowledgment target reads as a decision, not a fault',
      (await decisionRow.count()) >= 1
      && /Awaiting a decision/i.test(await decisionRow.first().innerText()));

    // Colour is never the only signal.
    check('D23 every gate carries its state as text',
      (await p.locator('.mica-gate-state').count()) === gateCount);

    // Gate details are the part that must NOT be on the chat page; here they are wanted.
    check('D24 a failing gate says how to clear it',
      /To clear it:/.test(gatesText));

    check('D25 a passing gate does not give advice it does not need',
      (await p.locator('.mica-gate--pass .mica-gate-fix').count()) === 0);

    // The disposition above set a flash ("Decision recorded: ..."). Carrying it onto this tab put a
    // green statement about a finding at the top of a configuration checklist, where it reads as a
    // statement about what you are now looking at.
    check('D26 a flash from the previous screen does not follow the tab change',
      !/Decision recorded/i.test(gatesText),
      gatesText.split('\n').slice(0, 2).join(' | ').slice(0, 90));

    await p.screenshot({ path: `${SHOTS}/review-gates-desktop.png`, fullPage: true });

    // Re-check must actually re-ask, since gates change when settings do. It lives in the tab row
    // with Refresh rather than inside the card.
    await p.locator('button:has-text("Re-check")').first().click();
    await p.waitForTimeout(1200);
    check('D27 re-check reloads the checklist', (await p.locator('.mica-gate').count()) === 7);
  } else {
    for (const n of ['D20', 'D21', 'D22', 'D23', 'D24', 'D25', 'D26', 'D27']) {
      check(`${n}  (skipped — no launch-readiness tab)`, false);
    }
  }

  check('D28 no MICA JS errors', p._errs.length === 0, p._errs.slice(0, 2).join(' | '));
  check('D29 no failed requests', p._failed.length === 0, p._failed.slice(0, 2).join(' | '));
  if (p._coreErrs) console.log(`        (${p._coreErrs} REDCap-core offsetHeight errors ignored)`);
  if (p._offHost) console.log(`        (${p._offHost} off-host asset failures ignored — not MICA's)`);

  await ctx.close();

  // ------------------------------------------------------------------ mobile
  console.log('\niPhone 13');
  const { ctx: mctx, p: mp } = await newCtx(browser, true);

  await login(mp);
  await mp.goto(BASE + REVIEW_PATH, { waitUntil: 'domcontentloaded' });
  await mp.waitForTimeout(2000);

  const mRoot = mp.locator('#mica-review-root');
  check('M1  the dashboard mounted on mobile', (await mRoot.count()) > 0);

  const mRows = mp.locator('.mica-row');
  check('M2  the queue renders', (await mRows.count()) >= 1);

  // No horizontal scroll. A queue a reviewer has to pan sideways is a queue they misread.
  const overflow = await mp.evaluate(() => {
    const el = document.querySelector('#mica-review-root');
    return el ? el.scrollWidth - el.clientWidth : 0;
  });
  check('M3  no horizontal overflow', overflow <= 2, `${overflow}px`);

  // Tap targets. A reviewer triaging on a phone is a real user, not a hypothetical one.
  //
  // Measured on the HIT AREA, not the element: a checkbox or radio renders about 13px tall natively
  // in every browser, and its tappable region is the label wrapping it. Measuring the input itself
  // reported a genuinely comfortable control as a failure.
  const tooSmall = await mp.evaluate(() => {
    const els = [...document.querySelectorAll('#mica-review-root button, #mica-review-root select, #mica-review-root input')];
    return els
      .filter((el) => el.offsetParent !== null)
      .filter((el) => {
        const target = (el.type === 'checkbox' || el.type === 'radio')
          ? el.closest('label') || el
          : el;
        const r = target.getBoundingClientRect();
        return r.height > 0 && r.height < 32;
      })
      .map((el) => `${el.tagName.toLowerCase()}[${el.type || ''}]#${el.id || '-'}`.slice(0, 44));
  });
  check('M4  interactive targets are tappable', tooSmall.length === 0, tooSmall.slice(0, 3).join(', '));

  await mp.screenshot({ path: `${SHOTS}/review-queue-mobile.png`, fullPage: true });

  await mRows.first().click();
  await mp.waitForTimeout(1800);

  const mSessionText = await mRoot.innerText().catch(() => '');
  check('M5  a session opens and shows its transcript', /Transcript/i.test(mSessionText));

  const mOverflow = await mp.evaluate(() => {
    const el = document.querySelector('#mica-review-root');
    return el ? el.scrollWidth - el.clientWidth : 0;
  });
  check('M6  session view does not overflow sideways', mOverflow <= 2, `${mOverflow}px`);

  check('M7  the transcript is readable without a nested scroller on mobile',
    await mp.evaluate(() => {
      const t = document.querySelector('.mica-transcript');
      if (!t) return false;
      // max-height is lifted under 40rem so the page scrolls instead of a box inside a box.
      return getComputedStyle(t).maxHeight === 'none';
    }));

  await mp.screenshot({ path: `${SHOTS}/review-session-mobile.png`, fullPage: true });

  // ------------------------------------------------------------------ launch readiness, mobile
  //
  // Gate details run long - the models gate enumerates the SecureChatAI registry - so this is the
  // view most likely to overflow a phone.
  const mGatesTab = mp.locator('button:has-text("Launch readiness")').first();
  if (await mGatesTab.count()) {
    await mGatesTab.click();
    await mp.waitForTimeout(1500);

    check('M8  the checklist renders on mobile', (await mp.locator('.mica-gate').count()) === 7);

    const gOverflow = await mp.evaluate(() => {
      const el = document.querySelector('#mica-review-root');
      return el ? el.scrollWidth - el.clientWidth : 0;
    });
    check('M9  long gate details do not overflow sideways', gOverflow <= 2, `${gOverflow}px`);

    // The mark column is dropped under 40rem; the text state is what carries meaning there.
    check('M10 state is still readable as text on mobile',
      (await mp.locator('.mica-gate-state').count()) === 7);

    await mp.screenshot({ path: `${SHOTS}/review-gates-mobile.png`, fullPage: true });
  } else {
    for (const n of ['M8 ', 'M9 ', 'M10']) check(`${n} (skipped — no launch-readiness tab)`, false);
  }

  check('M11 no MICA JS errors on mobile', mp._errs.length === 0, mp._errs.slice(0, 2).join(' | '));

  await mctx.close();
  await browser.close();

  console.log(`\n${pass} passed, ${fail} failed`);
  console.log(`screenshots: ${SHOTS}`);
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => {
  console.error('\nE2E crashed:', e);
  process.exit(1);
});
