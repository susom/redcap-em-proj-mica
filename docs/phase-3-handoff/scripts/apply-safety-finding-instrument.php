<?php

/**
 * Build the repeating `mica_safety_finding` instrument on a project (audit G5).
 *
 *   docker exec <web> php .../scripts/apply-safety-finding-instrument.php [pid] [--dry-run]
 *
 * Idempotent: safe to re-run. Reports what it would do with `--dry-run`.
 *
 * Three steps, because REDCap treats them as three separate things:
 *
 *   1. the fields          — via MetaData::save_metadata($dd, appendFields: true), REDCap's own
 *                            import path, so form creation, the `_complete` field, field ordering
 *                            and user-rights rows are handled by REDCap rather than by us
 *   2. event designation   — `redcap_events_forms`. save_metadata() does this automatically only
 *                            for NON-longitudinal projects (`if (!$longitudinal)` guard in its
 *                            source); PID 257 is longitudinal, so it has to be done here
 *   3. repeating           — `redcap_events_repeat`, one row per (event, form)
 *
 * The dictionary is read from `docs/phase-3-handoff/dictionary/mica_safety_finding.csv`, which is
 * the reviewable artifact: the study team can diff it and import it through the normal Data
 * Dictionary UI for production sign-off (open question #8). This script exists so a dev copy can be
 * rebuilt without a manual step, not to replace that review.
 *
 * **Refuses to run on a project in Production status.** There, metadata changes must go through
 * REDCap's draft-and-approve flow, and bypassing it would apply an unreviewed dictionary change to
 * live participant data.
 */

$PID = (int) ($argv[1] ?? 257);
$DRY = in_array('--dry-run', $argv, true);

$_GET['pid'] = $PID;
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

const FORM = 'mica_safety_finding';

/** The events MICA is designated to: Day 1 (ED) and Month 3, arms 2 and 3 only. */
const HOST_FORMS = ['mica_ed_session', 'mica_booster_session'];

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica.\n");
    exit(1);
}

$fails = 0;
function say(string $tag, string $message): void
{
    printf("  [%s] %s\n", $tag, $message);
}
function fail(string $message): void
{
    global $fails;
    $fails++;
    say('FAIL', $message);
}

printf("mica_safety_finding on pid %d%s\n\n", $PID, $DRY ? '  (DRY RUN — nothing will be written)' : '');

// ---------------------------------------------------------------- 0. refuse production

$status = (int) ($module->query('SELECT status FROM redcap_projects WHERE project_id = ?', [$PID])
    ->fetch_assoc()['status'] ?? -1);

if ($status !== 0) {
    fwrite(STDERR, sprintf(
        "Project %d is not in Development status (status=%d). Metadata changes on a project past\n"
        . "Development must go through REDCap's draft-and-approve flow; applying an unreviewed\n"
        . "dictionary change to live participant data is not something a script should do.\n"
        . "Import docs/phase-3-handoff/dictionary/%s.csv through the Data Dictionary UI instead.\n",
        $PID,
        $status,
        FORM
    ));
    exit(1);
}
say('ok', 'project is in Development status');

// ---------------------------------------------------------------- 1. the fields

$csv = dirname(__DIR__) . '/dictionary/' . FORM . '.csv';
if (!is_file($csv)) {
    fwrite(STDERR, "Dictionary not found: $csv\n");
    exit(1);
}

$rows = array_map('str_getcsv', array_filter(explode("\n", str_replace("\r\n", "\n", trim(file_get_contents($csv))))));
$header = array_shift($rows);

// Re-read with a proper parser: quoted fields contain commas and newlines (the choice lists do).
$handle = fopen($csv, 'r');
$header = fgetcsv($handle);
$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    if ($row === [null] || implode('', $row) === '') {
        continue;
    }
    $rows[] = $row;
}
fclose($handle);

$expected = array_values(\MetaData::getDataDictionaryHeaders());
if ($header !== $expected) {
    fail('CSV header does not match this REDCap version\'s data-dictionary columns.');
    printf("       got:  %s\n       want: %s\n", implode(',', $header), implode(',', $expected));
    exit(1);
}
say('ok', sprintf('dictionary parsed: %d field(s)', count($rows)));

$existing = [];
$q = $module->query('SELECT field_name FROM redcap_metadata WHERE project_id = ? AND form_name = ?', [$PID, FORM]);
while ($row = $q->fetch_assoc()) {
    $existing[] = $row['field_name'];
}

$declared = array_column($rows, 0);
$missing = array_values(array_diff($declared, $existing));

if ($missing === []) {
    say('ok', sprintf('all %d field(s) already present — nothing to import', count($declared)));
} else {
    say('--', sprintf('%d field(s) to import: %s', count($missing), implode(', ', $missing)));

    if (!$DRY) {
        // save_metadata() takes the dictionary as column-letter arrays (A..R), which is the shape
        // REDCap's own CSV upload builds. appendFields=true adds these fields; false would REPLACE
        // the project's entire dictionary, which is emphatically not wanted here.
        $dd = [];
        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $dd[chr(65 + $c)][$r] = $value;
            }
        }

        $errors = \MetaData::save_metadata($dd, true, false, $PID);

        if (!empty($errors)) {
            fail('save_metadata reported errors: ' . (is_array($errors) ? implode('; ', $errors) : $errors));
        } else {
            say('ok', 'fields imported');
        }
    }
}

// ---------------------------------------------------------------- 2. event designation

// Wherever the chat host forms live, the findings for those sessions belong too - which keeps the
// arm restriction (MICA is arms 2 and 3 only) enforced by REDCap's designation rather than by
// module logic, exactly as 09-pid-257-structure-audit.md §1 describes for the session forms.
$q = $module->query(
    'SELECT DISTINCT ef.event_id, e.descrip, a.arm_num FROM redcap_events_forms ef '
    . 'JOIN redcap_events_metadata e ON e.event_id = ef.event_id '
    . 'JOIN redcap_events_arms a ON a.arm_id = e.arm_id '
    . 'WHERE a.project_id = ? AND ef.form_name IN (?, ?) ORDER BY a.arm_num, e.day_offset',
    array_merge([$PID], HOST_FORMS)
);
$targetEvents = [];
while ($row = $q->fetch_assoc()) {
    $targetEvents[(int) $row['event_id']] = sprintf('arm %s / %s', $row['arm_num'], $row['descrip']);
}

if ($targetEvents === []) {
    fail('no events host ' . implode(' or ', HOST_FORMS) . ', so there is nowhere to designate findings.');
} else {
    say('--', sprintf('host events: %s', implode(', ', array_map(
        static fn($id, $label) => "$id ($label)",
        array_keys($targetEvents),
        $targetEvents
    ))));
}

foreach (array_keys($targetEvents) as $eventId) {
    $already = $module->query(
        'SELECT 1 FROM redcap_events_forms WHERE event_id = ? AND form_name = ?',
        [$eventId, FORM]
    )->fetch_assoc();

    if ($already) {
        say('ok', "event $eventId already designated");
        continue;
    }

    if ($DRY) {
        say('--', "would designate event $eventId");
        continue;
    }

    $module->query('INSERT INTO redcap_events_forms (event_id, form_name) VALUES (?, ?)', [$eventId, FORM]);
    say('ok', "designated event $eventId");
}

// ---------------------------------------------------------------- 3. repeating

// One row per (event, form). Without this the instrument accepts a single instance per event and the
// second finding in a session silently overwrites the first.
foreach (array_keys($targetEvents) as $eventId) {
    $already = $module->query(
        'SELECT 1 FROM redcap_events_repeat WHERE event_id = ? AND form_name = ?',
        [$eventId, FORM]
    )->fetch_assoc();

    if ($already) {
        say('ok', "event $eventId already repeating");
        continue;
    }

    if ($DRY) {
        say('--', "would enable repeating on event $eventId");
        continue;
    }

    $module->query(
        'INSERT INTO redcap_events_repeat (event_id, form_name, custom_repeat_form_label) VALUES (?, ?, ?)',
        [$eventId, FORM, '[finding_urgency]: [finding_concern_type]']
    );
    say('ok', "enabled repeating on event $eventId");
}

// No cache to invalidate: `Project` is built per request in this REDCap version (there is no
// Project::resetCache(), and REDCap's own Data Dictionary upload simply redirects afterwards), so
// the next request reads the new dictionary. Worth stating, because "the dictionary changed but the
// cache is stale" is the first thing to suspect when a fresh repeating form rejects an instance.

// ---------------------------------------------------------------- verify

if ($DRY) {
    // Verifying a dry run would report every planned change as a failure, which reads as a broken
    // script rather than an un-applied one.
    echo "\n" . ($fails === 0
        ? "DRY RUN OK - re-run without --dry-run to apply\n"
        : "DRY RUN found $fails problem(s) before writing anything\n");
    exit($fails === 0 ? 0 : 1);
}

echo "\nverify\n";

$after = [];
$q = $module->query(
    'SELECT field_name, element_type, misc FROM redcap_metadata WHERE project_id = ? AND form_name = ? ORDER BY field_order',
    [$PID, FORM]
);
while ($row = $q->fetch_assoc()) {
    $after[$row['field_name']] = $row;
}

foreach ($declared as $field) {
    if (!isset($after[$field])) {
        fail("field $field is still missing");
    }
}
if (!isset($after[FORM . '_complete'])) {
    fail('the form has no _complete field, so REDCap does not consider it a real instrument');
}
say(
    isset($after[FORM . '_complete']) ? 'ok' : 'FAIL',
    sprintf('%d field(s) on the form, including _complete', count($after))
);

// The annotations are what keep model-populated fields from being hand-edited into drift.
$readonly = array_filter($after, static fn(array $f): bool => str_contains((string) $f['misc'], '@READONLY'));
say('ok', sprintf('%d field(s) carry @READONLY', count($readonly)));

$designated = (int) $module->query(
    'SELECT COUNT(*) n FROM redcap_events_forms WHERE form_name = ? AND event_id IN ('
    . implode(',', array_map('intval', array_keys($targetEvents))) . ')',
    [FORM]
)->fetch_assoc()['n'];
if ($designated !== count($targetEvents)) {
    fail("designated to $designated of " . count($targetEvents) . ' host event(s)');
} else {
    say('ok', "designated to all $designated host event(s)");
}

$repeating = (int) $module->query(
    'SELECT COUNT(*) n FROM redcap_events_repeat WHERE form_name = ? AND event_id IN ('
    . implode(',', array_map('intval', array_keys($targetEvents))) . ')',
    [FORM]
)->fetch_assoc()['n'];
if ($repeating !== count($targetEvents)) {
    fail("repeating on $repeating of " . count($targetEvents) . ' host event(s)');
} else {
    say('ok', "repeating on all $repeating host event(s)");
}

// What the module itself asks before it will write findings.
require_once dirname(__DIR__, 3) . '/classes/RedcapScanResultStore.php';
$store = new \Stanford\MICA\RedcapScanResultStore($module);
say(
    $store->findingInstrumentExists((string) $PID) ? 'ok' : 'FAIL',
    'RedcapScanResultStore::findingInstrumentExists() agrees'
);
if (!$store->findingInstrumentExists((string) $PID)) {
    $fails++;
}

echo "\n" . ($fails === 0
    ? "PASS - the instrument is built and reviewable\n"
    : "FAIL - $fails problem(s)\n");

if ($fails === 0) {
    echo "\nRollback, if this needs undoing on a dev copy:\n"
        . "  DELETE FROM redcap_metadata WHERE project_id = $PID AND form_name = '" . FORM . "';\n"
        . "  DELETE FROM redcap_events_forms WHERE form_name = '" . FORM . "';\n"
        . "  DELETE FROM redcap_events_repeat WHERE form_name = '" . FORM . "';\n"
        . "  DELETE FROM redcap_user_rights_forms WHERE form_name = '" . FORM . "' AND project_id = $PID;\n"
        . "  -- plus any data: DELETE FROM " . \Records::getDataTable($PID)
        . " WHERE project_id = $PID AND field_name IN (SELECT ...) -- only if instances were created\n";
}

exit($fails === 0 ? 0 : 1);
