<?php
/**
 * Load a development allocation table into a project's randomization setup.
 *
 *   php load-dev-allocation-table.php <pid> <csv> [--apply]
 *
 * Example:
 *   php load-dev-allocation-table.php 271 dev-allocation-table-MICA-ONLY.csv --apply
 *
 * WHY THIS EXISTS. A project copy carries the randomization *setup* row
 * (`redcap_randomization`: target field, target event, trigger) but NOT the allocation list
 * (`redcap_randomization_allocation`). PID 271 arrived from prod with `rid=8` fully configured and
 * **zero** allocations. The visible symptom is not an error: a participant finishes the whole
 * battery, the `tsr` trigger fires, REDCap finds no allocation, writes no `study_group`, and
 * `EdSessionLink::resolve()` returns NOT_RANDOMIZED - so `MICA.php:908` points `ed_session_url` at
 * the handoff page and the participant reads "Someone from the study team will be with you shortly
 * to continue." Nothing anywhere says "there are no allocations left".
 *
 * REFUSES TO RUN ON A PRODUCTION-STATUS PROJECT. These CSVs are test sequences - the files say so
 * in their own comment rows. A real schedule comes from the study statistician, and REDCap keeps
 * development and production allocation sets separate (`project_status`), so loading test data
 * into a live project would corrupt the trial's allocation sequence.
 *
 * Idempotent in the only sense that matters: it refuses to add a second table if unused
 * allocations already exist, because doing so would silently change the allocation ratio.
 */

$pid   = (int)    ($argv[1] ?? 0);
$csv   = (string) ($argv[2] ?? '');
$apply = in_array('--apply', array_slice($argv, 1), true);

if (!$pid || $csv === '') {
    fwrite(STDERR, "usage: php load-dev-allocation-table.php <pid> <csv> [--apply]\n");
    exit(1);
}
if (!is_file($csv)) { $csv = __DIR__ . '/' . $csv; }
if (!is_file($csv)) { fwrite(STDERR, "cannot read CSV\n"); exit(1); }

$_GET['pid'] = $pid;
define('NOAUTH', true);
define('CRON', true);
require_once mica_find_redcap_connect();

function mica_find_redcap_connect(): string {
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) return "$env/redcap_connect.php";
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $dir = dirname($dir);
        if (is_file("$dir/redcap_connect.php")) return "$dir/redcap_connect.php";
    }
    fwrite(STDERR, "cannot locate redcap_connect.php; set REDCAP_ROOT\n");
    exit(1);
}

// ---- guard: development projects only ----------------------------------------------------------
$q = db_query("SELECT status, app_title FROM redcap_projects WHERE project_id = ?", [$pid]);
$proj = db_fetch_assoc($q);
if (!$proj) { fwrite(STDERR, "no such project\n"); exit(1); }
if ((int) $proj['status'] !== 0) {
    fwrite(STDERR, "REFUSING: pid $pid has status {$proj['status']} (0 = Development).\n"
                 . "  These CSVs are test sequences. A production allocation table must come from\n"
                 . "  the study statistician and be uploaded through REDCap's own interface.\n");
    exit(1);
}

$q = db_query("SELECT rid, target_field, target_event, trigger_instrument FROM redcap_randomization WHERE project_id = ?", [$pid]);
$rand = db_fetch_assoc($q);
if (!$rand) { fwrite(STDERR, "pid $pid has no randomization setup - configure it in the UI first\n"); exit(1); }
$rid = (int) $rand['rid'];

$q = db_query("SELECT COUNT(*) c, SUM(is_used_by IS NULL) unused FROM redcap_randomization_allocation WHERE rid = ? AND project_status = 0", [$rid]);
$have = db_fetch_assoc($q);
printf("pid %d  %s  (Development)\n", $pid, $proj['app_title']);
printf("  rid %d -> %s @ event %s, auto-triggered on '%s'\n",
       $rid, $rand['target_field'], $rand['target_event'], $rand['trigger_instrument']);
printf("  existing dev allocations: %d (%d unused)\n\n", (int) $have['c'], (int) $have['unused']);

if ((int) $have['unused'] > 0) {
    fwrite(STDERR, "REFUSING: {$have['unused']} unused allocation(s) already present.\n"
                 . "  Appending a second table would change the allocation ratio silently.\n");
    exit(1);
}

// ---- parse: column 1 is the group value; later columns are the file's own comments -------------
$groups = [];
$fh = fopen($csv, 'r');
$header = fgetcsv($fh);
while (($row = fgetcsv($fh)) !== false) {
    $v = trim((string) ($row[0] ?? ''));
    if ($v === '' || !ctype_digit($v)) continue;     // skip the annotation rows
    $groups[] = (int) $v;
}
fclose($fh);

if (!$groups) { fwrite(STDERR, "no allocation values found in the CSV\n"); exit(1); }

$dist = array_count_values($groups);
ksort($dist);
printf("%s\n  %d allocations: %s\n\n", basename($csv), count($groups),
       implode(', ', array_map(fn($g, $n) => "group $g x$n", array_keys($dist), $dist)));

// Sanity: every group value must be a real choice on the target field.
$q = db_query("SELECT element_enum FROM redcap_metadata WHERE project_id = ? AND field_name = ?", [$pid, $rand['target_field']]);
$enum = (string) (db_fetch_assoc($q)['element_enum'] ?? '');
$valid = [];
foreach (explode('\\n', $enum) as $line) {
    if (preg_match('/^\s*(\d+)\s*,/', $line, $m)) $valid[] = (int) $m[1];
}
$unknown = array_values(array_unique(array_diff(array_keys($dist), $valid)));
if ($valid && $unknown) {
    fwrite(STDERR, "REFUSING: group value(s) " . implode(',', $unknown)
                 . " are not choices on {$rand['target_field']} (valid: " . implode(',', $valid) . ")\n");
    exit(1);
}
printf("  target field choices: %s -> all allocation values valid\n\n", implode(', ', $valid) ?: '(could not parse)');

if (!$apply) { echo "nothing written. re-run with --apply to load.\n"; exit(0); }

// ---- insert, mirroring exactly what REDCap's own uploader produces -----------------------------
db_query("START TRANSACTION");
$n = 0;
foreach ($groups as $g) {
    if (!db_query("INSERT INTO redcap_randomization_allocation (rid, project_status, target_field) VALUES (?, 0, ?)", [$rid, $g])) {
        db_query("ROLLBACK");
        fwrite(STDERR, "INSERT failed: " . db_error() . "\n");
        exit(1);
    }
    $n++;
}
db_query("COMMIT");
printf("inserted %d allocation(s) for rid %d\n", $n, $rid);

$q = db_query("SELECT target_field g, COUNT(*) c FROM redcap_randomization_allocation WHERE rid = ? AND project_status = 0 GROUP BY target_field ORDER BY target_field", [$rid]);
echo "verify:\n";
while ($r = db_fetch_assoc($q)) printf("  group %s  x%s\n", $r['g'], $r['c']);
echo "\nrollback:  DELETE FROM redcap_randomization_allocation WHERE rid = $rid AND is_used_by IS NULL;\n";
