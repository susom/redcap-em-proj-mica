<?php
/**
 * Gate the randomization trigger on `tsr` being Complete, not merely saved.
 *
 *   php apply-rand-trigger-tsr-complete.php [pid]            # dry run - prints before/after, writes nothing
 *   php apply-rand-trigger-tsr-complete.php [pid] --apply    # commits, after writing a rollback file
 *
 * Idempotent. A second --apply run reports "already ok".
 *
 * WHAT CHANGES. Only `trigger_logic`:
 *
 *   before  [calc_screen_result]=1
 *   after   [calc_screen_result]=1 AND [tsr_complete]='2'
 *
 * `trigger_option` stays 2 ("any user or survey participant"). That option already allows BOTH
 * paths the study wants:
 *   - automatic: the participant's own `tsr` submit randomizes them (realtimeRandomization());
 *   - manual:    the Randomize button on `admin` @ Day 1 (ED), shown to any user with Randomize
 *                rights (DataEntry.php:4282-4296 renders it whatever the trigger option is).
 * Option 1 would break the automatic path (skipped on survey pages, Randomization.php:3112) and
 * option 0 would switch it off.
 *
 * WHY THE CLAUSE. `tsr` is a single-page survey with save-and-return off, so a participant submit
 * is already a Complete save and the clause changes nothing for them. It matters for staff: a CRC
 * saving `tsr` as Incomplete on the data-entry form would otherwise randomize the record.
 *
 * Goes through Randomization::saveRealtimeOption() - the function the setup page calls - so the
 * change is logged ("Save randomization execute option") rather than being a silent UPDATE.
 */

$pid   = (int) ($argv[1] ?? 271);
$apply = in_array('--apply', array_slice($argv, 1), true);

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

const TARGET_LOGIC = "[calc_screen_result]=1 AND [tsr_complete]='2'";

global $Proj, $project_id;
$project_id = $pid;
$Proj = new Project($pid, true);

$q = db_query("select rid, trigger_option, trigger_instrument, trigger_event_id, trigger_logic
               from redcap_randomization where project_id = ?", [$pid]);
$rows = [];
while ($r = db_fetch_assoc($q)) $rows[] = $r;
if (count($rows) !== 1) {
    fwrite(STDERR, "expected exactly one randomization on pid $pid, found " . count($rows) . "\n");
    exit(1);
}
$r = $rows[0];

echo "pid $pid rid {$r['rid']}\n";
echo "  trigger_option     {$r['trigger_option']}\n";
echo "  trigger_instrument {$r['trigger_instrument']} @ event {$r['trigger_event_id']}\n";
echo "  trigger_logic      {$r['trigger_logic']}\n";

if ((int) $r['trigger_option'] !== 2 || $r['trigger_instrument'] !== 'tsr') {
    fwrite(STDERR, "refusing: expected trigger_option 2 on `tsr`; this script assumes the 271 setup\n");
    exit(1);
}
if (trim($r['trigger_logic']) === TARGET_LOGIC) {
    echo "already ok\n";
    exit(0);
}
echo "  ->                 " . TARGET_LOGIC . "\n";

if (!$apply) {
    echo "dry run - nothing written. Re-run with --apply.\n";
    exit(0);
}

$rollback = __DIR__ . "/rollback-rand-trigger-$pid-" . date('Ymd-His') . ".sql";
file_put_contents($rollback, sprintf(
    "-- restore the randomization trigger logic on pid %d as it was before apply-rand-trigger-tsr-complete.php\n"
    . "UPDATE redcap_randomization SET trigger_logic = '%s' WHERE project_id = %d AND rid = %d LIMIT 1;\n",
    $pid, db_escape($r['trigger_logic']), $pid, (int) $r['rid']
));
echo "rollback written: $rollback\n";

$ok = Randomization::saveRealtimeOption([
    'rid'   => (int) $r['rid'],
    'opt'   => (int) $r['trigger_option'],
    'form'  => $r['trigger_instrument'],
    'event' => (int) $r['trigger_event_id'],
    'logic' => TARGET_LOGIC,
]);
if (!$ok) {
    fwrite(STDERR, "saveRealtimeOption failed: " . db_error() . "\n");
    exit(1);
}

$now = db_result(db_query("select trigger_logic from redcap_randomization where rid = ?", [(int) $r['rid']]), 0);
echo "applied: trigger_logic = $now\n";
