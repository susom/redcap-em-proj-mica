// Upload a Data Dictionary through REDCap's own Data Dictionary page, the way a study designer on
// prod will: choose file -> Upload -> read REDCap's change preview -> Commit.
//
//   node e2e/weekly-sms-dd-upload.js <pid> <csvPath> <username> <password> [--no-commit]
//
// Proves patch-weekly-sms-data-dictionary.php's output is accepted by REDCap's validator, and
// captures the preview a prod user should expect to see. --no-commit stops at the preview.

const { chromium } = require('playwright');
const path = require('path');

const args = process.argv.slice(2).filter(a => !a.startsWith('--'));
const noCommit = process.argv.includes('--no-commit');
const [pid, csv, user, pass] = args;
if (!pass) {
    console.error('usage: node e2e/weekly-sms-dd-upload.js <pid> <csvPath> <username> <password> [--no-commit]');
    process.exit(2);
}
const base = 'http://redcap.local';
const shots = path.join(__dirname, 'shots');

(async () => {
    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1400, height: 950 } })).newPage();
    page.on('dialog', d => d.accept());

    await page.goto(`${base}/`, { waitUntil: 'networkidle' });
    await page.fill('#username', user);
    await page.fill('#password', pass);
    await Promise.all([page.waitForLoadState('networkidle'), page.click('#login_btn')]);
    const version = await page.evaluate(() => window.redcap_version || '17.2.3');

    await page.goto(`${base}/redcap_v${version}/Design/data_dictionary_upload.php?pid=${pid}`, { waitUntil: 'networkidle' });
    await page.setInputFiles('input[name="uploadedfile"]', csv);
    await Promise.all([page.waitForLoadState('networkidle'), page.click('#submit')]);

    const preview = (await page.locator('body').innerText()).replace(/[ \t]+/g, ' ');
    await page.screenshot({ path: path.join(shots, 'weekly-sms-dd-preview.png'), fullPage: true });
    const hasErrors = /error/i.test(preview) && !(await page.locator('button[name="commit"]').count());
    console.log('--- REDCap preview (excerpt) ---');
    console.log(preview.split('\n').filter(l => /field|change|new|modif|delet|error|warn|commit/i.test(l)).slice(0, 40).join('\n'));

    if (hasErrors) { console.log('\nREDCap REJECTED the file - no Commit button.'); await browser.close(); process.exit(1); }
    if (noCommit) { console.log('\n--no-commit: stopped at the preview.'); await browser.close(); process.exit(0); }

    // REDCap appends its CSRF token to every form 100 ms after DOM-ready (base.js
    // appendCsrfTokenToForm) and sets this flag when done. A human never beats it; a script can,
    // and then the commit is rejected as CSRF.
    await page.waitForFunction(() => window.REDCap && REDCap.appendCsrfTokenToFormComplete === true, null, { timeout: 15000 });
    const tokenOnForm = await page.evaluate(() => !!document.forms.form?.querySelector('[name="redcap_csrf_token"]'));
    if (!tokenOnForm) console.log('  (commit form has no CSRF token - REDCap will reject it)');

    await Promise.all([page.waitForLoadState('networkidle'), page.click('button[name="commit"]')]);
    const done = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
    await page.screenshot({ path: path.join(shots, 'weekly-sms-dd-committed.png'), fullPage: true });
    await browser.close();

    // "successfully blocked" is REDCap's CSRF rejection wording - test for it before anything else.
    const csrf = /Cross-site Request Forgery/i.test(done);
    // A crash page contains words like "success" in the page chrome, so check for it first:
    // on 2026-09-27 a commit died in MetaData::createDataDictionarySnapshot() (file storage 403)
    // and this line still printed OK.
    const crashed = /fatal error|REDCap crashed/i.test(done);
    const committed = !csrf && !crashed && /changes .{0,40}(made|committed)|success/i.test(done);
    console.log(`\ncommit: ${csrf ? 'REJECTED (CSRF)' : crashed ? 'CRASHED (fatal error - nothing committed)' : committed ? 'OK' : 'UNCLEAR'} - ${done.match(/.{0,160}(Cross-site|success|committed|have been made).{0,160}/i)?.[0] || done.slice(0, 300)}`);
    process.exit(committed ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
