<?php

/**
 * Is this project able to produce a MICA safety finding at all?
 *
 *   docker exec <web> php /var/www/html/temp/mica/preflight-safetyscan.php
 *   MICA_PID=257 docker exec ... (or pass the pid as argv[1])
 *
 * Read-only. Changes nothing, so it is safe to run against a live study.
 *
 * Exit 0 = the pipeline can run end to end. Exit 1 = at least one link is broken, and the output says
 * which link and what to do about it.
 *
 * ## Why this exists as its own script
 *
 * The pipeline is six links long - session -> transcript -> job -> scan -> finding -> review - and a
 * break anywhere shows up at the far end as "no findings", which is indistinguishable from "nothing
 * to find". That ambiguity is the thing the whole handoff is built to prevent, so it should not be
 * how somebody discovers their project is misconfigured. `LaunchReadiness` covers the launch gates;
 * this covers the plumbing those gates assume.
 */

$PID = (int) ($argv[1] ?? getenv('MICA_PID') ?: 257);

define('NOAUTH', true);
$_GET['pid'] = (string) $PID;
require_once mica_find_redcap_connect();

function mica_find_redcap_connect(): string
{
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) {
        return "$env/redcap_connect.php";
    }
    foreach (['/var/www/html'] as $guess) {
        if (is_file("$guess/redcap_connect.php")) {
            return "$guess/redcap_connect.php";
        }
    }
    for ($d = __DIR__, $i = 0; $i < 8; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) {
            return "$d/redcap_connect.php";
        }
        if ($d === '/') {
            break;
        }
    }
    fwrite(STDERR, "Could not locate redcap_connect.php. Set REDCAP_ROOT=/path/to/redcap web root.\n");
    exit(1);
}

$blockers = [];

/** A link in the chain. `$fix` is only printed when it is broken. */
function link_(string $label, bool $ok, string $detail = '', string $fix = ''): void
{
    global $blockers;

    printf("  %-4s %-36s %s\n", $ok ? '[ok]' : '[--]', $label, $detail);

    if (!$ok) {
        $blockers[] = $label;
        if ($fix !== '') {
            foreach (explode("\n", wordwrap($fix, 88)) as $line) {
                printf("       -> %s\n", $line);
            }
        }
    }
}

function note_(string $label, string $detail): void
{
    printf("  %-4s %-36s %s\n", '    ', $label, $detail);
}

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

printf("MICA SafetyScan pipeline preflight - project %d\n\n", $PID);

// ------------------------------------------------------------------ 1. session -> transcript

echo "1. A finished session becomes a transcript\n";

link_(
    'enable-transcript-finalization',
    (bool) $module->getProjectSetting('enable-transcript-finalization', $PID),
    ((bool) $module->getProjectSetting('enable-transcript-finalization', $PID)) ? 'on' : 'OFF',
    'Tick "Finalize transcripts and queue safety scans" under External Modules > MICA > Configure. '
    . 'With it off, ending a session writes nothing and no scan is ever queued - the pipeline has no '
    . 'input at all.'
);

$map = trim((string) $module->getProjectSetting('session-host-map', $PID));
note_('session-host-map', $map === '' ? 'blank - using the R01 defaults (this is fine)' : str_replace("\n", ' ; ', $map));
note_(
    'chat_host_instruments',
    trim((string) $module->getProjectSetting('chat_host_instruments', $PID)) ?: '(default: ui_hosting_instrument)'
);

/**
 * The session engine, which is still the pilot's.
 *
 * `completeSession()` calls `calculateSessionInfo()`, which needs an event literally named
 * `baseline_arm_1` and a `consent_date` on the record at that event. The R01 structure on PID 257 has
 * neither - its arm-1 events are `day_1_ed_arm_1`, `month_3_arm_1` and so on - so every End Session
 * throws "the project is not configured for MICA sessions" before a transcript is written.
 *
 * That is Stage 2 (the R01 session engine), deliberately deferred - not a Stage 6 defect. It is
 * checked here because it is the difference between "seed a fixture" and "just have a chat", and
 * discovering it by having a chat costs an hour.
 */
$events = \REDCap::getEventNames(true, false);
$hasBaselineArm1 = (bool) array_search('baseline_arm_1', $events, true);

$consented = (int) $module->query(
    'SELECT COUNT(DISTINCT record) c FROM ' . \Records::getDataTable($PID)
    . " WHERE project_id = ? AND field_name = 'consent_date' AND value <> ''",
    [$PID]
)->fetch_assoc()['c'];

link_(
    'pilot session engine usable',
    $hasBaselineArm1 && $consented > 0,
    sprintf(
        'baseline_arm_1 event: %s; records with consent_date: %d',
        $hasBaselineArm1 ? 'present' : 'ABSENT',
        $consented
    ),
    'completeSession() needs a `baseline_arm_1` event and a `consent_date` on the record - the '
    . 'pilot\'s scaffolding. Without both, End Session throws before writing a transcript, so a real '
    . 'chat cannot drive this pipeline. Stage 2 replaces this; until then use '
    . 'e2e-review-fixture.php, which finalizes a transcript directly.'
);

// ------------------------------------------------------------------ 2. transcript -> scan

echo "\n2. The transcript gets scanned\n";

$alias = trim((string) $module->getProjectSetting('safetyscan-model-alias', $PID));
$mock = (bool) $module->getProjectSetting('scan-mock-mode', $PID);

try {
    $available = $module->getSecureChatInstance()->getAvailableModels();
} catch (\Throwable $e) {
    $available = [];
}
$available = is_array($available) ? $available : [];

link_(
    'safetyscan-model-alias set',
    $alias !== '',
    $alias !== '' ? $alias : 'UNSET',
    'Set it under External Modules > MICA > Configure. Unset means the scanner falls back to a '
    . 'hardcoded alias, which is not checked against this server\'s registry - and an unregistered '
    . 'alias returns the provider\'s canned apology rather than an error, so sessions look screened '
    . 'and are not.'
);

if ($alias !== '') {
    link_(
        '...and registered in SecureChatAI',
        in_array($alias, $available, true),
        'registry: ' . (implode(', ', $available) ?: '(empty)'),
        'Pick an alias the registry actually lists, or add it to SecureChatAI\'s api-settings.'
    );
}

note_(
    'scan-mock-mode',
    $mock
        ? 'ON - replays fixtures from disk, no model is called (fine for testing the UI, useless '
        . 'for testing the model)'
        : 'off - scans call the real model'
);
note_('safetyscan-max-attempts', (string) ($module->getProjectSetting('safetyscan-max-attempts', $PID) ?: 3));

$crons = [];
$result = $module->query("SELECT cron_name, cron_last_run_start FROM redcap_crons WHERE cron_name LIKE 'mica%'", []);
while ($row = $result->fetch_assoc()) {
    $crons[$row['cron_name']] = $row['cron_last_run_start'] ?: 'never';
}

link_(
    'mica_scan_worker cron registered',
    isset($crons['mica_scan_worker']),
    isset($crons['mica_scan_worker']) ? 'last start ' . $crons['mica_scan_worker'] : 'MISSING',
    'Re-enable the module so REDCap registers its crons, then check Control Center > Cron Job Status.'
);
foreach (['mica_digest', 'mica_ack_monitor'] as $name) {
    note_("  $name", $crons[$name] ?? 'not registered');
}

// ------------------------------------------------------------------ 3. scan -> finding

echo "\n3. The scan writes a finding\n";

$store = new \Stanford\MICA\RedcapScanResultStore($module);

link_(
    'mica_safety_finding instrument',
    $store->findingInstrumentExists((string) $PID),
    $store->findingInstrumentExists((string) $PID) ? 'present' : 'MISSING',
    'Run apply-safety-finding-instrument.php <pid>. Without the instrument the scanner refuses to '
    . 'release findings at all, and every job lands in manual_review_required.'
);

$designated = [];
$result = $module->query(
    'SELECT ef.event_id, em.descrip FROM redcap_events_forms ef '
    . 'JOIN redcap_events_repeat er ON er.event_id = ef.event_id AND er.form_name = ef.form_name '
    . 'JOIN redcap_events_metadata em ON em.event_id = ef.event_id '
    . 'JOIN redcap_events_arms ea ON ea.arm_id = em.arm_id '
    . 'WHERE ea.project_id = ? AND ef.form_name = ? ORDER BY ef.event_id',
    [$PID, 'mica_safety_finding']
);
while ($row = $result->fetch_assoc()) {
    $designated[] = $row['event_id'] . ' (' . $row['descrip'] . ')';
}

link_(
    'designated AND repeating',
    $designated !== [],
    implode(', ', $designated) ?: 'NO EVENT',
    'A finding is one instance of a repeating instrument. Designate mica_safety_finding to the '
    . 'session events and enable it as a repeating instrument there.'
);

// ------------------------------------------------------------------ 4. finding -> review

echo "\n4. Somebody can review it\n";

$roles = \Stanford\MICA\RoleService::fromModule($module, $PID);

foreach ([
    \Stanford\MICA\RoleService::RA      => 'Reviewer role mapped',
    \Stanford\MICA\RoleService::PI      => 'PI role mapped',
] as $role => $label) {
    $mapped = $roles->mappedRedcapRoles($role);
    link_(
        $label,
        $mapped !== [],
        $mapped !== [] ? 'REDCap role id(s) ' . implode(', ', $mapped) : 'UNMAPPED',
        'Map a REDCap user role under External Modules > MICA > Configure. Access follows REDCap '
        . 'roles, so with nothing mapped nobody - not even an admin - can open the dashboard.'
    );
}

$auditor = $roles->mappedRedcapRoles(\Stanford\MICA\RoleService::AUDITOR);
note_('Auditor role mapped', $auditor !== [] ? implode(', ', $auditor) : 'unmapped (optional)');

// ------------------------------------------------------------------ what is already there

echo "\n5. What this project already holds\n";

note_('scan jobs', (string) $module->query(
    'SELECT COUNT(*) c FROM redcap_entity_mica_scan_job WHERE project_id = ?',
    [$PID]
)->fetch_assoc()['c']);

// mica_scan_run has no project_id - it hangs off a job. Counted through the join rather than
// pretending the column exists.
note_('scan runs', (string) $module->query(
    'SELECT COUNT(*) c FROM redcap_entity_mica_scan_run r '
    . 'JOIN redcap_entity_mica_scan_job j ON j.id = r.job_id WHERE j.project_id = ?',
    [$PID]
)->fetch_assoc()['c']);

note_('notification rows', (string) $module->query(
    'SELECT COUNT(*) c FROM redcap_entity_mica_notification WHERE project_id = ?',
    [$PID]
)->fetch_assoc()['c']);

note_('finding instances', (string) $module->query(
    'SELECT COUNT(*) c FROM ' . \Records::getDataTable($PID) . ' WHERE project_id = ? AND field_name = ?',
    [$PID, 'finding_id']
)->fetch_assoc()['c']);

// ------------------------------------------------------------------ verdict

echo "\n";

if ($blockers === []) {
    echo "PASS - a finished session can become a reviewable finding on this project.\n";
    exit(0);
}

printf("BLOCKED - %d link(s) broken: %s\n", count($blockers), implode(', ', $blockers));
echo "\nA finding cannot be produced until those are fixed. Note that a broken link shows up at the\n";
echo "far end as \"no findings\", which looks exactly like \"nothing to find\" - which is the one\n";
echo "reading this pipeline exists to make impossible.\n";
exit(1);
