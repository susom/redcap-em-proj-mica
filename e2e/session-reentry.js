// Regression: a finished session cannot be walked back into.
//
//   php docs/phase-3-handoff/scripts/seed-rand-test.php 268 REENTRY01   # then consent + randomize
//   node e2e/session-reentry.js <edSessionLink> [lastName]
//
// The participant must have been randomized into arm 2 or 3. A Standard Care allocation has no
// session by design, and its `ed_session_url` points at the arm-1 `close` survey - so A1 fails
// with "the session opens and is usable" and it looks like a regression when it is the control
// group working correctly. With the balanced 1:1:1 dev table, one seed in three lands on SC;
// check `study_group` before blaming the test.
//
// Guards the bug in docs/session-lifecycle/README.md: End Session left the host instrument's
// form status unwritten - REDCap refuses `<form>_complete` for a survey response (`survey_403`,
// no bypass) - so `sessionIsClosed()` always said "open" and the browser Back button handed the
// participant a live chat, whose turns were appended to a transcript that had already been
// finalized and scanned.
//
// Three properties, and the third is the one that matters: the UI blocking is cosmetic, the
// SERVER refusing is the fix. A stale tab never sees the terminal notice.
//
// Deliberately does NOT assert that MICA replies. The local instance usually has no registered
// model, and this test is about whether a turn is ACCEPTED, which is observable without one.
const { chromium } = require('playwright');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const LINK = process.argv[2];
const LAST = process.argv[3] || process.env.MICA_E2E_LAST_NAME || 'Testerson';
const CHAT = '#chatbot_ui_container[data-bootstrap]';

if (!LINK) {
    console.error('usage: node e2e/session-reentry.js <edSessionLink> [lastName]');
    process.exit(2);
}

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
    ok ? pass++ : fail++;
    console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

const composer = p => p.locator(`${CHAT} textarea, ${CHAT} input:not([type=hidden]), ${CHAT} [contenteditable]`).first();

(async () => {
    const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
    const p = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();

    const turns = [];
    p.on('response', async r => {
        if (!/prefix=proj_mica/.test(r.url())) return;
        let body = ''; try { body = (await r.text()).slice(0, 300); } catch (e) { body = ''; }
        turns.push({ status: r.status(), body });
    });

    // ---------- A. reach a live session ----------
    await p.goto(LINK, { waitUntil: 'domcontentloaded' });
    await p.waitForTimeout(2000);
    const login = p.locator('input[name="last_name"]').first();
    if (await login.count()) {
        await login.fill(LAST);
        await p.getByRole('button', { name: /log ?in/i }).first().click();
        await p.waitForLoadState('domcontentloaded');
        await p.waitForTimeout(4000);
    }
    check('A1 the session opens and is usable', await composer(p).count() > 0);

    // ---------- B. a turn is accepted while the session is open ----------
    // The guard must not block live sessions; without this the rest passes trivially.
    turns.length = 0;
    await composer(p).fill('Hello, I would like to talk about my drinking.');
    const btns = p.locator(`${CHAT} button`);
    await btns.nth((await btns.count()) - 1).click({ force: true });
    await p.waitForTimeout(30000);
    check('B1 a turn is accepted BEFORE ending', turns.some(t => !/already completed/i.test(t.body)),
        `${turns.length} call(s)`);

    // ---------- C. end it ----------
    const sessionUrl = p.url();
    await p.locator('button.end_session').first().click();
    await p.waitForTimeout(1200);
    await p.locator('button', { hasText: /^End session$/ }).first().click();
    await p.waitForTimeout(8000);
    check('C1 the participant is sent onward', p.url() !== sessionUrl);

    // ---------- D. back button ----------
    await p.goBack({ waitUntil: 'domcontentloaded' }).catch(() => {});
    await p.waitForTimeout(6000);
    const bodyTxt = (await p.locator('body').innerText()).replace(/\s+/g, ' ');
    check('D1 no composer after going back', await composer(p).count() === 0);
    check('D2 no End Session button after going back', await p.locator('button.end_session').count() === 0);
    check('D3 the terminal notice is shown', /already completed|session is complete/i.test(bodyTxt));

    // ---------- E. the server, with no help from the UI ----------
    // What a stale tab or a replayed request does. If this regresses, the UI checks above
    // will still pass and the transcript will still be corruptible.
    const probe = await p.evaluate(async () => {
        if (typeof window.mica_jsmo_module?.ajax !== 'function') return 'no-jsmo';
        try {
            return JSON.stringify(await window.mica_jsmo_module.ajax('callAI', {
                messages: [{ role: 'user', content: 'Replaying a turn into a finished session.' }],
            })).slice(0, 400);
        } catch (e) { return String(e).slice(0, 400); }
    });
    check('E1 the server refuses a replayed turn', /already completed/i.test(probe),
        probe === 'no-jsmo' ? 'JSMO not on the page — could not probe' : '');

    console.log(`\n  ${pass} passed, ${fail} failed`);
    await browser.close();
    process.exit(fail ? 1 : 0);
})();
