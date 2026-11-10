// Create the "MICA transcripts" report through REDCap's report builder, then open it, the way a
// study team member will (doc 33, prod step 6).
//
//   node e2e/transcript-report.js <pid> <username> <password>
//
// Adds record_id plus every field of `mica_transcript`, saves, opens the report and counts its
// rows. Prints the new report_id for an API export (`content=report&report_id=N`).
// Screenshots: e2e/shots/transcript-report-*.png (desktop and iPhone 13).

const { chromium, devices } = require('playwright');
const path = require('path');

const [pid, user, pass] = process.argv.slice(2);
if (!pass) {
    console.error('usage: node e2e/transcript-report.js <pid> <username> <password>');
    process.exit(2);
}
const base = 'http://redcap.local';
const shots = path.join(__dirname, 'shots');
const TITLE = 'MICA transcripts';
let failures = 0;
const check = (ok, label) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failures++; };

async function login(context) {
    const page = await context.newPage();
    page.on('dialog', d => d.accept());
    await page.goto(`${base}/`, { waitUntil: 'networkidle' });
    await page.fill('#username', user);
    await page.fill('#password', pass);
    await Promise.all([page.waitForLoadState('networkidle'), page.click('#login_btn')]);
    return page;
}

(async () => {
    const browser = await chromium.launch();
    const page = await login(await browser.newContext({ viewport: { width: 1400, height: 950 } }));
    const version = await page.evaluate(() => window.redcap_version);
    const web = `${base}/redcap_v${version}`;

    await page.goto(`${web}/DataExport/index.php?pid=${pid}&create=1&addedit=1`, { waitUntil: 'networkidle' });
    await page.fill('input[name="__TITLE__"]', TITLE);
    await page.selectOption('#add_form_field_dropdown', 'mica_transcript');
    await page.waitForTimeout(1500);
    const fields = await page.$$eval('input[name="field[]"], .field-dropdown-a', els => els.length).catch(() => 0);
    // Statement rows only. Without this the report also has one blank row per record and event.
    // The textarea opens REDCap's logic editor on focus, so the value is set directly, after the
    // same "advanced filter logic" link a person clicks.
    await page.click('#adv_logic_row_link a');
    await page.evaluate(() => { document.getElementById('advanced_logic').value = '[tx_seq] <> ""'; });
    // "Show data for all events or repeating instruments/events for each record returned" is on by
    // default and brings back each matched record's other rows (record ID only). Off = matching rows.
    await page.uncheck('input[name="filter_type"]');
    await page.screenshot({ path: path.join(shots, 'transcript-report-1-builder.png'), fullPage: true });
    await page.click('#save-report-btn');
    await page.waitForTimeout(3000);

    const reportId = await page.evaluate(async (args) => {
        const html = await (await fetch(`${args.web}/DataExport/index.php?pid=${args.pid}`)).text();
        const ids = [...html.matchAll(/report_id=(\d+)/g)].map(m => Number(m[1]));
        return ids.length ? Math.max(...ids) : null;
    }, { web, pid });
    check(reportId !== null, `report saved (report_id ${reportId}, ${fields} field controls in the builder)`);

    await page.goto(`${web}/DataExport/index.php?pid=${pid}&report_id=${reportId}`, { waitUntil: 'networkidle' });
    await page.waitForSelector('#report_table', { timeout: 60000 }).catch(() => {});
    const header = await page.$$eval('#report_table thead th', ths => ths.map(t => t.innerText.trim())).catch(() => []);
    const rows = await page.$$eval('#report_table tbody tr', trs => trs.length).catch(() => 0);
    check(rows > 0, `report shows ${rows} row(s)`);
    check(header.some(h => /tx_text/.test(h)) && header.some(h => /tx_flagged/.test(h)), 'report has the statement and highlight columns');
    await page.screenshot({ path: path.join(shots, 'transcript-report-2-view.png') });

    const phone = await login(await browser.newContext({ ...devices['iPhone 13'] }));
    await phone.goto(`${web}/DataExport/index.php?pid=${pid}&report_id=${reportId}`, { waitUntil: 'networkidle' });
    await phone.waitForSelector('#report_table', { timeout: 60000 }).catch(() => {});
    await phone.screenshot({ path: path.join(shots, 'transcript-report-3-mobile.png') });

    console.log(`REPORT_ID=${reportId}`);
    await browser.close();
    console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
    process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
