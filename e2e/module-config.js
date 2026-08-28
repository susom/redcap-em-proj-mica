// Module-configuration E2E: the read-only SafetyScan prompt panel in the module's own settings
// dialog, desktop and mobile.
//
//   docker exec <web> php .../scripts/e2e-module-config-user.php 257 setup     # prints creds + URL
//   node e2e/module-config.js
//   docker exec <web> php .../scripts/e2e-module-config-user.php 257 teardown
//
// The panel is rendered by `redcap_module_configuration_settings()` (see PinnedPromptView), and
// almost nothing about it is checkable from PHP. `descriptive` is a client-side-only setting type -
// its entire render lives in `ExternalModules/manager/js/globals.js`, which drops the setting's
// `name` into a `<label>` as raw HTML. So whether the markup survives that, whether a `<details>`
// nested inside a `<label>` still toggles, and whether 4.4 KB of prompt stays inside a modal are all
// browser questions. A unit test proves the string; this proves the surface.
//
// Signs in as a DESIGN-RIGHTS user, not an admin: hasProjectSettingSavePermission() short-circuits
// to true for a super user, so an admin run cannot tell you whether an ordinary study designer -
// the person who actually writes the addendum - can open the dialog at all.
const { chromium, devices } = require('playwright');
const fs = require('fs');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const BASE = process.env.MICA_BASE || 'http://redcap.local';
const USER = process.env.MICA_E2E_CONFIG_USER || 'e2e_mica_config';
const PASS = process.env.MICA_E2E_CONFIG_PASS || 'E2eModCfg!2026';
const PID = process.env.MICA_PID || '257';
const EM_PATH = `/redcap_v17.2.3/ExternalModules/manager/project.php?pid=${PID}`;

// The pin itself. Hard-coded on purpose: reading it from handoff/manifest.json would make the spec
// agree with whatever the manifest currently says, which is the one thing it is here to check.
const PROMPT_SHA = '8aa8f9de53297e09cb2929cc1c100ed7bf6a1abdcaf7218e00a4f9b1867e9786';

const SHOTS = __dirname + '/shots';
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

let pass = 0, fail = 0;
const check = (name, ok, detail = '') => {
  ok ? pass++ : fail++;
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
};

async function login(p) {
  await p.goto(`${BASE}/redcap_v17.2.3/index.php`, { waitUntil: 'domcontentloaded' });
  await p.locator('input[name="username"]').first().fill(USER);
  // #login_btn, not [type=submit]: REDCap's button carries an id and no type.
  await p.locator('input[name="password"]').first().fill(PASS);
  await p.locator('#login_btn').first().click();
  await p.waitForLoadState('domcontentloaded');
  return !(await p.locator('input[name="password"]').count());
}

async function openConfig(p) {
  await p.goto(BASE + EM_PATH, { waitUntil: 'domcontentloaded' });
  const row = p.locator('tr', { hasText: 'Project MICA' }).first();
  await row.locator('button:has-text("Configure")').first().click();
  await p.locator('#external-modules-configure-modal').waitFor({ state: 'visible', timeout: 15000 });
  // The settings are fetched from manager/ajax/get-settings.php and rendered client-side, so a
  // visible modal is not yet a rendered panel.
  await p.locator('#external-modules-configure-modal textarea[name="safetyscan-prompt-addendum"]')
    .waitFor({ state: 'attached', timeout: 15000 });
}

async function run(mobile) {
  const label = mobile ? 'mobile' : 'desktop';
  console.log(`\n=== ${label} ===`);
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const ctx = await browser.newContext(
    mobile ? { ...devices['iPhone 13'] } : { viewport: { width: 1400, height: 950 } },
  );
  const p = await ctx.newPage();
  const errs = [];
  // Same REDCap-core noise both other suites document; counted, never attributed to the module.
  p.on('pageerror', (e) => {
    if (!/offsetHeight/.test(String(e.message))) errs.push(String(e.message).slice(0, 160));
  });

  check(`C1  ${label}: an ordinary design-rights user can sign in`, await login(p));
  await openConfig(p);

  const modal = p.locator('#external-modules-configure-modal');
  check(`C2  ${label}: the configuration dialog opens for that user`, await modal.isVisible());

  const details = modal.locator('details').filter({ hasText: 'Show the full prompt' }).first();
  check(`C3  ${label}: the pinned-prompt panel is present`, (await details.count()) === 1);

  const bodyText = await modal.innerText();
  // The label is injected unescaped, so the tags must have been parsed rather than displayed.
  check(`C4  ${label}: the panel's markup is parsed, not shown as text`,
    bodyText.includes('the validated instructions in force') && !bodyText.includes('<b>'));
  check(`C5  ${label}: the full 64-char artifact sha256 is shown`, bodyText.includes(PROMPT_SHA));
  // config.json's placeholder text. If this is visible, the hook did not run and the panel is a lie.
  check(`C6  ${label}: config.json's fallback text is NOT what rendered`, !bodyText.includes('did not run'));

  check(`C7  ${label}: collapsed by default`, (await details.evaluate((el) => el.open)) === false);
  const pre = details.locator('pre').first();
  check(`C8  ${label}: the prompt body is hidden while collapsed`, !(await pre.isVisible()));

  // The load-bearing interaction: globals.js wraps the label around this, and a <label> can swallow
  // the click that would toggle a <summary>.
  await details.locator('summary').first().click();
  await p.waitForTimeout(350);
  check(`C9  ${label}: clicking the summary expands it`, (await details.evaluate((el) => el.open)) === true);
  check(`C10 ${label}: the prompt body is visible once expanded`, await pre.isVisible());

  const preText = await pre.innerText();
  check(`C11 ${label}: the first line of the artifact is shown`,
    preText.startsWith('SAFETYSCAN POST-SESSION SYSTEM INSTRUCTIONS'));
  // The last line is the one an addendum is appended after, so a truncated panel would hide exactly
  // the sentence the addendum field's own help text is about.
  check(`C12 ${label}: the last line of the artifact is shown`,
    preText.trim().endsWith('Return only the JSON object required by the schema.'));

  const box = await pre.evaluate((el) => ({
    h: el.clientHeight, scrollH: el.scrollHeight, ow: el.offsetWidth, sw: el.scrollWidth,
  }));
  check(`C13 ${label}: the prompt box is height-bounded`, box.h <= 340,
    `clientHeight ${box.h}px of ${box.scrollH}px`);
  check(`C14 ${label}: the prompt box scrolls rather than stretching the dialog`, box.scrollH > box.h);
  // white-space:pre-wrap + word-break, so no line of the prompt forces a sideways scroll.
  check(`C15 ${label}: no horizontal overflow inside the prompt box`, box.sw <= box.ow + 1,
    `${box.sw} vs ${box.ow}`);

  /*
   * Differential, not absolute. On a 390px viewport REDCap's own settings modal overflows by 476px
   * with this panel, without it, and with the entire settings table display:none - it is the dialog's
   * chrome and its fixed-width table, both of which predate this panel and are not the module's to
   * fix. An absolute assertion here fails for a reason the module cannot cause. What the panel must
   * not do is make it worse.
   */
  const spill = await modal.evaluate((el) => {
    const before = el.scrollWidth;
    const row = Array.from(el.querySelectorAll('tr'))
      .find((r) => r.innerText.includes('Show the full prompt'));
    row.style.display = 'none';
    const without = el.scrollWidth;
    row.style.display = '';
    return { added: before - without, client: el.clientWidth, before };
  });
  check(`C16 ${label}: the panel adds no width to the dialog`, spill.added <= 1,
    `+${spill.added}px (dialog ${spill.before}px wide in a ${spill.client}px viewport, panel-independent)`);

  const addendum = modal.locator('textarea[name="safetyscan-prompt-addendum"]');
  check(`C17 ${label}: the addendum field is still present and editable`,
    (await addendum.count()) === 1 && (await addendum.first().isEditable()));
  const order = await modal.evaluate(() => {
    const rows = Array.from(document.querySelectorAll('#external-modules-configure-modal tr'));
    return {
      panel: rows.findIndex((r) => r.innerText.includes('Show the full prompt')),
      addendum: rows.findIndex((r) => r.querySelector('textarea[name="safetyscan-prompt-addendum"]')),
    };
  });
  check(`C18 ${label}: the panel sits directly above the addendum field`,
    order.panel >= 0 && order.addendum === order.panel + 1,
    `panel row ${order.panel}, addendum row ${order.addendum}`);

  // A descriptive setting must render no input, which is why Save posts nothing for it. Asserted
  // here because it is the browser-visible cause of "the anchor stores no value" - the stored-value
  // half is checked by verify-settings.php, which can see the settings table.
  check(`C19 ${label}: the anchor renders no input, so Save posts nothing for it`,
    (await modal.locator('[name="safetyscan-prompt-pinned-view"]').count()) === 0);

  await p.screenshot({ path: `${SHOTS}/module-config-${label}.png` });
  check(`C20 ${label}: no page JS errors`, errs.length === 0, errs.join(' | '));

  await browser.close();
}

/**
 * The one thing an administrator actually does in this dialog.
 *
 * This spec is otherwise read-only, and this function is the exception: it WRITES
 * `safetyscan-prompt-addendum`. It reads the current value first and writes it back through the same
 * dialog at the end, so a project with a configured addendum is left as it was found - but a run
 * interrupted between the two saves leaves the marker text in the setting, which is why the marker
 * says what it is. Adding a setting to the section is exactly the change that could break the save
 * path for its neighbours, so a panel that renders and then costs the study its addendum would be a
 * worse outcome than no panel.
 *
 * Desktop only: Save is not viewport-dependent, and writing the setting twice proves nothing twice.
 */
async function saveRoundTrip() {
  console.log('\n=== save round-trip (desktop, WRITES the addendum setting) ===');
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 950 } });
  const p = await ctx.newPage();

  const field = () => p.locator('#external-modules-configure-modal textarea[name="safetyscan-prompt-addendum"]');
  const save = async () => {
    await p.locator('#external-modules-configure-modal button:has-text("Save"), '
      + '#external-modules-configure-modal input[value="Save"]').first().click();
    // The dialog saves over ajax and reloads the manager page.
    await p.waitForLoadState('load', { timeout: 20000 }).catch(() => {});
    await p.waitForTimeout(2000);
  };

  await login(p);
  await openConfig(p);
  const original = await field().inputValue();

  const marker = 'E2E module-config probe — safe to delete. Flag mentions of a firearm at home.';
  await field().fill(marker);
  await save();

  await openConfig(p);
  check('C21 the addendum still saves and reloads with the panel in the section',
    (await field().inputValue()) === marker);
  // A save must not cost the panel: it is rendered per dialog open, so a broken hook would show up
  // here as config.json's fallback rather than as an error.
  const afterSave = await p.locator('#external-modules-configure-modal').innerText();
  check('C22 the panel still renders the artifact after a save',
    afterSave.includes(PROMPT_SHA) && !afterSave.includes('did not run'));

  await field().fill(original);
  await save();
  await openConfig(p);
  check('C23 the original addendum value is restored', (await field().inputValue()) === original,
    original === '' ? 'was blank, left blank' : `${original.length} chars`);

  await browser.close();
}

(async () => {
  await run(false);
  await run(true);
  await saveRoundTrip();
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
