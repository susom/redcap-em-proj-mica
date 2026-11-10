// Build the `mica_transcript` instrument through REDCap's own pages, the way a designer will on prod
// (doc 33, prod steps 1-3):
//   1. Online Designer -> Upload instrument ZIP
//   2. Designate Instruments for My Events -> tick the form on each session event
//   3. Project Setup -> Repeating instruments -> make it repeating on those events
//
//   node e2e/transcript-instrument.js <pid> <zipPath> <username> <password> <eventId,eventId,...>
//
// Each step is checked by reading the page back, and screenshots go to e2e/shots/transcript-*.png.

const { chromium } = require('playwright');
const path = require('path');

const [pid, zip, user, pass, eventsArg] = process.argv.slice(2);
if (!eventsArg) {
    console.error('usage: node e2e/transcript-instrument.js <pid> <zipPath> <username> <password> <eventId,...>');
    process.exit(2);
}
const events = eventsArg.split(',').map(e => e.trim()).filter(Boolean);
const FORM = 'mica_transcript';
const base = 'http://redcap.local';
const shots = path.join(__dirname, 'shots');
let failures = 0;
const check = (ok, label) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failures++; };

(async () => {
    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1400, height: 950 } })).newPage();
    page.on('dialog', d => d.accept());

    await page.goto(`${base}/`, { waitUntil: 'networkidle' });
    await page.fill('#username', user);
    await page.fill('#password', pass);
    await Promise.all([page.waitForLoadState('networkidle'), page.click('#login_btn')]);
    const version = await page.evaluate(() => window.redcap_version);
    const web = `${base}/redcap_v${version}`;

    // 1. Upload instrument ZIP.
    await page.goto(`${web}/Design/online_designer.php?pid=${pid}`, { waitUntil: "load" });
    // REDCap appends its CSRF token to every form shortly after DOM-ready; a script that submits
    // before then is rejected (see weekly-sms-dd-upload.js).
    await page.waitForFunction(() => window.REDCap && REDCap.appendCsrfTokenToFormComplete === true, null, { timeout: 15000 });
    await page.evaluate(() => openZipInstrumentPopup());
    await page.setInputFiles('#myfile', zip);
    const uploadLabel = await page.evaluate(() => langUploadInstZip1);
    await page.locator('.ui-dialog-buttonpane button', { hasText: uploadLabel }).click();
    await page.waitForFunction(() => ['div_zip_instrument_success', 'div_zip_instrument_fail']
        .some(id => getComputedStyle(document.getElementById(id)).display !== 'none'), null, { timeout: 30000 })
        .catch(async () => { await page.screenshot({ path: path.join(shots, 'transcript-1-zip-timeout.png') }); });
    const uploaded = await page.isVisible('#div_zip_instrument_success');
    const dups = (await page.locator('#div_zip_instrument_success_dups').innerText().catch(() => '')).trim();
    await page.screenshot({ path: path.join(shots, 'transcript-1-zip-uploaded.png') });
    check(uploaded, 'instrument ZIP uploaded');
    check(dups === '', `no renamed fields${dups ? ': ' + dups : ''}`);
    await page.goto(`${web}/Design/online_designer.php?pid=${pid}`, { waitUntil: "load" });
    const formName = await page.evaluate(f => !!document.querySelector(`a[href*="page=${f}"]`), FORM);
    check(formName, `Online Designer lists "${FORM}" under its own name (not renamed)`);

    // 2. Designate the form on each event, one arm page at a time.
    await page.goto(`${web}/Design/designate_forms.php?pid=${pid}`, { waitUntil: 'networkidle' });
    const armLinks = await page.$$eval('#sub-nav a', as => as.map(a => a.href));
    const armPages = armLinks.length ? armLinks : [page.url()];
    for (const href of armPages) {
        await page.goto(href, { waitUntil: 'networkidle' });
        const here = [];
        for (const ev of events) if (await page.locator(`[id="${FORM}--${ev}"]`).count()) here.push(ev);
        if (!here.length) continue;
        await page.click('#beginEditBtn');
        for (const ev of here) await page.check(`[id="${FORM}--${ev}"]`);
        await Promise.all([page.waitForLoadState('networkidle'), page.click('#save_btn')]);
        await page.waitForTimeout(1500);
        await page.goto(href, { waitUntil: 'networkidle' });
        for (const ev of here) {
            const img = await page.getAttribute(`[id="img--${FORM}--${ev}"]`, 'src').catch(() => '');
            check(/tick/.test(img || ''), `designated on event ${ev}`);
        }
    }
    await page.screenshot({ path: path.join(shots, 'transcript-2-designated.png'), fullPage: true });

    // 3. Repeating on each of those events.
    await page.goto(`${web}/ProjectSetup/index.php?pid=${pid}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => dialogRepeatingInstance());
    await page.waitForSelector('#repeat_instance_setup_form', { timeout: 15000 });
    for (const ev of events) {
        const box = page.locator(`#repeat_instance_setup_form [name="repeat_form-${ev}-${FORM}"]`);
        const row = box.locator('xpath=ancestor::tr[1]');
        const select = row.locator('select.repeat_select');
        if (await select.count() && (await select.inputValue()) !== 'PARTIAL') {
            await select.selectOption('PARTIAL');
        }
        await box.check();
    }
    await page.screenshot({ path: path.join(shots, 'transcript-3-repeating-dialog.png') });
    const saveLabel = await page.evaluate(() => {
        const b = [...document.querySelectorAll('.ui-dialog-buttonpane button')].map(x => x.textContent.trim());
        return b[b.length - 1];
    });
    await page.locator('.ui-dialog-buttonpane button', { hasText: saveLabel }).last().click();
    await page.waitForTimeout(4000);
    await page.goto(`${web}/ProjectSetup/index.php?pid=${pid}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => dialogRepeatingInstance());
    await page.waitForSelector('#repeat_instance_setup_form', { timeout: 15000 });
    for (const ev of events) {
        const on = await page.isChecked(`#repeat_instance_setup_form [name="repeat_form-${ev}-${FORM}"]`);
        check(on, `repeating on event ${ev}`);
    }

    await browser.close();
    console.log(failures ? `\n${failures} FAILED` : '\nALL PASS');
    process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
