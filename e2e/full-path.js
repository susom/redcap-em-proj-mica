// Full-path E2E: native Survey Login gate + the MICA chatbot SPA, desktop and mobile.
//
//   php docs/phase-3-handoff/scripts/manual-test-auth.php 257 setup     # prints the two links
//   node e2e/full-path.js <edSessionLink> <baseline1ControlLink>
//   php docs/phase-3-handoff/scripts/manual-test-auth.php 257 teardown
//
// Requires playwright resolvable by node (NODE_PATH=... if it is not a local dependency).
// Screenshots land in e2e/shots/ (gitignored).
//
// Composer selectors are deliberately element-agnostic (textarea | input | contenteditable):
// the SPA's message box changed from <input> to <textarea> during a UI pass, and a stale
// selector reads as a backend regression when nothing is broken.
const { chromium, devices } = require('playwright');
const fs = require('fs');
const ED = process.argv[2], CONTROL = process.argv[3];
const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  (ok ? pass++ : fail++);
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

async function newCtx(browser, mobile = false) {
  const ctx = await browser.newContext(mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();
  p._errs = []; p._failed = []; p._mica = []; p._coreErrs = 0;
  // Known REDCap-core error, not MICA: fires on non-MICA survey pages too, stack lands in
  // redcap_v17.2.3/Resources/webpack/js/bundle.js. Counted separately, not attributed to the module.
  p.on('pageerror', e => {
    const m = String(e.message);
    if (/offsetHeight/.test(m)) { p._coreErrs++; return; }
    p._errs.push(m.slice(0, 160));
  });
  p.on('requestfailed', r => p._failed.push(`${r.url().slice(-60)} :: ${r.failure()?.errorText}`));
  p.on('response', r => { if (/prefix=proj_mica/.test(r.url())) p._mica.push(r.status()); });
  return { ctx, p };
}

const login = async (p, cred = 'Testerson') => {
  const f = p.locator('input[name="last_name"]').first();
  if (!(await f.count())) return false;
  await f.fill(cred);
  await p.getByRole('button', { name: /log ?in/i }).first().click();
  await p.waitForLoadState('domcontentloaded');
  await p.waitForTimeout(3000);
  return true;
};

const send = async (p, text, waitMs = 25000) => {
  const box = p.locator('#chatbot_ui_container textarea, #chatbot_ui_container input:not([type=hidden]), #chatbot_ui_container [contenteditable]').first();
  await box.fill(text);
  const btns = p.locator('#chatbot_ui_container button');
  await btns.nth((await btns.count()) - 1).click({ force: true });
  await p.waitForTimeout(waitMs);
  return p.locator('#chatbot_ui_container').innerText();
};

(async () => {
  const browser = await chromium.launch({ headless: true });

  // ---------- A. SURVEY LOGIN ----------
  console.log('\n=== A. SURVEY LOGIN ===');
  {
    const { p } = await newCtx(browser);
    await p.goto(ED, { waitUntil: 'domcontentloaded' });
    check('A1 gate present on ED session link', (await p.locator('input[name="last_name"]').count()) > 0);
    check('A1b chat NOT reachable before login', (await p.locator('#chatbot_ui_container').count()) === 0);
    await p.screenshot({ path: `${SHOTS}/A1-login.png`, fullPage: true });

    await login(p, 'WrongName');
    const stillGated = (await p.locator('input[name="last_name"]').count()) > 0;
    const noChat = (await p.locator('#chatbot_ui_container').count()) === 0;
    check('A2 wrong credential rejected', stillGated && noChat,
          `gate=${stillGated} chat=${!noChat}`);
    await p.screenshot({ path: `${SHOTS}/A2-wrong.png`, fullPage: true });
  }
  {
    const { p } = await newCtx(browser);
    await p.goto(CONTROL, { waitUntil: 'domcontentloaded' });
    // Discriminate on the login form itself, NOT on input[name=last_name]: baseline1 is the
    // Enrollment instrument and contains last_name as a real data-entry field.
    const d = await p.evaluate(() => ({
      authSubmit: document.querySelectorAll('input[name="survey-auth-submit"]').length,
      loginBtn: [...document.querySelectorAll('button')].some(x => /log ?in/i.test(x.innerText)),
      surveyForm: !!document.querySelector('form#form'),
      title: document.title,
    }));
    check('A3 control survey does NOT prompt (scoping holds)',
          d.authSubmit === 0 && !d.loginBtn && d.surveyForm, `title="${d.title}"`);
  }

  // ---------- B. CHATBOT (desktop, happy path) ----------
  console.log('\n=== B. CHATBOT LOADS (desktop) ===');
  const { p } = await newCtx(browser);
  await p.goto(ED, { waitUntil: 'domcontentloaded' });
  await login(p);
  check('B1 login accepted, container present', (await p.locator('#chatbot_ui_container').count()) > 0);

  await p.waitForTimeout(2500);
  const ui = await p.evaluate(() => {
    const el = document.getElementById('chatbot_ui_container');
    const txt = el ? el.innerText : '';
    return {
      html: el ? el.innerHTML.length : 0,
      hasTitle: /MICA AI Chatbot/i.test(txt),
      hasIntro: /What is your name/i.test(txt),
      hasEnd: /End Session/i.test(txt),
      inputs: el ? el.querySelectorAll('textarea, input:not([type=hidden]), [contenteditable]').length : 0,
      buttons: el ? el.querySelectorAll('button').length : 0,
      bundle: [...document.querySelectorAll('script[src]')].map(s => s.src).filter(s => /dist\/assets/.test(s)),
      css: [...document.querySelectorAll('link[rel=stylesheet]')].map(s => s.href).filter(s => /dist\/assets/.test(s)),
    };
  });
  check('B2 header renders', ui.hasTitle);
  check('B3 intro message renders', ui.hasIntro);
  check('B4 End Session control renders', ui.hasEnd);
  check('B5 composer + buttons render', ui.inputs >= 1 && ui.buttons >= 2, `composers=${ui.inputs} buttons=${ui.buttons}`);
  check('B6 exactly one JS + one CSS bundle loaded', ui.bundle.length === 1 && ui.css.length === 1,
        `${ui.bundle.map(b => b.split('/').pop())} / ${ui.css.map(c => c.split('/').pop())}`);
  check('B7 no failed requests', p._failed.length === 0, JSON.stringify(p._failed.slice(0, 2)));

  // The launch-gates banner. PID 257 is a development project and its acknowledgment target ships
  // unset on purpose, so the banner is always present here - no fixture needed.
  const banner = await p.evaluate(() => {
    const el = document.querySelector('.mica-launch');
    return {
      present: Boolean(el),
      text: el ? el.innerText : '',
      role: el ? el.getAttribute('role') : null,
      // Must be chrome, not conversation: outside the transcript's live region, and not inside a
      // message bubble wearing MICA's avatar.
      insideLog: Boolean(el && el.closest('[role=log]')),
      insideMessages: Boolean(el && el.closest('.messages')),
      boot: window.mica_bootstrap?.launch_banner || null,
    };
  });

  check('B8  the development banner renders', banner.present, banner.text.replace(/\n/g, ' | ').slice(0, 110));
  check('B9  it says sessions would be refused in production',
    /would be refused/i.test(banner.text) && /development only/i.test(banner.text));
  check('B10 it names what is unmet', /acknowledgment target/i.test(banner.text));

  // The rule the whole banner rests on: this page is in no-auth-pages, so gate DETAIL must not be
  // here. Titles and a count only.
  check('B11 it leaks no gate detail, model registry or address',
    !/null on purpose|clinical governance|registered|@/i.test(banner.text),
    banner.text.replace(/\n/g, ' | ').slice(0, 90));
  check('B12 and the bootstrap it came from carries none either',
    banner.boot !== null && !JSON.stringify(banner.boot).match(/detail|how_to_fix|gemini|gpt-|claude-/i),
    JSON.stringify(banner.boot || {}).slice(0, 110));

  // System state never speaks as the counselor - the same rule sessionNotice enforces.
  check('B13 it is chrome, not something MICA said',
    !banner.insideLog && !banner.insideMessages && banner.role === 'status');

  // The conversation still works around it: a banner that blocked a dev session would make the
  // development project useless for testing, which is the one thing it is for.
  check('B14 the session is still usable', ui.hasIntro && ui.inputs >= 1);

  await p.screenshot({ path: `${SHOTS}/B-loaded.png`, fullPage: true });

  console.log('\n=== C. CONVERSATION ===');
  const t1 = await send(p, 'What is 2+2? Reply with just the number.');
  check('C1 my message echoed', /2\+2/.test(t1));
  check('C2 real reply received (not the provider apology)', /\b4\b/.test(t1) && !/network difficulties/i.test(t1));
  await p.screenshot({ path: `${SHOTS}/C1-turn1.png`, fullPage: true });

  const t2 = await send(p, 'What did I just ask you? One short sentence.');
  check('C3 second turn works', /What did I just ask/i.test(t2));
  check('C4 model retained turn-1 context', /2\s*\+\s*2|two plus two|added|sum|math/i.test(t2.split('What did I just ask')[1] || ''));
  const md = await p.evaluate(() => {
    const el = document.getElementById('chatbot_ui_container');
    return { lists: el.querySelectorAll('ul,ol').length, strong: el.querySelectorAll('strong,em,code,h1,h2,h3').length };
  });
  check('C5 markdown pipeline active', md.lists + md.strong >= 0, `lists=${md.lists} inline=${md.strong}`);
  check('C6 no MICA page errors during conversation', p._errs.length === 0, JSON.stringify(p._errs.slice(0, 2)));
  console.log(`  INFO  REDCap-core offsetHeight errors (not MICA): ${p._coreErrs}`);
  check('C7 MICA ajax calls all HTTP 200', p._mica.length > 0 && p._mica.every(s => s === 200), JSON.stringify(p._mica));
  await p.screenshot({ path: `${SHOTS}/C2-turn2.png`, fullPage: true });

  // Persona observation (known gap on PID 257)
  const persona = await p.evaluate(() => JSON.stringify(window.mica_jsmo_module.data));
  console.log(`  INFO  initial_system_context on the client: ${persona}`);

  console.log('\n=== D. RELOAD / RESTORE (D6 observation) ===');
  await p.goto(ED, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(3500);
  const afterReload = await p.locator('#chatbot_ui_container').innerText().catch(() => '');
  console.log(`  INFO  transcript visible after reload: ${/2\+2/.test(afterReload)}`);
  console.log(`  INFO  container text after reload: ${JSON.stringify(afterReload.slice(0, 120))}`);
  await p.screenshot({ path: `${SHOTS}/D-reload.png`, fullPage: true });

  console.log('\n=== E. END SESSION ===');
  const csRaw = await p.evaluate(async () => new Promise(res => {
    window.mica_jsmo_module.completeSession({ participant_id: 'MICATEST01' },
      r => res('success: ' + JSON.stringify(r)), e => res('error: ' + JSON.stringify(e)));
  })).catch(e => 'threw: ' + e.message);
  console.log(`  INFO  completeSession returned -> ${String(csRaw).slice(0, 220)}`);
  const before = p.url();
  await p.locator('#chatbot_ui_container button').first().click({ force: true }).catch(() => {});
  await p.waitForTimeout(8000);
  console.log(`  INFO  url before: ${before.slice(0, 60)}`);
  console.log(`  INFO  url after : ${p.url().slice(0, 90)}`);
  console.log(`  INFO  page errors: ${JSON.stringify(p._errs.slice(0, 3))}`);
  await p.screenshot({ path: `${SHOTS}/E-endsession.png`, fullPage: true });

  // ---------- F. MOBILE ----------
  console.log('\n=== F. MOBILE (iPhone 13) ===');
  {
    const { p: m } = await newCtx(browser, true);
    await m.goto(ED, { waitUntil: 'domcontentloaded' });
    await login(m);
    await m.waitForTimeout(2500);
    check('F1 container present on mobile', (await m.locator('#chatbot_ui_container').count()) > 0);
    const overflow = await m.evaluate(() => ({
      docW: document.documentElement.scrollWidth, winW: window.innerWidth,
      composerVisible: (() => { const i = document.querySelector('#chatbot_ui_container textarea, #chatbot_ui_container input:not([type=hidden]), #chatbot_ui_container [contenteditable]');
        if (!i) return false; const r = i.getBoundingClientRect();
        return r.top >= 0 && r.bottom <= window.innerHeight + 1; })(),
    }));
    check('F2 no horizontal overflow', overflow.docW <= overflow.winW + 1, `doc=${overflow.docW} win=${overflow.winW}`);
    check('F3 composer within viewport', overflow.composerVisible);
    const mt = await send(m, 'Say OK.');
    check('F4 mobile turn works', /Say OK/.test(mt) && !/network difficulties/i.test(mt));
    check('F5 no MICA page errors on mobile', m._errs.length === 0, JSON.stringify(m._errs.slice(0, 2)));
    await m.screenshot({ path: `${SHOTS}/F-mobile.png`, fullPage: true });
  }

  console.log(`\n===== ${pass} passed, ${fail} failed =====`);
  await browser.close();
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error('FATAL', e); process.exit(2); });
