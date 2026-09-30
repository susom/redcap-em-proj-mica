// The Arm-3 weekly check-in (`sunday`) as a participant's browser renders it.
//
//   node e2e/weekly-sms-sunday.js <sundaySurveyLink> <label> [lastName]
//
// <label> prefixes the screenshots (e.g. `before`, `after`). [lastName] answers the native
// Survey Login gate if the project has it on for this survey.
//
// The weekly SMS itself is delivered as a text conversation by the Enhanced SMS Conversation module,
// not through this page - but the module walks the same metadata, and REDCap refuses to render the
// survey at all when `sunday`'s branching names events the project does not have. So "the page
// renders and pipes the participant's name" is the browser-visible proof that the rewrite took.
//
// Exit code 0 = renders cleanly; 1 = REDCap's "SURVEY ERRORS EXIST" page or an unpiped token.

const { chromium, devices } = require('playwright');
const path = require('path');

const [link, label = 'run', lastName] = process.argv.slice(2);
if (!link) {
    console.error('usage: node e2e/weekly-sms-sunday.js <sundaySurveyLink> <label> [lastName]');
    process.exit(2);
}
const shots = path.join(__dirname, 'shots');

async function render(browser, name, contextOpts) {
    const ctx = await browser.newContext(contextOpts);
    const page = await ctx.newPage();
    await page.goto(link, { waitUntil: 'networkidle' });

    // Survey Login gate, if present.
    if (lastName && await page.locator('#survey_auth_field1, input[name="survey-auth-field1"]').count()) {
        await page.locator('#survey_auth_field1, input[name="survey-auth-field1"]').first().fill(lastName);
        await Promise.all([page.waitForLoadState('networkidle'), page.keyboard.press('Enter')]);
    }

    const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
    const file = path.join(shots, `weekly-sms-${label}-${name}.png`);
    await page.screenshot({ path: file, fullPage: true });

    // Horizontal overflow on mobile is a layout defect worth failing on, not just looking at.
    const overflow = await page.evaluate(() =>
        document.documentElement.scrollWidth - document.documentElement.clientWidth);

    await ctx.close();
    return { body, file, overflow };
}

(async () => {
    const browser = await chromium.launch();
    const results = {
        desktop: await render(browser, 'desktop', { viewport: { width: 1400, height: 950 } }),
        mobile:  await render(browser, 'mobile', devices['iPhone 13']),
    };
    await browser.close();

    let failed = false;
    for (const [name, r] of Object.entries(results)) {
        const errors   = /SURVEY ERRORS EXIST/i.test(r.body);
        const unpiped  = r.body.match(/\[[a-z0-9_]+\](\[[a-z0-9_]+\])?/gi) || [];
        const tram     = /TRAM/.test(r.body);
        const ok = !errors && unpiped.length === 0 && !tram && r.overflow <= 0;
        failed = failed || !ok;

        console.log(`\n=== ${name} ${ok ? 'PASS' : 'FAIL'} ===`);
        console.log(`  screenshot        ${r.file}`);
        console.log(`  "SURVEY ERRORS"   ${errors ? 'PRESENT' : 'absent'}`);
        console.log(`  unpiped tokens    ${unpiped.length ? unpiped.join(' ') : 'none'}`);
        console.log(`  "TRAM" branding   ${tram ? 'PRESENT' : 'absent'}`);
        console.log(`  horizontal overflow ${r.overflow}px`);
        console.log(`  text: ${r.body.slice(0, 600)}`);
    }
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
