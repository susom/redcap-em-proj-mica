// AI token policy (standard / high-threshold), through the screens people actually use.
// See docs/token-policy.md.
//
//   docker exec <web> php .../secure_chat_ai_v9.9.9/tests/e2e/fixture.php setup             # mock registry + super user
//   docker exec -d <web> php -S 127.0.0.1:8099 .../secure_chat_ai_v9.9.9/tests/e2e/mock_aihub.php
//   docker exec <web> php .../scripts/e2e-token-policy-fixture.php 271 setup                 # this folder active
//   docker exec <web> php .../scripts/e2e-module-config-user.php 271 setup                   # the designer
//   node e2e/token-policy.js                 # [config] [session]; both by default
//
// session-real: the same participant session against the REAL high-threshold models (after
//   `e2e-token-policy-fixture.php 271 setup-real <counselor> <scan>`, no mock): the counselor
//   answers, the scan succeeds on the configured alias, and every SecureChatAI log row for the
//   session's calls says `token_policy: high`. Synthetic, mild text only.
//   ... e2e-module-config-user.php 271 teardown; e2e-token-policy-fixture.php 271 teardown;
//       secure_chat_ai fixture.php teardown
//
// config, desktop and iPhone 13:
//   - a SUPER USER sees the switch; ticking it swaps both model dropdowns for the high-threshold
//     lists live and unticking swaps them back; the lists are AI Hub's high-threshold deployments
//     (config.json) followed by anything else SecureChatAI has registered under high-threshold; a
//     Save stores the switch and both high-threshold aliases and leaves the standard ones alone.
//   - a DESIGNER (design rights, not a super user) never sees the switch. REDCap leaves a
//     super-users-only field out of the dialog, and a branching condition on a missing field is
//     false - so without the module's own handling they would see neither model dropdown. They see
//     the active pair, and their Save keeps every value they were not shown.
// session, desktop: a participant's counselor turn and the SafetyScan of that session both reach the
//   high-threshold model set on the high-threshold key, read off the mock AI Hub's request log - and
//   nothing reaches the standard key.
const { chromium, devices } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const BASE = process.env.MICA_BASE || 'http://redcap.local';
const V = 'redcap_v17.2.3';
const PID = 271;
const ADMIN = { user: 'e2e_sca_admin', pass: 'E2eScaAdmin!2026' };     // SecureChatAI fixture's super user
const DESIGNER = { user: 'e2e_mica_config', pass: 'E2eModCfg!2026' };  // e2e-module-config-user.php
const SCRIPTS = '/var/www/html/modules-local/' + __dirname.split('/').slice(-2, -1)[0] + '/docs/phase-3-handoff/scripts';
const MOCK_LOG = '/tmp/sca_mock_aihub.jsonl';
const LAST = 'Testerson';
const CHAT = '#chatbot_ui_container[data-bootstrap]';
const HIGH_LIVE = ['gpt-5-4', 'gpt-4-1-nano'];   // the mock's high-threshold registry, added live
// config.json's list: AI Hub's high-threshold deployments (2026-10-12).
const AI_HUB_HIGH = ['gpt-5-4-high-threshold-policy', 'gpt-5-5-high-threshold-policy', 'gpt-5-2-high-threshold-policy',
  'gpt-5-4-mini-high-threshold-policy', 'gpt-5-4-nano-high-threshold-policy', 'gpt-4-1-mini-high-threshold-policy'];
const MODES = process.argv.slice(2).length ? process.argv.slice(2) : ['config', 'session'];

const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${!ok && detail ? ' — ' + detail : ''}`);
};

// Over stdin, so no SQL quoting in a shell and no password on a command line: the container has it.
const sql = (q) => execSync(
  `docker exec -i redcap_2023_1_db sh -c 'mysql -uredcap -p"$MYSQL_PASSWORD" redcap -N 2>/dev/null'`,
  { input: q },
).toString().trim();
const setting = (key) => sql(
  `SELECT s.value FROM redcap_external_module_settings s JOIN redcap_external_modules m USING (external_module_id)
   WHERE m.directory_prefix = 'proj_mica' AND s.project_id = ${PID} AND s.\`key\` = '${key}'`,
);
const php = (code) => execSync('docker exec -i -w /var/www/html redcap_2023_1_web php', { input: code }).toString();
const wire = () => {
  const raw = execSync(`docker exec redcap_2023_1_web sh -c 'cat ${MOCK_LOG} 2>/dev/null || true'`).toString();
  return raw.split('\n').filter(Boolean).map((l) => JSON.parse(l));
};
const resetWire = () => php('<?php file_get_contents("http://127.0.0.1:8099/__reset");');

async function login(p, who) {
  await p.goto(`${BASE}/${V}/index.php`, { waitUntil: 'domcontentloaded' });
  await p.locator('input[name="username"]').first().fill(who.user);
  await p.locator('input[name="password"]').first().fill(who.pass);
  await p.locator('#login_btn').first().click();
  await p.waitForLoadState('domcontentloaded');
  return !(await p.locator('input[name="password"]').count());
}

async function openConfig(p) {
  await p.goto(`${BASE}/${V}/ExternalModules/manager/project.php?pid=${PID}`, { waitUntil: 'domcontentloaded' });
  await p.locator('tr', { hasText: 'Project MICA' }).first().locator('button:has-text("Configure")').first().click();
  const modal = p.locator('#external-modules-configure-modal');
  await modal.waitFor({ state: 'visible', timeout: 15000 });
  await modal.locator('textarea[name="safetyscan-prompt-addendum"]').waitFor({ state: 'attached', timeout: 15000 });
  return modal;
}

const row = (modal, key) => modal.locator(`tr[field="${key}"]`);
const shown = async (modal, key) => (await row(modal, key).count()) > 0 && row(modal, key).isVisible();
const options = (modal, key) => modal.locator(`select[name="${key}"] option`)
  .evaluateAll((els) => els.map((e) => ({ value: e.value, text: e.textContent.trim() })).filter((o) => o.value !== ''));

// Against the device's screen width: mobile Chrome widens the layout viewport to fit overflowing
// content, so innerWidth grows with the very overflow this is meant to catch.
const noSideScroll = (p) => p.evaluate(() => document.documentElement.scrollWidth <= screen.width + 1);

async function save(p, modal) {
  await modal.locator('button.save').click();
  await modal.waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  await p.waitForLoadState('networkidle').catch(() => {});
  await p.waitForTimeout(800);
  return !(await modal.isVisible());
}

async function superUser(p, label) {
  const modal = await openConfig(p);
  const sw = modal.locator('input[name="use-high-threshold-models"]');
  // The desktop run saves the switch on, so the phone opens on whatever is stored.
  const savedOn = setting('use-high-threshold-models') === 'true';
  check(`A1 ${label}: super user sees the switch, showing the saved state (${savedOn ? 'on' : 'off'})`,
    (await sw.count()) === 1 && (await sw.isChecked()) === savedOn);
  check(`A2 ${label}: the dialog opens on the saved state's pair`,
    (await shown(modal, savedOn ? 'llm-model-high' : 'llm-model'))
    && !(await shown(modal, savedOn ? 'llm-model' : 'llm-model-high')));
  if (savedOn) await sw.uncheck();
  check(`A2b ${label}: off shows the standard pair only`,
    await shown(modal, 'llm-model') && await shown(modal, 'safetyscan-model-alias')
    && !(await shown(modal, 'llm-model-high')) && !(await shown(modal, 'safetyscan-model-alias-high')));

  await sw.check();
  check(`A3 ${label}: ticking swaps in the high-threshold pair, live`,
    await shown(modal, 'llm-model-high') && await shown(modal, 'safetyscan-model-alias-high')
    && !(await shown(modal, 'llm-model')) && !(await shown(modal, 'safetyscan-model-alias')));
  const counselor = await options(modal, 'llm-model-high');
  const scan = await options(modal, 'safetyscan-model-alias-high');
  check(`A4 ${label}: counselor list is AI Hub's high-threshold models, then any other SecureChatAI has registered`,
    JSON.stringify(counselor.map((o) => o.value)) === JSON.stringify([...AI_HUB_HIGH, ...HIGH_LIVE]),
    JSON.stringify(counselor.map((o) => o.value)));
  check(`A5 ${label}: SafetyScan list is the same, structured-output models only`,
    JSON.stringify(scan.map((o) => o.value)) === JSON.stringify([...AI_HUB_HIGH, ...HIGH_LIVE]),
    JSON.stringify(scan.map((o) => o.value)));
  check(`A6 ${label}: each entry says it is high-threshold, and live ones say where they come from`,
    [...counselor, ...scan].every((o) => (AI_HUB_HIGH.includes(o.value)
      ? /\(high-threshold/.test(o.text)
      : /registered in SecureChatAI as high-threshold/.test(o.text))),
    JSON.stringify(counselor));
  const helpText = await row(modal, 'use-high-threshold-models').innerText();
  check(`A7 ${label}: the switch says it moves both calls and never falls back`,
    /both/.test(helpText) && /nothing is ever sent to a standard model/.test(helpText));
  const modalRight = await modal.locator('.modal-dialog').evaluate((e) => e.getBoundingClientRect().right);
  const rights = await Promise.all(['use-high-threshold-models', 'llm-model-high', 'safetyscan-model-alias-high']
    .map((k) => row(modal, k).evaluate((e) => e.getBoundingClientRect().right)));
  check(`A8 ${label}: the new rows stay inside the dialog`, rights.every((r) => r <= modalRight + 1),
    JSON.stringify({ modalRight, rights }));
  check(`A9 ${label}: no sideways scroll`, await noSideScroll(p),
    String(await p.evaluate(() => document.documentElement.scrollWidth + ' > ' + screen.width)));
  await row(modal, 'use-high-threshold-models').scrollIntoViewIfNeeded();
  await p.screenshot({ path: `${SHOTS}/token-policy-switch-on-${label}.png` });

  await sw.uncheck();
  check(`A10 ${label}: unticking swaps the standard pair back`,
    await shown(modal, 'llm-model') && !(await shown(modal, 'llm-model-high')));

  if (label !== 'desktop') return;
  await sw.check();
  await modal.locator('select[name="llm-model-high"]').selectOption('gpt-5-4');
  await modal.locator('select[name="safetyscan-model-alias-high"]').selectOption('gpt-5-4');
  check(`A11 ${label}: Save goes through`, await save(p, modal));
  check(`A12 ${label}: switch and both high-threshold aliases stored, standard ones untouched`,
    setting('use-high-threshold-models') === 'true' && setting('llm-model-high') === 'gpt-5-4'
    && setting('safetyscan-model-alias-high') === 'gpt-5-4'
    && setting('llm-model') === 'gpt-5-4' && setting('safetyscan-model-alias') === 'gpt-5-4',
    JSON.stringify(['use-high-threshold-models', 'llm-model-high', 'safetyscan-model-alias-high', 'llm-model',
      'safetyscan-model-alias'].map(setting)));
  const reopened = await openConfig(p);
  check(`A13 ${label}: reopened, the dialog shows what was saved`,
    await reopened.locator('input[name="use-high-threshold-models"]').isChecked()
    && (await reopened.locator('select[name="llm-model-high"]').inputValue()) === 'gpt-5-4'
    && await shown(reopened, 'llm-model-high') && !(await shown(reopened, 'llm-model')));
}

async function designer(p, label) {
  // Runs after the super user's Save: the switch is on.
  const modal = await openConfig(p);
  check(`B1 ${label}: designer does not see the switch`,
    (await modal.locator('input[name="use-high-threshold-models"]').count()) === 0);
  check(`B2 ${label}: designer sees the active (high-threshold) pair, visible, with its saved values`,
    await shown(modal, 'llm-model-high') && await shown(modal, 'safetyscan-model-alias-high')
    && (await modal.locator('select[name="llm-model-high"]').inputValue()) === 'gpt-5-4'
    && (await modal.locator('select[name="safetyscan-model-alias-high"]').inputValue()) === 'gpt-5-4');
  check(`B3 ${label}: designer is not offered the inactive standard pair`,
    (await modal.locator('select[name="llm-model"], select[name="safetyscan-model-alias"]').count()) === 0);
  check(`B4 ${label}: no sideways scroll`, await noSideScroll(p));
  await row(modal, 'safetyscan-model-alias-high').scrollIntoViewIfNeeded();
  await p.screenshot({ path: `${SHOTS}/token-policy-designer-${label}.png` });
  if (label !== 'desktop') return;
  await modal.locator('select[name="llm-model-high"]').selectOption('gpt-4-1-nano');
  check(`B5 ${label}: designer Save goes through`, await save(p, modal));
  check(`B6 ${label}: designer changed the high-threshold model; switch and standard aliases kept`,
    setting('llm-model-high') === 'gpt-4-1-nano' && setting('use-high-threshold-models') === 'true'
    && setting('llm-model') === 'gpt-5-4' && setting('safetyscan-model-alias') === 'gpt-5-4',
    JSON.stringify(['llm-model-high', 'use-high-threshold-models', 'llm-model', 'safetyscan-model-alias'].map(setting)));
  sql(`UPDATE redcap_external_module_settings s JOIN redcap_external_modules m USING (external_module_id)
       SET s.value = 'gpt-5-4' WHERE m.directory_prefix = 'proj_mica' AND s.project_id = ${PID} AND s.\`key\` = 'llm-model-high'`);
}

async function config() {
  for (const mobile of [false, true]) {
    const label = mobile ? 'mobile' : 'desktop';
    console.log(`\n=== config, ${label} ===`);
    for (const [who, fn] of [[ADMIN, superUser], [DESIGNER, designer]]) {
      const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
      const ctx = await browser.newContext(mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } });
      const p = await ctx.newPage();
      p.on('dialog', (d) => d.dismiss().catch(() => {}));
      try {
        check(`L ${label}: ${who.user} signs in`, await login(p, who));
        await fn(p, label);
      } catch (e) {
        check(`${label}: ${who.user} run completed`, false, String(e).slice(0, 300));
        await p.screenshot({ path: `${SHOTS}/token-policy-error-${label}.png` }).catch(() => {});
      }
      await browser.close();
    }
  }
}

// ------------------------------------------------------------------------------------- session

// SecureChatAI's own log: one row per model call, with the policy it ran under.
const SCA_LOG = (since) => sql(
  `SELECT CONCAT_WS('|', l.log_id, JSON_UNQUOTE(JSON_EXTRACT(l.message, '$.token_policy')), JSON_UNQUOTE(JSON_EXTRACT(l.message, '$.model')))
   FROM redcap_external_modules_log l JOIN redcap_external_modules m USING (external_module_id)
   WHERE m.directory_prefix = 'secure_chat_ai' AND l.project_id = ${PID} AND l.log_id > ${since} AND JSON_VALID(l.message)
   ORDER BY l.log_id`,
).split('\n').filter(Boolean).map((r) => { const [id, policy, model] = r.split('|'); return { id, policy, model }; });
const lastScaLog = () => Number(sql(`SELECT IFNULL(MAX(log_id), 0) FROM redcap_external_modules_log`)) || 0;

async function seedRandomized(browser) {
  for (let i = 1; i <= 6; i++) {
    const rec = `TPOL${String(Date.now()).slice(-6)}${i}`;
    const out = execSync(`docker exec -w /var/www/html redcap_2023_1_web php ${SCRIPTS}/seed-rand-test.php ${PID} ${rec}`).toString();
    if (!/saveData errors: \[\]/.test(out)) throw new Error('seed failed: ' + out.slice(0, 300));
    const tsr = php(`<?php $_GET['pid']=${PID}; define('NOAUTH',true); require 'redcap_connect.php';
      $P = new Project(${PID}); foreach ($P->eventInfo as $id => $e) { if ((int)$e['arm_num'] === 1) { $ev = $id; break; } }
      echo REDCap::getSurveyLink('${rec}', 'tsr', $ev);`).trim();
    const p = await (await browser.newContext({ viewport: { width: 1400, height: 950 } })).newPage();
    await p.goto(tsr, { waitUntil: 'networkidle' });
    await p.locator('input[name="alc_tx___radio"][value="0"]').check();
    await Promise.all([p.waitForLoadState('load'), p.locator('button[name="submit-btn-saverecord"]').click()]);
    await p.waitForLoadState('networkidle');
    await p.close();
    const field = (f) => sql(`SELECT value FROM redcap_data7 WHERE project_id=${PID} AND record='${rec}' AND field_name='${f}' LIMIT 1`);
    if (/^[23]$/.test(field('study_group')) && /^https?:\/\//.test(field('ed_session_url'))) {
      return { rec, link: field('ed_session_url'), tries: i };
    }
    console.log(`     ${rec}: allocated Standard Care (no session); seeding another`);
  }
  throw new Error('no session-arm allocation in 6 tries');
}

async function session(real = false) {
  const counselorAlias = setting('llm-model-high');
  const scanAlias = setting('safetyscan-model-alias-high');
  console.log(`\n=== session${real ? ' (REAL high-threshold models)' : ''}, desktop (switch on) ===`);
  check(`S0 switch is on; counselor ${counselorAlias}, scan ${scanAlias}`,
    setting('use-high-threshold-models') === 'true'
    && (real ? /high-threshold-policy$/.test(counselorAlias) && /high-threshold-policy$/.test(scanAlias)
      : counselorAlias === 'gpt-5-4' && scanAlias === 'gpt-5-4'));
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const { rec, link } = await seedRandomized(browser);
  console.log(`     participant ${rec}`);
  if (!sql(`SELECT value FROM redcap_data7 WHERE project_id=${PID} AND record='${rec}' AND field_name='last_name' LIMIT 1`)) {
    php(`<?php $_GET['pid']=${PID}; define('NOAUTH',true); require 'redcap_connect.php';
      $r = REDCap::saveData(['project_id'=>${PID}, 'dataFormat'=>'json',
        'data'=>json_encode([['record_id'=>'${rec}','redcap_event_name'=>'day_1_ed_arm_1','last_name'=>'${LAST}']])]);
      if ($r['errors']) { fwrite(STDERR, json_encode($r['errors'])); exit(1); }`);
  }

  if (!real) resetWire();
  const since = lastScaLog();
  const p = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  await p.goto(link, { waitUntil: 'domcontentloaded' });
  await p.waitForTimeout(2500);
  const lastName = p.locator('input[name="last_name"]').first();
  if (await lastName.count()) {
    await lastName.fill(LAST);
    await p.getByRole('button', { name: /log ?in/i }).first().click();
    await p.waitForLoadState('domcontentloaded');
    await p.waitForTimeout(4000);
  }
  const composer = p.locator(`${CHAT} textarea, ${CHAT} input:not([type=hidden]), ${CHAT} [contenteditable]`).first();
  check('S1 the chat opens', (await composer.count()) > 0);
  await composer.fill(real
    ? 'Hi. I had a few drinks at a party last weekend and I would like to talk about cutting back a bit.'
    : 'E2E token policy: a synthetic first message.');
  const before = (await p.locator(CHAT).innerText()).length;
  const answered = p.waitForResponse((r) => /prefix=proj_mica/.test(r.url()) && /callAI/.test(r.request().postData() || ''),
    { timeout: 120000 }).catch(() => null);
  const btns = p.locator(`${CHAT} button`);
  await btns.nth((await btns.count()) - 1).click({ force: true });
  const r = await answered;
  await p.waitForTimeout(2000);
  const body = r ? await r.text().catch(() => '') : '';
  check('S2 the counselor answered (no provider_error)',
    !!r && r.status() === 200 && !/provider_error\\?"\s*:\s*true/.test(body), body.slice(0, 200));
  if (real) {
    const turn = SCA_LOG(since);
    const grew = (await p.locator(CHAT).innerText()).length - before;
    check('S3 the counselor\'s reply is on screen', grew > 40, `chat text grew by ${grew} characters`);
    // Two rows per turn by design: SecureChatAI's audit row and its conversation row.
    check('S4 SecureChatAI logged the turn under the high-threshold policy',
      turn.length >= 1 && turn.every((t) => t.policy === 'high'), JSON.stringify(turn));
  } else {
    check('S3 the answer on screen came from the high-threshold set on the high-threshold key',
      (await p.locator(CHAT).innerText()).includes('path=/high/gpt-5-4 key=e2e-high-key'), body.slice(0, 200));
    const turn = wire();
    check('S4 the turn reached AI Hub once, at /high/gpt-5-4 with the high-threshold key',
      turn.length === 1 && turn[0].path === '/high/gpt-5-4' && turn[0].key === 'e2e-high-key', JSON.stringify(turn));
  }
  await p.screenshot({ path: `${SHOTS}/token-policy-session${real ? '-real' : ''}.png`, fullPage: true });

  await p.locator('button.end_session').first().click();
  await p.waitForTimeout(1200);
  await p.locator('button', { hasText: /^End session$/ }).first().click();
  await p.waitForTimeout(4000);
  await browser.close();

  if (real) {
    // The scan runs on MICA's one-minute cron; wait for its first run row.
    let run = '';
    const t0 = Date.now();
    while (Date.now() - t0 < 420000) {
      run = sql(`SELECT CONCAT_WS('|', r.run_status, r.model_alias, IFNULL(r.resolved_model, ''), j.status)
        FROM redcap_entity_mica_scan_run r JOIN redcap_entity_mica_scan_job j ON j.id = r.job_id
        WHERE j.project_id = ${PID} AND j.record = '${rec}' ORDER BY r.id DESC LIMIT 1`);
      if (run) break;
      await new Promise((res) => setTimeout(res, 10000));
    }
    const [status, alias, resolved, jobStatus] = run.split('|');
    check('S5 the SafetyScan ran on the high-threshold alias', alias === scanAlias, run);
    check('S6 the SafetyScan succeeded (structured, schema-valid answer)', status === 'ok', run);
    const calls = SCA_LOG(since);
    check('S7 every SecureChatAI call in the session ran under the high-threshold policy',
      calls.length >= 2 && calls.every((c) => c.policy === 'high'), JSON.stringify(calls));
    console.log(`     scan: ${status} on ${alias} (resolved ${resolved}); job ${jobStatus}`);
    return rec;
  }

  // The scan runs on MICA's one-minute cron.
  let scans = [];
  const t0 = Date.now();
  while (Date.now() - t0 < 300000) {
    scans = wire().filter((w) => w.body_keys.includes('response_format'));
    if (scans.length) break;
    await new Promise((res) => setTimeout(res, 10000));
  }
  const job = sql(`SELECT CONCAT(id, ' ', status) FROM redcap_entity_mica_scan_job WHERE project_id=${PID} AND record='${rec}' ORDER BY id DESC LIMIT 1`);
  check('S5 End Session queued a scan job', job !== '', job);
  check('S6 the SafetyScan reached the high-threshold set on the high-threshold key',
    scans.length > 0 && scans.every((w) => w.path === '/high/gpt-5-4' && w.key === 'e2e-high-key'), JSON.stringify(scans));
  check('S7 nothing reached the standard key', wire().every((w) => w.key === 'e2e-high-key'), JSON.stringify(wire()));
  check('S8 token_policy never went upstream', wire().every((w) => !w.raw_has_token_policy));
  console.log(`     scan job ${job}; the mock answers a scan with a non-scan JSON, so a failed run is expected`);
  return rec;
}

(async () => {
  if (MODES.includes('config')) await config();
  if (MODES.includes('session') || MODES.includes('session-real')) {
    try {
      const rec = await session(MODES.includes('session-real'));
      console.log(`\n  synthetic participant left for cleanup: ${rec}`);
    } catch (e) {
      check('session run completed', false, String(e).slice(0, 300));
    }
  }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
