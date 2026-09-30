// Stratified randomization E2E on a replica of prod (randomization stratified on rand_strata, trigger
// option 2 on `tsr`, logic `[day_1_ed_arm_1][tsr_complete]=2`).
//
//   node e2e/rand-strata.js upload <pid> <rid> <allocation.csv>   # Randomization page, development table
//   docker exec <web> php .../scripts/seed-strata-test.php <pid> <record> high|low|incomplete
//   node e2e/rand-strata.js tsr <pid> <record>                    # submit tsr as the participant
//
// `upload` signs in as the throwaway project creator (e2e-admin-form-user.php ... --create-projects).
// `tsr` needs no login: it opens the record's tsr survey link the way the auto-continue chain would.
// Every verdict is read from the database: rand_strata as saved by the survey (set by the
// @IF/@SETVALUE when the page loads), study_group at Arm 1, the allocation slot and its stratum, and
// REDCap's own "Randomize record" log line - which is the only place a failure is recorded.
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const HOST_RULES = process.env.MICA_HOST_RULES || 'MAP redcap.local 127.0.0.1';
const BASE = process.env.MICA_BASE || 'http://redcap.local';
const USER = process.env.MICA_E2E_USER || 'e2e_admin_form';
const PASS = process.env.MICA_E2E_PASS || 'E2eAdminForm!2026';
const V = 'redcap_v17.2.3';
const SHOTS = path.join(__dirname, 'shots');
if (!fs.existsSync(SHOTS)) fs.mkdirSync(SHOTS, { recursive: true });

const sql = (q) => execSync(
  `docker exec redcap_2023_1_db mysql -uredcap -predcap123 redcap -N -e ${JSON.stringify(q)} 2>/dev/null`,
).toString().trim();
// Single-quoted for the shell, so `$_GET` reaches PHP intact. `code` must not contain a single quote.
const php = (pid, code) => execSync(
  `docker exec -w /var/www/html redcap_2023_1_web php -r '$_GET["pid"]=${pid}; define("NOAUTH",true); require "redcap_connect.php"; ${code}'`,
).toString().trim();

async function upload(pid, rid, csv) {
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const p = await (await browser.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
  await p.goto(`${BASE}/${V}/index.php`, { waitUntil: 'domcontentloaded' });
  await p.fill('input[name="username"]', USER);
  await p.fill('input[name="password"]', PASS);
  await Promise.all([p.waitForLoadState('domcontentloaded'), p.click('#login_btn')]);
  await p.waitForTimeout(1200);
  await p.goto(`${BASE}/${V}/Randomization/index.php?pid=${pid}&rid=${rid}`, { waitUntil: 'networkidle' });
  await p.setInputFiles('#allocFileDev', csv);
  await p.waitForFunction(() => window.REDCap && REDCap.appendCsrfTokenToFormComplete === true, null, { timeout: 15000 }).catch(() => {});
  await Promise.all([p.waitForLoadState('networkidle'), p.click('#uploadFileBtn')]);
  const text = (await p.locator('body').innerText()).replace(/\s+/g, ' ');
  await p.screenshot({ path: `${SHOTS}/rand-strata-upload.png`, fullPage: true });
  await browser.close();
  const err = text.match(/.{0,80}(error|could not|invalid|not valid).{0,160}/i)?.[0];
  console.log(`upload page: ${/fatal error|REDCap crashed/i.test(text) ? 'CRASHED' : err ? 'MESSAGE - ' + err : 'ok'}`);
  console.log('development allocations by stratum/group:');
  console.log(sql(`SELECT CONCAT('  stratum ', source_field1, ' group ', target_field, ': ', COUNT(*)) FROM redcap_randomization_allocation WHERE rid=${rid} AND project_status=0 GROUP BY source_field1, target_field ORDER BY 1`));
}

async function tsr(pid, record) {
  const ev = sql(`SELECT e.event_id FROM redcap_events_metadata e JOIN redcap_events_arms a ON a.arm_id=e.arm_id WHERE a.project_id=${pid} AND a.arm_num=1 ORDER BY e.day_offset, e.event_id LIMIT 1`);
  const link = php(pid, `echo REDCap::getSurveyLink(${JSON.stringify(record)}, "tsr", ${ev});`);
  const browser = await chromium.launch({ args: [`--host-resolver-rules=${HOST_RULES}`] });
  const p = await (await browser.newContext({ viewport: { width: 1400, height: 950 } })).newPage();
  await p.goto(link, { waitUntil: 'networkidle' });
  await p.locator('input[name="alc_tx___radio"][value="0"]').check();      // "No" hides the rest of tsr
  await Promise.all([p.waitForLoadState('load'), p.locator('button[name="submit-btn-saverecord"]').first().click()]);
  await p.waitForLoadState('networkidle');
  await browser.close();
  const dt = sql(`SELECT data_table FROM redcap_projects WHERE project_id=${pid}`);
  const lt = sql(`SELECT log_event_table FROM redcap_projects WHERE project_id=${pid}`);
  const v = (f) => sql(`SELECT value FROM ${dt} WHERE project_id=${pid} AND record='${record}' AND event_id=${ev} AND field_name='${f}' LIMIT 1`);
  const slot = sql(`SELECT CONCAT('aid ', a.aid, ' (stratum ', a.source_field1, ', group ', a.target_field, ')') FROM redcap_randomization_allocation a JOIN redcap_randomization r USING(rid) WHERE r.project_id=${pid} AND a.is_used_by='${record}'`);
  const logRows = sql(`SELECT description FROM ${lt} WHERE project_id=${pid} AND pk='${record}' AND description LIKE 'Randomize%' ORDER BY log_event_id`);
  const arms = sql(`SELECT GROUP_CONCAT(arm ORDER BY arm) FROM redcap_record_list WHERE project_id=${pid} AND record='${record}'`);
  console.log(`record ${record}: audit_score='${v('audit_score')}' rand_strata='${v('rand_strata')}' tsr_complete='${v('tsr_complete')}' study_group@arm1='${v('study_group')}' slot=${slot || '-'} arms=${arms}`);
  console.log(`  REDCap log: ${logRows ? logRows.replace(/\n/g, ' | ') : '(no randomization entry)'}`);
}

(async () => {
  const [cmd, a1, a2, a3] = process.argv.slice(2);
  if (cmd === 'upload') await upload(a1, a2, a3);
  else if (cmd === 'tsr') await tsr(a1, a2);
  else { console.error('usage: see header'); process.exit(2); }
})().catch((e) => { console.error(e); process.exit(1); });
