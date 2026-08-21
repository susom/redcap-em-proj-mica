<?php

/**
 * Does every MICA setting on this project actually do what its label says?
 *
 *   docker exec <web> php .../verify-settings.php [pid]
 *
 * Read-only. Exit 0 = nothing broken; 1 = at least one setting is ineffective or contradicts itself.
 *
 * ## Why this is not answerable by grepping config.json
 *
 * A setting can be wrong in five different ways, and only the first is visible statically:
 *
 *   1. declared and never read;
 *   2. read, but the value names something that does not exist (a model alias absent from the
 *      SecureChatAI registry, a REDCap role id that was deleted, an instrument that was renamed);
 *   3. read, valid, and inert on THIS project (the pilot's `session_2`..`session_7` contexts on an
 *      R01 project, `reasoning-effort` on a Claude model);
 *   4. read behind an early return, so it is configured, correct, and never consulted - which is how
 *      `chatbot_system_context_general` was set for weeks while the chatbot introduced itself as
 *      Claude;
 *   5. its own label disagrees with its `required` flag, so the form demands a value the code treats
 *      as optional.
 *
 * The static direction is already covered: no setting is read that config.json does not declare, and
 * the `chatbot_system_context_*` keys that appear unreferenced are read through a concatenated key at
 * `MICA.php` (`"chatbot_system_context_" . $session_key`). This script covers the other four.
 */

$PID = (int) ($argv[1] ?? getenv('MICA_PID') ?: 257);

$_GET['pid'] = (string) $PID;
define('NOAUTH', true);
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
    fwrite(STDERR, "Could not locate redcap_connect.php.\n");
    exit(1);
}

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

$problems = [];

function ok_(string $key, string $detail = ''): void
{
    printf("  [ok]  %-32s %s\n", $key, $detail);
}

function bad_(string $key, string $detail, string $fix = ''): void
{
    global $problems;
    $problems[] = $key;
    printf("  [--]  %-32s %s\n", $key, $detail);
    if ($fix !== '') {
        foreach (explode("\n", wordwrap($fix, 84)) as $line) {
            printf("        -> %s\n", $line);
        }
    }
}

/** Configured, valid, and doing nothing on this project. Reported, never counted as broken. */
function inert_(string $key, string $detail): void
{
    printf("  [--]  %-32s INERT: %s\n", $key, $detail);
}

function note_(string $key, string $detail): void
{
    printf("        %-32s %s\n", $key, $detail);
}

$get = static fn(string $k) => $module->getProjectSetting($k, $PID);
$str = static fn(string $k) => trim((string) $module->getProjectSetting($k, $PID));

$project = new \Project($PID);
$fields = array_keys($project->metadata ?? []);
$forms = array_keys($project->forms ?? []);

printf("MICA settings audit - project %d\n\n", $PID);

// ------------------------------------------------------------------ chatbot UI + hosts

echo "1. Chat hosting and participant-visible copy\n";

$hosts = array_values(array_filter(array_map('trim', explode(',', $str('chat_host_instruments')))));
if ($hosts === []) {
    note_('chat_host_instruments', 'unset - defaults to ui_hosting_instrument');
    $hosts = ['ui_hosting_instrument'];
}
$missingHosts = array_values(array_diff($hosts, $forms));
$missingHosts === []
    ? ok_('chat_host_instruments', implode(', ', $hosts))
    : bad_(
        'chat_host_instruments',
        'names instrument(s) that do not exist on this project: ' . implode(', ', $missingHosts),
        'The chat UI is injected by matching this list against the instrument being rendered, so a '
        . 'name that does not exist means the chatbot never appears on it.'
    );

foreach (['chatbot_intro_text' => 'the first thing a participant reads',
          'chatbot_end_session_text' => 'the End Session reminder'] as $key => $what) {
    $str($key) !== ''
        ? ok_($key, substr($str($key), 0, 48))
        : note_($key, "unset - a built-in default is used ($what)");
}

$override = $str('chatbot_end_session_url_override');
if ($override === '') {
    note_(
        'chatbot_end_session_url_override',
        'unset - a finished session shows the completion notice and stops, which is the intended '
        . 'behaviour on a project with no post-session survey'
    );
} elseif (!filter_var($override, FILTER_VALIDATE_URL)) {
    bad_('chatbot_end_session_url_override', "not a URL: \"$override\"", 'A participant is redirected here the moment they finish.');
} else {
    ok_('chatbot_end_session_url_override', $override);
}

foreach (['session_length_days', 'number_session_callback'] as $key) {
    $v = $get($key);
    if ($v === null || $v === '') {
        note_($key, 'unset - built-in default');
    } elseif (!is_numeric($v)) {
        bad_($key, "not numeric: \"$v\"");
    } else {
        ok_($key, (string) $v);
    }
}

$inject = array_values(array_filter(array_map('trim', explode(',', $str('chatbot_redcap_inject')))));
if ($inject === []) {
    note_('chatbot_redcap_inject', 'unset - no instrument data is injected into the chat context');
} else {
    $missing = array_values(array_diff($inject, $forms));
    $missing === []
        ? ok_('chatbot_redcap_inject', implode(', ', $inject))
        : bad_('chatbot_redcap_inject', 'names instrument(s) that do not exist: ' . implode(', ', $missing));
}

// ------------------------------------------------------------------ the persona

echo "\n2. The system context that actually reaches the model\n";

/**
 * Resolved through the real code path, not by reading the settings.
 *
 * This is the check that would have caught the persona bug: the settings were configured and correct,
 * and `getSystemContextForRecord()` returned before reading them, so the model was called with no
 * system prompt and answered as itself.
 */
$sampleRecord = $module->query(
    'SELECT record FROM ' . \Records::getDataTable($PID) . ' WHERE project_id = ? ORDER BY record LIMIT 1',
    [$PID]
)->fetch_assoc()['record'] ?? null;

$general = $str('chatbot_system_context_general');
$general !== ''
    ? ok_('chatbot_system_context_general', substr($general, 0, 48))
    : bad_(
        'chatbot_system_context_general',
        'unset - the model is given no persona or guardrails at all',
        'This is the setting that makes the chatbot MICA rather than the underlying model.'
    );

if ($sampleRecord === null) {
    note_('effective system context', 'no record on this project to resolve one for');
} else {
    foreach ($hosts as $host) {
        try {
            $ctx = $module->getSystemContextForRecord($sampleRecord, $host);
            $msgs = is_array($ctx) ? ($ctx['system_context'] ?? []) : [];
            $chars = 0;
            foreach ($msgs as $m) {
                $chars += strlen((string) ($m['content'] ?? ''));
            }

            $chars > 0
                ? ok_("via $host", sprintf(
                    '%d message(s), %d chars, session key "%s"',
                    count($msgs),
                    $chars,
                    (string) ($ctx['currentSession'] ?? '?')
                ))
                : bad_(
                    "via $host",
                    'resolves to an EMPTY system context, so the model is called with no persona',
                    'The settings above are configured; something on the path from '
                    . 'getSystemContextForRecord() to the bootstrap is dropping them.'
                );
        } catch (\Throwable $e) {
            note_("via $host", 'gated: ' . substr($e->getMessage(), 0, 60));
        }
    }
}

// The pilot's cadence contexts, which an R01 project can never reach.
$pilotEvent = (bool) array_search('baseline_arm_1', \REDCap::getEventNames(true, false), true);
$setCadence = [];
for ($i = 2; $i <= 7; $i++) {
    if ($str("chatbot_system_context_session_$i") !== '') {
        $setCadence[] = "session_$i";
    }
}
if (!$pilotEvent && $setCadence !== []) {
    inert_(
        'chatbot_system_context_session_*',
        sprintf(
            '%s configured, but this project has no baseline_arm_1 event so the pilot cadence is '
            . 'unreachable. Booster sessions use chatbot_system_context_booster.',
            implode(', ', $setCadence)
        )
    );
} elseif (!$pilotEvent) {
    note_('chatbot_system_context_session_*', 'unset, and unreachable on this project - correct');
}

// ------------------------------------------------------------------ the model

echo "\n3. The models\n";

try {
    $registry = $module->getSecureChatInstance()->getAvailableModels();
    $registry = is_array($registry) ? $registry : [];
} catch (\Throwable $e) {
    $registry = [];
}
note_('SecureChatAI registry', implode(', ', $registry) ?: '(empty - could not be read)');

foreach (['llm-model' => 'the counselor', 'safetyscan-model-alias' => 'the safety scanner'] as $key => $what) {
    $alias = $str($key);

    if ($alias === '') {
        bad_(
            $key,
            "unset - $what has no configured model",
            $key === 'safetyscan-model-alias'
                ? 'The scanner falls back to a hardcoded alias that is NOT checked against the '
                . 'registry, and an unregistered alias returns the provider\'s canned apology rather '
                . 'than an error - so sessions look screened and are not.'
                : 'assertModelIsRegistered() throws on an empty alias, so no turn can be taken.'
        );
        continue;
    }

    in_array($alias, $registry, true)
        ? ok_($key, "$alias (registered)")
        : bad_($key, "\"$alias\" is NOT in the SecureChatAI registry", 'Add it to SecureChatAI\'s api-settings, or point this at one the registry lists.');
}

/**
 * Model parameters. The check that matters is the empty-string one.
 *
 * `setModelParameters()` guards with `if ($value !== null)`, and a REDCap number field that has been
 * saved blank is `''`, not null - which is neither numeric nor null, so it is forwarded verbatim and
 * the provider receives `temperature: ""`.
 */
foreach (['gpt-temperature' => [0, 2], 'gpt-top-p' => [0, 1], 'gpt-frequency-penalty' => [-2, 2],
          'gpt-presence-penalty' => [-2, 2], 'gpt-max-tokens' => [1, 200000]] as $key => [$lo, $hi]) {
    $raw = $get($key);

    if ($raw === null) {
        continue;
    }

    if ($raw === '') {
        bad_(
            $key,
            'saved as an EMPTY STRING, which setModelParameters() forwards verbatim to the provider',
            'Clear the setting entirely rather than leaving it blank, or set a number.'
        );
        continue;
    }

    is_numeric($raw) && $raw >= $lo && $raw <= $hi
        ? ok_($key, (string) $raw)
        : bad_($key, sprintf('"%s" is outside the usable range %s..%s', (string) $raw, $lo, $hi));
}

$effort = $str('reasoning-effort');
$counselor = $str('llm-model');
if ($effort !== '') {
    in_array($counselor, ['o1', 'o3-mini', 'gpt-5'], true)
        ? ok_('reasoning-effort', "$effort (applies to $counselor)")
        : inert_('reasoning-effort', sprintf(
            '"%s" is set, but SecureChatAI unsets reasoning_effort for every model except '
            . 'o1/o3-mini/gpt-5, and this project uses "%s".',
            $effort,
            $counselor ?: '(none)'
        ));
}

// ------------------------------------------------------------------ the scan pipeline

echo "\n4. The post-session pipeline\n";

$get('enable-transcript-finalization')
    ? ok_('enable-transcript-finalization', 'on - sessions are snapshotted and queued for scanning')
    : bad_(
        'enable-transcript-finalization',
        'OFF - a finished session writes nothing and no scan is ever queued',
        'Everything downstream of this setting is inert while it is off, including every other '
        . 'setting in this section.'
    );

$map = $str('session-host-map');
try {
    $hostMap = \Stanford\MICA\SessionHostMap::fromSetting($map === '' ? null : $map);
    $resolved = [];
    foreach ($hosts as $host) {
        try {
            $r = $hostMap->resolve($host);
            $resolved[] = sprintf('%s -> %s/%s', $host, $r['session_type'], $r['setting']);
        } catch (\Throwable $e) {
            $resolved[] = "$host -> UNMAPPED";
        }
    }
    $unmapped = array_filter($resolved, static fn(string $r): bool => str_contains($r, 'UNMAPPED'));
    $unmapped === []
        ? ok_('session-host-map', implode('; ', $resolved) . ($map === '' ? '  (R01 defaults)' : ''))
        : bad_(
            'session-host-map',
            implode('; ', $resolved),
            'An unmapped chat host cannot be finalized: the session type and clinical setting are '
            . 'unknown, so nothing is written and no scan is queued.'
        );
} catch (\Throwable $e) {
    bad_('session-host-map', 'does not parse: ' . $e->getMessage());
}

$attempts = $get('safetyscan-max-attempts');
if ($attempts === null || $attempts === '') {
    note_('safetyscan-max-attempts', 'unset - default 3');
} elseif (!is_numeric($attempts) || (int) $attempts < 1 || (int) $attempts > 10) {
    bad_('safetyscan-max-attempts', sprintf('"%s" is not a usable attempt count', (string) $attempts));
} else {
    ok_('safetyscan-max-attempts', (string) (int) $attempts);
}

$get('scan-mock-mode')
    ? bad_(
        'scan-mock-mode',
        'ON - scans replay fixtures from disk and no model is called',
        'Fine for exercising the dashboard, useless for testing the scanner. The launch-readiness '
        . 'gate refuses production while it is on.'
    )
    : ok_('scan-mock-mode', 'off - scans call the configured model');

$store = new \Stanford\MICA\RedcapScanResultStore($module);
$store->findingInstrumentExists((string) $PID)
    ? ok_('mica_safety_finding', 'present (not a setting, but nothing above can release a finding without it)')
    : bad_('mica_safety_finding', 'MISSING - findings have nowhere to go', 'Run apply-safety-finding-instrument.php.');

// ------------------------------------------------------------------ thresholds

echo "\n5. The finding filter\n";

$thresholds = \Stanford\MICA\FindingThresholds::fromModule($module, $PID);
$rawExcluded = $get('finding-excluded-concern-types');
$rawExcluded = is_array($rawExcluded) ? array_filter($rawExcluded) : ($rawExcluded ? [$rawExcluded] : []);

if ($thresholds->passesEverything()) {
    ok_('finding thresholds', 'nothing filtered - every finding reaches the queue');
} else {
    ok_('finding-minimum-urgency', $thresholds->minimumUrgency() ?: '(no floor)');
    ok_('finding-excluded-concern-types', implode(', ', $thresholds->excludedConcernTypes()) ?: '(none)');

    // Selecting a life-safety type is accepted by the form and dropped by the code. Silent, and worth
    // saying out loud so nobody believes they excluded it.
    $ignored = array_intersect($rawExcluded, \Stanford\MICA\FindingThresholds::NEVER_EXCLUDABLE);
    if ($ignored !== []) {
        inert_('finding-excluded-concern-types', sprintf(
            '%s selected but IGNORED - life-safety concern types can never be excluded.',
            implode(', ', $ignored)
        ));
    }
}

// ------------------------------------------------------------------ review access

echo "\n6. Who can review\n";

$roles = \Stanford\MICA\RoleService::fromModule($module, $PID);
$liveRoles = [];
$result = $module->query('SELECT role_id, role_name FROM redcap_user_roles WHERE project_id = ?', [$PID]);
while ($row = $result->fetch_assoc()) {
    $liveRoles[(string) $row['role_id']] = $row['role_name'];
}

foreach ([\Stanford\MICA\RoleService::RA => 'role-ra-reviewer',
          \Stanford\MICA\RoleService::PI => 'role-pi-lead',
          \Stanford\MICA\RoleService::AUDITOR => 'role-auditor'] as $micaRole => $key) {
    $mapped = $roles->mappedRedcapRoles($micaRole);

    if ($mapped === []) {
        $micaRole === \Stanford\MICA\RoleService::AUDITOR
            ? note_($key, 'unmapped (optional)')
            : bad_(
                $key,
                'UNMAPPED - nobody can open the review dashboard',
                'MICA access follows REDCap roles, so with nothing mapped not even an admin gets in.'
            );
        continue;
    }

    $dead = array_values(array_diff($mapped, array_keys($liveRoles)));
    if ($dead !== []) {
        bad_(
            $key,
            'points at REDCap role id(s) that no longer exist: ' . implode(', ', $dead),
            'A mapping to a deleted role reads as "a role is mapped but nobody is in it", which '
            . 'sends somebody to User Rights looking for a role that is not there.'
        );
        continue;
    }

    $members = (int) $module->query(
        'SELECT COUNT(*) c FROM redcap_user_rights WHERE project_id = ? AND role_id IN ('
        . implode(',', array_fill(0, count($mapped), '?')) . ')',
        array_merge([$PID], array_map('intval', $mapped))
    )->fetch_assoc()['c'];

    $names = implode(', ', array_map(static fn(string $id): string => $liveRoles[$id] ?? $id, $mapped));
    $members > 0
        ? ok_($key, "$names ($members user(s))")
        : bad_($key, "$names is mapped but has no users in it", 'Assign at least one user under User Rights.');
}

// ------------------------------------------------------------------ notifications

echo "\n7. Notifications\n";

$artifacts = new \Stanford\MICA\ArtifactRegistry();
$policy = \Stanford\MICA\NotificationPolicy::fromJson(
    $str('notification-policy-json') ?: null,
    $artifacts,
    new \Stanford\MICA\SchemaValidator($artifacts)
);

if ($str('notification-policy-json') === '') {
    ok_('notification-policy-json', 'unset - the vendored default is in force (the conservative one)');
} elseif ($policy->usedFallback()) {
    bad_(
        'notification-policy-json',
        'REJECTED and the default is in force: ' . implode('; ', array_slice($policy->errors(), 0, 2)),
        'A policy that fails its pinned schema is discarded whole, never partly applied. Fix the '
        . 'JSON or clear the setting.'
    );
} else {
    ok_('notification-policy-json', 'valid against the pinned schema');
}

$directory = new \Stanford\MICA\RedcapRecipientDirectory($module, $roles, $PID);
$recipientProblems = $directory->configurationProblems();
$recipientProblems === []
    ? ok_('notify-*-emails', 'every configured address parses')
    : bad_('notify-*-emails', implode(' ', $recipientProblems), 'Malformed addresses are skipped silently at send time.');

foreach (['care_team' => 'notify-care-team-emails', 'protocol_lead' => 'notify-protocol-lead-emails',
          'on_call_research_staff' => 'notify-on-call-emails', 'data_safety_reviewer' => 'notify-data-safety-emails'] as $role => $key) {
    $addrs = $directory->addressesForRole($role);
    $addrs === [] ? note_($key, 'unset - this recipient role resolves to nobody') : ok_($key, implode(', ', $addrs));
}

$from = $str('notification-from-email');
if ($from === '') {
    note_('notification-from-email', "unset - REDCap's own configured sender is used, which is SPF-aligned");
} elseif (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
    bad_('notification-from-email', "not an address: \"$from\"");
} else {
    ok_('notification-from-email', $from);
}

$str('technical-fallback-text') === ''
    ? note_('technical-fallback-text', 'unset - a built-in sentence is shown when a session is refused')
    : ok_('technical-fallback-text', substr($str('technical-fallback-text'), 0, 48));

// ------------------------------------------------------------------ randomisation + logging

echo "\n8. Arm materialization and logging\n";

if (empty($get('materialize-assigned-arm'))) {
    note_('materialize-assigned-arm', 'off - records are not moved into their randomized arm');
} else {
    $groupField = $str('study-group-field') ?: 'study_group';
    in_array($groupField, $fields, true)
        ? ok_('materialize-assigned-arm', "on, allocation field \"$groupField\" exists")
        : bad_(
            'study-group-field',
            "allocation field \"$groupField\" does not exist on this project",
            'ensureRecordInAssignedArm() logs and returns "no-field", so materialization silently '
            . 'never happens.'
        );
}

$get('enable-project-debug-logging')
    ? ok_('enable-project-debug-logging', 'on')
    : note_(
        'enable-project-debug-logging',
        'off - emDebug is silent. Note emError routes through emLogger too, so module errors go '
        . 'nowhere unless the emLogger module is installed AND enabled.'
    );

// ------------------------------------------------------------------ system settings

echo "\n9. System settings\n";

/**
 * The `twilio-*` settings were REMOVED from config.json along with the commented-out `sendSMS()`.
 *
 * This check outlived them on purpose. Undeclaring a setting does not delete its stored row - the
 * value stays in `redcap_external_module_settings`, now invisible in the module's own UI, which is
 * strictly worse than leaving it declared. So the check inverts: it used to report a value that
 * had no code, and now it reports a value that has no *setting*. Either way the finding is the
 * same live auth token in the database.
 *
 * Purged locally on 2026-08-21. It has to be purged wherever else this module was ever enabled,
 * and the credential itself rotated in Twilio - neither of which a config.json change can do.
 */
$twilioOrphans = [];
foreach (['twilio-sid', 'twilio-auth-token', 'twilio-from-number'] as $key) {
    $v = trim((string) $module->getSystemSetting($key));
    if ($v !== '') {
        $twilioOrphans[] = $key;
    }
}

if ($twilioOrphans !== []) {
    bad_(
        'twilio-* (orphaned)',
        sprintf(
            '%s still hold values, but the settings no longer exist in config.json and sendSMS() '
            . 'is deleted',
            implode(', ', $twilioOrphans)
        ),
        'An orphaned credential: unreachable by code, unreadable in the UI, still in the database. '
        . 'Rotate the token in the Twilio console, then purge the rows: DELETE FROM '
        . "redcap_external_module_settings WHERE `key` IN ('twilio-sid','twilio-auth-token',"
        . "'twilio-from-number') AND external_module_id = (SELECT external_module_id FROM "
        . "redcap_external_modules WHERE directory_prefix='proj_mica');"
    );
} else {
    ok_('twilio-*', 'no orphaned rows - the settings and sendSMS() are both gone');
}

// ------------------------------------------------------------------ config.json self-consistency

echo "\n10. config.json self-consistency\n";

/**
 * The framework's own parsed config, not a path.
 *
 * This read was `file_get_contents(dirname(__DIR__, 3) . '/config.json')`, which resolves correctly
 * from the repo and to `/var/www/config.json` when the script is run from a copy under temp/ - where
 * it returns false, `json_decode(false)` gives null, and `$cfg[$section] ?? []` turns that into "no
 * settings are required". A check that silently reports the all-clear when it cannot find its own
 * input is worse than no check. getConfig() cannot be wrong about where the module is.
 */
$cfg = $module->getConfig();
$contradictions = [];
foreach (['project-settings', 'system-settings'] as $section) {
    foreach ($cfg[$section] ?? [] as $s) {
        $label = strip_tags((string) ($s['name'] ?? ''));
        // A label that says "optional" while the form demands a value is a form nobody can save.
        if (($s['required'] ?? false) === true && preg_match('/\boptional\b/i', $label)) {
            $contradictions[] = $s['key'];
        }
    }
}

$contradictions === []
    ? ok_('required flags', 'no setting is marked required while its label calls it optional')
    : bad_(
        'required flags',
        'marked required but labelled optional: ' . implode(', ', $contradictions),
        'REDCap enforces `required` on save, so an administrator cannot save the configuration '
        . 'without filling in a field the label told them to skip.'
    );

$requiredKeys = [];
foreach ($cfg['project-settings'] ?? [] as $s) {
    if (($s['required'] ?? false) === true) {
        $requiredKeys[] = $s['key'];
    }
}
note_('required project settings', implode(', ', $requiredKeys) ?: '(none)');

// ------------------------------------------------------------------ verdict

echo "\n";
if ($problems === []) {
    echo "PASS - every setting on this project is read, valid, and effective.\n";
    exit(0);
}

printf("%d setting(s) need attention: %s\n", count($problems), implode(', ', array_unique($problems)));
echo "\nLines marked INERT are configured and valid but do nothing on this project. They are not\n";
echo "counted as failures - a pilot cadence context on an R01 project is clutter, not a defect.\n";
exit(1);
