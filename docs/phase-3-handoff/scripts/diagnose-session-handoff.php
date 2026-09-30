<?php
/**
 * Why is a participant seeing the session-handoff page instead of their MICA session?
 *
 *   php diagnose-session-handoff.php <pid> [record]
 *
 * READ-ONLY. Runs no writes of any kind - safe on production.
 *
 * WHAT THE MESSAGE MEANS. "Thank you. Someone from the study team will be with you shortly to
 * continue." is `pages/sessionHandoff.php` with `state=pending`, reached because the survey chain
 * redirects to `[ed_session_url]` and that field holds the handoff page rather than a session
 * link. `MICA.php:908` writes it whenever `EdSessionLink::resolve()` returns anything other than
 * RESOLVED - in practice, whenever the record has no usable `study_group`.
 *
 * It is not an error, and it is not new behaviour: before the handoff page existed, the same
 * condition produced `redirect('')` and a completely blank page. The message replaced the blank.
 *
 * THE CAUSE THIS IS MOST OFTEN. REDCap keeps development and production allocation tables
 * separate, and only ever reads the set matching the project's CURRENT status:
 *
 *     Randomization.php:2173   inner join redcap_projects p on ra.project_status = p.status
 *
 * So a project moved from Development to Production stops seeing every allocation uploaded while
 * it was in Development. If no production allocation table was uploaded, it randomizes nobody -
 * silently, and with no error anywhere.
 */

$pid    = (int)    ($argv[1] ?? 0);
$record = (string) ($argv[2] ?? '');
if (!$pid) { fwrite(STDERR, "usage: php diagnose-session-handoff.php <pid> [record]\n"); exit(1); }

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

$STATUS = [0 => 'Development', 1 => 'Production', 2 => 'Inactive', 3 => 'Completed'];
$problems = [];
$line = str_repeat('-', 78);

// ---- 1. project -------------------------------------------------------------------------------
$q = db_query("SELECT app_title, status, randomization FROM redcap_projects WHERE project_id = ?", [$pid]);
$p = db_fetch_assoc($q);
if (!$p) { fwrite(STDERR, "no such project\n"); exit(1); }
$status = (int) $p['status'];

printf("%s\nPID %d  %s\n%s\n", $line, $pid, $p['app_title'], $line);
printf("project status        : %d (%s)\n", $status, $STATUS[$status] ?? '?');
printf("randomization enabled : %s\n", $p['randomization'] ? 'yes' : 'NO');
if (!$p['randomization']) $problems[] = 'Randomization is not enabled on this project.';

// ---- 2. randomization setup ---------------------------------------------------------------------
$q = db_query("SELECT * FROM redcap_randomization WHERE project_id = ?", [$pid]);
$rand = db_fetch_assoc($q);
if (!$rand) {
    $problems[] = 'No randomization setup row exists.';
    printf("\n!! no redcap_randomization row for this project\n");
} else {
    $rid = (int) $rand['rid'];
    printf("\nrandomization setup   : rid %d\n", $rid);
    printf("  target              : %s @ event %s\n", $rand['target_field'], $rand['target_event']);
    printf("  trigger             : option %s, instrument '%s', event %s\n",
        $rand['trigger_option'], $rand['trigger_instrument'], $rand['trigger_event_id']);
    printf("  trigger logic       : %s\n", $rand['trigger_logic'] ?: '(none)');

    // stratification: if source fields are set, allocations are matched on them too
    $strata = [];
    for ($i = 1; $i <= 15; $i++) {
        if (!empty($rand["source_field$i"])) $strata[] = $rand["source_field$i"];
    }
    printf("  stratified by       : %s\n", $strata ? implode(', ', $strata) : '(not stratified)');
    printf("  grouped by DAG      : %s\n", $rand['group_by'] ?: 'no');

    // ---- 3. allocations, split by the status they belong to -------------------------------------
    $q = db_query("SELECT project_status, COUNT(*) n, SUM(is_used_by IS NULL) unused
                   FROM redcap_randomization_allocation WHERE rid = ? GROUP BY project_status", [$rid]);
    $byStatus = [];
    while ($r = db_fetch_assoc($q)) $byStatus[(int) $r['project_status']] = $r;

    printf("\n%s\nallocation table\n%s\n", $line, $line);
    if (!$byStatus) {
        printf("  NONE AT ALL for rid %d\n", $rid);
    } else {
        foreach ($byStatus as $st => $r) {
            printf("  %-12s set : %4d rows, %4d unused%s\n",
                $STATUS[$st] ?? $st, $r['n'], $r['unused'],
                $st === $status ? '   <-- the one REDCap reads for this project' : '');
        }
    }

    $liveUnused = (int) ($byStatus[$status]['unused'] ?? 0);
    $liveTotal  = (int) ($byStatus[$status]['n'] ?? 0);

    if ($liveTotal === 0) {
        $other = array_diff(array_keys($byStatus), [$status]);
        $problems[] = $other
            ? sprintf(
                'THE LIKELY CAUSE: this project is in %s, but its only allocations belong to the %s set. '
              . 'Randomization.php:2173 joins on `ra.project_status = p.status`, so REDCap cannot see them. '
              . 'Upload an allocation table while the project is in %s.',
                $STATUS[$status], implode('/', array_map(fn($s) => $STATUS[$s] ?? $s, $other)), $STATUS[$status])
            : sprintf('No allocation rows exist for the %s set. Upload one.', $STATUS[$status]);
    } elseif ($liveUnused === 0) {
        $problems[] = sprintf('The %s allocation table is EXHAUSTED - all %d rows are used. '
                            . 'Every new participant from now on fails to randomize. Upload more.',
                            $STATUS[$status], $liveTotal);
    } elseif ($liveUnused < 10) {
        $problems[] = sprintf('Only %d unused allocation(s) left in the %s set - running out.',
                              $liveUnused, $STATUS[$status]);
    }
}

// ---- 4. how many records are actually stuck -----------------------------------------------------
$dt = \Records::getDataTable($pid);
$targetField = $rand['target_field'] ?? 'study_group';
$q = db_query("SELECT COUNT(DISTINCT record) n FROM $dt WHERE project_id = ? AND field_name = 'ed_session_url'
               AND value LIKE '%sessionHandoff%'", [$pid]);
$stuck = (int) (db_fetch_assoc($q)['n'] ?? 0);
$q = db_query("SELECT COUNT(DISTINCT record) n FROM $dt WHERE project_id = ? AND field_name = ? AND value <> ''", [$pid, $targetField]);
$randomized = (int) (db_fetch_assoc($q)['n'] ?? 0);
$q = db_query("SELECT COUNT(DISTINCT record) n FROM $dt WHERE project_id = ?", [$pid]);
$total = (int) (db_fetch_assoc($q)['n'] ?? 0);

printf("\n%s\nrecords\n%s\n", $line, $line);
printf("  total                       : %d\n", $total);
printf("  with %-22s: %d\n", $targetField, $randomized);
printf("  pointing at the handoff page: %d\n", $stuck);
if ($stuck > 0) printf("     (these are the participants seeing the message)\n");

// ---- 5. one record in detail --------------------------------------------------------------------
if ($record !== '') {
    printf("\n%s\nrecord %s\n%s\n", $line, $record, $line);
    \ExternalModules\ExternalModules::getModuleInstance('proj_mica');   // load the module autoloader
    $proj = new Project($pid);

    $q = db_query("SELECT event_id, value FROM $dt WHERE project_id=? AND record=? AND field_name=? AND value<>''",
                  [$pid, $record, $targetField]);
    $groups = [];
    while ($r = db_fetch_assoc($q)) $groups[(int) $r['event_id']] = $r['value'];
    printf("  %-22s: %s\n", $targetField, $groups ? json_encode($groups) : '(EMPTY - not randomized)');

    $q = db_query("SELECT event_id, value FROM $dt WHERE project_id=? AND record=? AND field_name='ed_session_url'",
                  [$pid, $record]);
    while ($r = db_fetch_assoc($q)) printf("  ed_session_url @%s : %s\n", $r['event_id'], $r['value']);

    $q = db_query("SELECT GROUP_CONCAT(arm ORDER BY arm) a FROM redcap_record_list WHERE project_id=? AND record=?", [$pid, $record]);
    printf("  arms                  : %s\n", db_fetch_assoc($q)['a'] ?? '(none)');

    $gv = $groups ? reset($groups) : '';
    if (class_exists('\Stanford\MICA\EdSessionLink')) {
        $host = 'mica_ed_session';
        $res = \Stanford\MICA\EdSessionLink::resolve($gv, $host, $proj->eventsForms ?? [], $proj->eventInfo ?? []);
        printf("  EdSessionLink::resolve: %s\n", json_encode($res));
        if (($res['status'] ?? '') === 'not-randomized') {
            $problems[] = "Record $record has no $targetField value - it was never randomized. "
                        . 'That is what produces the "someone will be with you" message.';
        } elseif (($res['status'] ?? '') === 'no-session-in-arm') {
            printf("     -> Standard Care: correctly has no session. This record should see the\n"
                 . "        'you have finished this part of the study' wording, not 'pending'.\n");
        }
    }

    if (!empty($rand['trigger_logic'])) {
        $t = \REDCap::evaluateLogic($rand['trigger_logic'], $pid, $record);
        printf("  trigger logic '%s' -> %s\n", $rand['trigger_logic'], var_export($t, true));
        if ($t !== true) $problems[] = "Record $record does not satisfy the randomization trigger logic, so it is never randomized.";
    }
}

// ---- verdict --------------------------------------------------------------------------------------
printf("\n%s\nfindings\n%s\n", $line, $line);
if (!$problems) {
    echo "  Nothing obviously wrong with randomization on this project.\n"
       . "  Re-run with a specific record id to look at one participant.\n";
} else {
    foreach ($problems as $i => $m) printf("  %d. %s\n\n", $i + 1, wordwrap($m, 74, "\n     "));
}
