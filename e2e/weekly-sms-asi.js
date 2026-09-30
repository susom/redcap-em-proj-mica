// The weekly-SMS Automated Survey Invitation as REDCap's own Online Designer shows it.
//
//   node e2e/weekly-sms-asi.js <pid> <surveyId> <eventId> <username> <password>
//
// The ASI is created by SQL (apply-weekly-sms-asi.php). This proves REDCap's UI loads that row and
// reads back every setting that makes the weekly cadence work - and the screenshot is the
// field-by-field template for building the same ASI by hand on a project where the script can't run.
//
// Signs in as a DESIGN-rights user (e2e-module-config-user.php): ASI setup is gated on design rights,
// which is what a study designer on prod has.

const { chromium } = require('playwright');
const path = require('path');

const [pid, surveyId, eventId, user, pass] = process.argv.slice(2);
if (!pass) {
    console.error('usage: node e2e/weekly-sms-asi.js <pid> <surveyId> <eventId> <username> <password>');
    process.exit(2);
}
const base = 'http://redcap.local';
const shot = path.join(__dirname, 'shots', 'weekly-sms-asi-dialog.png');

(async () => {
    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1400, height: 1100 } })).newPage();

    await page.goto(`${base}/`, { waitUntil: 'networkidle' });
    await page.fill('#username', user);
    await page.fill('#password', pass);
    await Promise.all([page.waitForLoadState('networkidle'), page.click('#login_btn')]);

    const version = await page.evaluate(() => (window.redcap_version || ''));
    const designer = `${base}/redcap_v${version || '17.2.3'}/Design/online_designer.php?pid=${pid}`;
    await page.goto(designer, { waitUntil: 'networkidle' });

    // The same call the "Automated Invitations" button makes.
    await page.evaluate(([s, e]) => setUpConditionalInvites(s, e, 'sunday'), [surveyId, eventId]);
    const dialog = page.locator('.ui-dialog:visible').last();
    await dialog.waitFor({ timeout: 15000 });
    await page.waitForTimeout(1000);
    await dialog.screenshot({ path: shot });

    // Read back the controls that carry the weekly behaviour. REDCap suffixes every control id with
    // `-<survey_id>-<event_id>`; the radios are positional (sscondwhen: immediately / next day /
    // time lag / exact; ssrepeat: once / recurring; ssactive: active / not active).
    const read = await page.evaluate(([s, e]) => {
        const k = `${s}-${e}`;
        const el = id => document.getElementById(id);
        const radios = n => [...document.querySelectorAll(`input[name="${n}-${k}"]`)].map(r => r.checked);
        return {
            active:              radios('ssactive')[0],
            delivery_type:       document.querySelector('.ui-dialog select[name="delivery_type"]')?.value,
            subject:             el(`sssubj-${k}`)?.value,
            andor:               el(`sscondoption-andor-${k}`)?.value,
            logic_enabled:       el(`sscondoption-logic-${k}`)?.checked,
            condition_logic:     el(`sscondlogic-${k}`)?.value.replace(/\s+/g, ' '),
            reeval_before_send:  el(`sscondoption-reeval_before_send-${k}`)?.checked,
            send_after_lag:      radios('sscondwhen')[2],
            lag_days:            el(`sscond-timelagdays-${k}`)?.value,
            lag_field_after:     el(`sscond-timelagfieldafter-${k}`)?.value,
            lag_field:           el(`sscond-timelagfield-${k}`)?.value,
            recurring:           radios('ssrepeat')[1],
            num_recurrence:      el(`ssrepeat-num-${k}`)?.value,
            units_recurrence:    el(`ssrepeat-units-${k}`)?.value,
            max_recurrence:      el(`ssrepeat-max-${k}`)?.value,
        };
    }, [surveyId, eventId]);
    await browser.close();

    console.log(`screenshot: ${shot}`);
    for (const [k, v] of Object.entries(read)) console.log(`  ${k.padEnd(20)} ${JSON.stringify(v)}`);
    const expect = {
        emailTypeWithEsmsSubject: read.delivery_type === 'EMAIL' && /@ESMS/.test(read.subject || ''),
        conditionAndReeval:       read.andor === 'AND' && read.logic_enabled && read.reeval_before_send,
        sixDaysAfterFirstMonday:  read.send_after_lag && read.lag_days === '6' && read.lag_field_after === 'after'
                                  && /\[first_monday_1200\]/.test(read.lag_field || ''),
        weeklyTwelveTimes:        read.recurring && read.num_recurrence === '7' && read.units_recurrence === 'DAYS'
                                  && read.max_recurrence === '12',
    };
    console.log('\nchecks:', expect);
    process.exit(Object.values(expect).every(Boolean) ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
