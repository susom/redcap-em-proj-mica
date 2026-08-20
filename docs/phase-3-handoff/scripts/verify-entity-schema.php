<?php

/**
 * Build and verify MICA's REDCap Entity schema against a live REDCap.
 *
 * Runs the real code path (EntitySchemaManager over RedcapEntityPlatform) rather than
 * re-implementing it, then checks the database independently: every declared table, every declared
 * index, the recorded schema-version, and that a second run is a no-op. Counts are derived from
 * EntityTypes rather than written here, so adding a type or an index does not need this file edited -
 * a hardcoded count is a check that silently stops covering what it was written for.
 *
 *   docker exec <web> php /var/www/html/temp/mica/verify-entity-schema.php
 *
 * Exit 0 = the schema is exactly as declared; 1 = at least one check failed.
 * Step 2 always forces, because a verifier that might skip the work it is verifying is useless.
 *
 * Why a script and not a unit test: everything interesting here is the part the unit suite
 * deliberately cannot reach - whether redcap_entity is loadable in this request, whether
 * buildSchema() actually creates tables for a module that is enabled per-project but not
 * system-wide, and whether ALTER TABLE is permitted for the REDCap database user.
 */

define('NOAUTH', true);
require_once mica_find_redcap_connect();

/** Locate redcap_connect.php so the script runs from the repo path or anywhere it is copied. */
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

$fails = 0;
function check(string $label, $actual, $expected): void
{
    global $fails;
    $ok = (string) $actual === (string) $expected;
    if (!$ok) {
        $fails++;
    }
    printf(
        "  [%s] %-56s got: %-24s want: %s\n",
        $ok ? 'ok' : 'FAIL',
        $label,
        $actual === '' ? "''" : (string) $actual,
        $expected === '' ? "''" : (string) $expected
    );
}

echo "MICA entity schema\n";

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!$module) {
    fwrite(STDERR, "Could not instantiate proj_mica. Is a `version` row present for it?\n");
    exit(1);
}

echo "\n1. Dependency\n";
$entityVersion = \ExternalModules\ExternalModules::getEnabledVersion('redcap_entity');
check('redcap_entity has an enabled version', $entityVersion ? 'yes' : 'no', 'yes');

// The single most likely deployment mistake, and the reason ensureSchema() verifies tables rather
// than trusting buildSchema(): EntityDB::buildSchema() early-returns on a falsy getEnabledVersion,
// so a module enabled only for a project gets no tables and no error.
$micaVersion = \ExternalModules\ExternalModules::getEnabledVersion('proj_mica');
printf(
    "  [--] %-56s %s\n",
    'proj_mica system-level enabled version',
    $micaVersion ? $micaVersion : "(none - enabled per-project only)"
);

echo "\n2. Build\n";

// ensureSchema() ends in setSystemSetting(), and ExternalModules::setSystemSetting() routes through
// setProjectSetting(), whose user-based permission check is waived only under CRON or for a user
// with design rights. This script is neither: NOAUTH CLI has no user at all, so the write throws
// "You don't have permission to save project settings".
//
// The override belongs here rather than in the module. Production reaches recordSchemaVersion()
// from exactly two places - module enable (a super user is present) and cron (`defined('CRON')`
// waives the check) - so adding a permission override to shipped code would weaken it to suit a
// test harness. Participant requests never migrate at all; they call assertSchemaCurrent().
$module->disableUserBasedSettingPermissions();

try {
    $manager = $module->entitySchemaManager();
    $manager->ensureSchema(true);
    echo "  [ok] ensureSchema(force) completed\n";
} catch (\Throwable $e) {
    $fails++;
    echo "  [FAIL] ensureSchema threw: " . $e->getMessage() . "\n";
}

echo "\n3. Tables\n";
foreach (\Stanford\MICA\EntityTypes::tables() as $table) {
    $q = $module->query(
        'SELECT 1 FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    );
    check($table, $q->fetch_assoc() ? 'present' : 'MISSING', 'present');
}

echo "\n4. Columns (redcap_entity cannot ALTER, so a new property needs a migration)\n";

// The check this script was missing, and its absence let a real bug through: `event_id` was added
// to two entity types, the version was bumped, buildSchema() ran, and this reported PASS - because
// it only looked at tables and indexes. buildSchema() is CREATE TABLE IF NOT EXISTS; it never
// touches an existing table, so the columns simply were not there.
$columnFails = 0;
foreach (\Stanford\MICA\EntityTypes::all() as $type => $info) {
    $table = 'redcap_entity_' . $type;
    $q = $module->query(
        'SELECT column_name AS col, column_type AS ct FROM information_schema.COLUMNS '
        . 'WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    );
    $present = [];
    while ($row = $q->fetch_assoc()) {
        $present[strtolower((string) $row['col'])] = strtoupper((string) $row['ct']);
    }

    $missing = [];
    foreach ($info['properties'] as $property => $spec) {
        if (!isset($present[strtolower($property)])) {
            $missing[] = $property;
        }
    }

    if ($missing === []) {
        printf("  [ok] %-56s all %d declared column(s) present\n", $table, count($info['properties']));
    } else {
        $columnFails++;
        $fails++;
        printf("  [FAIL] %-54s MISSING: %s\n", $table, implode(', ', $missing));
    }
}

echo "\n5. Indexes (redcap_entity creates none of these)\n";
foreach (\Stanford\MICA\EntityTypes::indexes() as $name => $spec) {
    // Both columns are aliased deliberately: MySQL returns information_schema column names
    // UPPERCASED however the query spells them, so an unaliased $row['non_unique'] is undefined -
    // and (int) null === 0 reported every index as UNIQUE, which is how this verifier managed to
    // pass and fail the same index on two consecutive runs. Alias, always.
    $q = $module->query(
        'SELECT non_unique AS nonuniq, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols '
        . 'FROM information_schema.STATISTICS '
        . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? GROUP BY non_unique',
        [$spec['table'], $name]
    );
    $row = $q->fetch_assoc();

    check("{$spec['table']}.$name columns", $row['cols'] ?? 'MISSING', implode(',', $spec['columns']));
    check(
        "{$spec['table']}.$name unique",
        $row === null ? 'MISSING' : ((int) $row['nonuniq'] === 0 ? 'yes' : 'no'),
        $spec['unique'] ? 'yes' : 'no'
    );
}

echo "\n6. Recorded version\n";
check(
    'schema-version system setting',
    (string) $module->getSystemSetting('schema-version'),
    \Stanford\MICA\EntityTypes::SCHEMA_VERSION
);

echo "\n7. Second run is a no-op\n";
try {
    $before = microtime(true);
    $module->entitySchemaManager()->ensureSchema();
    printf("  [ok] ensureSchema() returned in %.1f ms with no error\n", (microtime(true) - $before) * 1000);
} catch (\Throwable $e) {
    $fails++;
    echo "  [FAIL] second ensureSchema threw: " . $e->getMessage() . "\n";
}

echo "\n8. The participant-path assertion passes\n";
try {
    $module->entitySchemaManager()->assertSchemaCurrent();
    echo "  [ok] assertSchemaCurrent() passed without writing anything\n";
} catch (\Throwable $e) {
    $fails++;
    echo "  [FAIL] assertSchemaCurrent threw: " . $e->getMessage() . "\n";
}

echo "\n" . ($fails === 0 ? "PASS - schema matches the declarations\n" : "FAIL - $fails check(s) failed\n");
exit($fails === 0 ? 0 : 1);
