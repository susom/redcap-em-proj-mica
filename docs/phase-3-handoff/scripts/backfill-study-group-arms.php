<?php
/**
 * Put every already-randomized record into its assigned arm.
 *
 *   php backfill-study-group-arms.php [pid] [--dry-run]
 *
 * Defaults: pid=257.
 *
 * Why this exists: `redcap_save_record` is the hook that normally does this, but REDCap fires it
 * from exactly one place - `Classes/DataEntry.php:6735` - i.e. only when someone saves a data entry
 * form or survey page through the UI. Data imports, the REST API, and other modules'
 * `REDCap::saveData()` calls do NOT fire it. So this script covers:
 *
 *   - records that already existed before the hook was switched on (backfill), and
 *   - records created or randomized by import/API rather than by a CRC in the UI.
 *
 * Idempotent: records already present in their arm are reported as `already-present` and untouched.
 * Safe to run repeatedly, and safe to schedule if you want import coverage to be automatic.
 */

$pid    = (int) ($argv[1] ?? 257);
$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

$_GET['pid'] = $pid;
define('NOAUTH', true);
require_once mica_find_redcap_connect();

function mica_find_redcap_connect(): string {
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) return "$env/redcap_connect.php";
    foreach (['/var/www/html'] as $guess) if (is_file("$guess/redcap_connect.php")) return "$guess/redcap_connect.php";
    for ($d = __DIR__, $i = 0; $i < 8; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) return "$d/redcap_connect.php";
        if ($d === '/') break;
    }
    fwrite(STDERR, "Could not locate redcap_connect.php. Set REDCAP_ROOT.\n"); exit(1);
}

$mica = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
$Proj = new Project($pid, true);

echo "\n=== arm backfill for project $pid" . ($dryRun ? ' (DRY RUN)' : '') . " ===\n";

if (empty($mica->getProjectSetting('materialize-assigned-arm', $pid))) {
    echo "  'Automatically add the record to its randomized arm' is OFF for this project.\n";
    echo "  Nothing done. Enable it in the module's project settings first.\n";
    exit(1);
}

$groupField = trim((string) ($mica->getProjectSetting('study-group-field', $pid) ?: 'study_group'));
if (!isset($Proj->metadata[$groupField])) {
    fwrite(STDERR, "  allocation field '$groupField' does not exist in project $pid\n");
    exit(1);
}
echo "  allocation field: $groupField\n\n";

$data    = \REDCap::getData(['project_id' => $pid, 'fields' => [$groupField], 'return_format' => 'array']);
$tallies = [];

foreach ($data as $record => $events) {
    $group = null;
    foreach ($events as $values) {
        if (isset($values[$groupField]) && $values[$groupField] !== '') { $group = $values[$groupField]; break; }
    }
    if ($group === null) { $tallies['not-randomized'][] = $record; continue; }

    if ($dryRun) {
        // Report what would happen without writing anything.
        $arm = (int) $group;
        $sql = "select 1 from " . $mica->getDataTable($pid) . " d
                  join redcap_events_metadata em on em.event_id = d.event_id
                  join redcap_events_arms ea on ea.arm_id = em.arm_id
                 where d.project_id = ? and d.record = ? and ea.project_id = ? and ea.arm_num = ? limit 1";
        $present = (bool) $mica->query($sql, [$pid, $record, $pid, $arm])->fetch_row();
        $status  = $present ? 'already-present' : 'would-materialize';
    } else {
        $status = $mica->ensureRecordInAssignedArm($pid, $record);
    }

    /*
     * The ED session link, for the same reason this script exists at all: the hook that writes it
     * fires only on UI saves and survey submits, so a record randomized by import sits in the right
     * arm with an empty `ed_session_url` - and a survey redirect piping that field would then send
     * the participant nowhere. Reported as a second column rather than folded into $status, because
     * "already in its arm" and "link already written" are different facts and a run that fixes only
     * one of them should say so.
     *
     * Runs even when the arm status is `already-present`: ensureEdSessionLink() is keyed on the
     * field being empty, not on materialization having just happened, so it self-heals records that
     * predate the feature.
     */
    $linkStatus = $dryRun ? '(dry-run)' : $mica->ensureEdSessionLink($pid, $record);
    if (!$dryRun) {
        $tallies['link:' . $linkStatus][] = $record;
    }

    $tallies[$status][] = $record;
    printf("  %-18s %-14s %s (study_group=%s)\n", $status, $linkStatus, $record, $group);
}

echo "\n--- summary ---\n";
foreach ($tallies as $status => $records) {
    printf("  %-18s %d\n", $status, count($records));
}
if (empty($tallies)) echo "  no records in this project\n";

$bad = array_merge(
    $tallies['bad-arm'] ?? [], $tallies['save-failed'] ?? [], $tallies['no-field'] ?? [],
    // A randomized record whose link could not be minted is a stuck handoff, so it counts.
    // `link:not-randomized` and `link:no-session-in-arm` deliberately do not - the first cannot
    // happen here (this loop skips unrandomized records) and the second is Standard Care, which is
    // correct and permanent. `link:no-field` means the project never opted in.
    $tallies['link:mint-failed'] ?? [], $tallies['link:save-failed'] ?? [],
    $tallies['link:bad-group'] ?? [], $tallies['link:no-host'] ?? []
);
if ($bad) {
    echo "\n  needs attention: " . implode(', ', $bad) . "\n";
    exit(1);
}
echo "\n*** DONE ***\n";
