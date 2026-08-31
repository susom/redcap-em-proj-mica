<?php
/**
 * Is the ED Day-1 session handoff actually wired end to end?
 *
 *   docker exec <web> php verify-ed-session-link.php [pid]
 *
 * Read-only. Exit 0 = nothing broken; 1 = at least one link in the chain is missing or wrong.
 *
 * The handoff spans five things that are configured in five different places - a field, a module
 * setting, REDCap's randomization setup, a survey's termination option, and the module's own hook -
 * and a break in any one of them shows up to a participant as a blank page or a session they cannot
 * reach. This asserts each link separately so the report names the broken one instead of "it does
 * not work".
 *
 * The check most worth running is §3. `Randomization.php:3112` skips trigger option 1 on survey
 * pages, so a setup that randomizes perfectly for a CRC does nothing at all for a participant - and
 * fails silently, with no log line and no error.
 */

$PID = (int) ($argv[1] ?? getenv('MICA_PID') ?: 257);

$_GET['pid'] = (string) $PID;
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

$problems = [];
function ok_(string $k, string $d = ''): void { printf("  [ok]  %-30s %s\n", $k, $d); }
function bad_(string $k, string $d, string $fix = ''): void {
    global $problems; $problems[] = $k;
    printf("  [--]  %-30s %s\n", $k, $d);
    if ($fix !== '') foreach (explode("\n", wordwrap($fix, 84)) as $l) printf("        -> %s\n", $l);
}
function note_(string $k, string $d): void { printf("        %-30s %s\n", $k, $d); }

$module = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
$Proj   = new Project($PID, true);

echo "\n=== ED session handoff on project $PID ===\n";

// ------------------------------------------------------------------ 1. the field
echo "\n1. The field that receives the link\n";

$urlField = trim((string) ($module->getProjectSetting('ed-session-url-field', $PID) ?: 'ed_session_url'));

if (!isset($Proj->metadata[$urlField])) {
    bad_('field', "'$urlField' does not exist - the whole feature is off",
        'The field\'s existence is the switch. Run apply-ed-session-url-field.php, or name an '
        . 'existing field in the module\'s "Field that receives the ED Day-1 session link" setting.');
} else {
    $meta = $Proj->metadata[$urlField];
    ok_('field', "$urlField ({$meta['element_type']}) on form {$meta['form_name']}");
    $tags = (string) ($meta['misc'] ?? '');
    str_contains(strtoupper($tags), '@READONLY')
        ? ok_('@READONLY', 'set')
        : bad_('@READONLY', "$urlField is editable",
            'A survey redirect follows whatever is in this field, so a hand-edited value sends a '
            . 'participant somewhere nobody chose. Add @READONLY.');
    note_('action tags', $tags === '' ? '(none)' : $tags);

    // Which event the module writes to - derived, so worth printing rather than assuming.
    $writeEvent = null; $bestKey = null;
    foreach ($Proj->eventInfo as $eid => $info) {
        if (!in_array($meta['form_name'], $Proj->eventsForms[$eid] ?? [], true)) continue;
        $key = [(int) $info['arm_num'], (int) $info['day_offset']];
        if ($bestKey === null || $key < $bestKey) { $bestKey = $key; $writeEvent = (int) $eid; }
    }
    note_('written to event', $writeEvent
        ? "$writeEvent ({$Proj->eventInfo[$writeEvent]['name_ext']})"
        : '(none - the form is designated to no event)');
}

// ------------------------------------------------------------------ 2. the host map
echo "\n2. The session host\n";

// getModulePath(), not a dirname() walk: this file sits three levels below the module root and
// counting them wrong resolves to docs/ - which is exactly what the first version of this line did.
require_once $module->getModulePath() . 'classes/EdSessionLink.php';
$host = \Stanford\MICA\EdSessionLink::hostInstrument(
    \Stanford\MICA\SessionHostMap::fromSetting($module->getProjectSetting('session-host-map', $PID))
);
$host === null
    ? bad_('baseline host', 'the session host map names no baseline session',
        'Nothing can be minted. Fix the module\'s session-host-map setting.')
    : ok_('baseline host', $host);

if ($host !== null) {
    $armsWithHost = [];
    foreach ($Proj->eventInfo as $eid => $info) {
        if (in_array($host, $Proj->eventsForms[$eid] ?? [], true)) {
            $armsWithHost[(int) $info['arm_num']][] = (int) $eid;
        }
    }
    foreach ($armsWithHost as $arm => $events) {
        note_("arm $arm hosts it at", implode(', ', $events));
    }
    $noHost = array_diff(array_unique(array_column($Proj->eventInfo, 'arm_num')), array_keys($armsWithHost));
    if ($noHost) note_('arms with NO session', implode(', ', $noHost) . ' (correct for control arms)');
}

// ------------------------------------------------------------------ 3. randomization
echo "\n3. Randomization - the trap\n";

$randOn = (int) db_result(db_query("select randomization from redcap_projects where project_id = $PID"), 0);
$rand = db_fetch_assoc(db_query("select rid, target_field, target_event, trigger_option,
    trigger_instrument, trigger_event_id, trigger_logic from redcap_randomization where project_id = $PID"));

if (!$randOn || !$rand) {
    bad_('randomization', 'not enabled / not set up on this project',
        'The allocation has to come from somewhere. Either enable REDCap\'s Randomization module '
        . '(target field ' . ($module->getProjectSetting('study-group-field', $PID) ?: 'study_group')
        . ') or keep setting the allocation by hand - the module writes the link either way, but '
        . 'nothing is automatic until randomization is configured.');
} else {
    ok_('randomization', "enabled, rid {$rand['rid']}, target {$rand['target_field']}");
    $groupField = trim((string) ($module->getProjectSetting('study-group-field', $PID) ?: 'study_group'));
    $rand['target_field'] === $groupField
        ? ok_('target field', "matches the module's allocation field ($groupField)")
        : bad_('target field', "randomization writes {$rand['target_field']} but the module reads $groupField",
            'They must be the same field, or the module never sees the allocation.');

    $opt = (int) $rand['trigger_option'];
    $optName = [0 => 'manual only ("Randomize" button)',
                1 => 'trigger logic, users with Randomize permission ONLY',
                2 => 'trigger logic, all users including survey respondents'][$opt] ?? "unknown ($opt)";
    note_('trigger option', "$opt - $optName");
    note_('trigger instrument', ($rand['trigger_instrument'] ?: '(none)')
        . ($rand['trigger_event_id'] ? " @ event {$rand['trigger_event_id']}" : ''));
    note_('trigger logic', $rand['trigger_logic'] ?: '(none)');

    if ($opt === 2) {
        ok_('participant-triggered', 'option 2 - a survey respondent can trigger randomization');
    } elseif ($opt === 1) {
        bad_('participant-triggered', 'option 1 will NEVER fire on a survey',
            'Randomization.php:3112 skips option 1 when $isSurveyPage, and again unless the user has '
            . 'random_perform rights. A participant has neither, so randomizing on a participant '
            . 'survey silently does nothing. Change it to option 2 ("for all users, including '
            . 'survey respondents").');
    } else {
        note_('participant-triggered', 'no - option 0 means a CRC clicks Randomize by hand');
    }

    if ($rand['trigger_instrument'] !== '' && $rand['trigger_instrument'] !== null) {
        $isSurvey = isset($Proj->forms[$rand['trigger_instrument']]['survey_id']);
        note_('trigger instrument is a survey', $isSurvey ? 'yes' : 'no (data entry only)');
        if ($isSurvey && $opt !== 2) {
            bad_('trigger reachability', "{$rand['trigger_instrument']} is a survey but the trigger "
                . "option is $opt", 'See above - only option 2 fires for a respondent.');
        }
    }

    // Allocations. Without a table for the project's current status, the trigger does nothing.
    $status = (int) $Proj->project['status'];
    $srcId  = $status === 0 ? 1 : 0;   // 1 = development table, 0 = production
    $free = (int) db_result(db_query("select count(*) from redcap_randomization_allocation
        where rid = {$rand['rid']} and project_status = $srcId and is_used_by is null"), 0);
    $free > 0
        ? ok_('free allocations', "$free remaining in the " . ($srcId ? 'development' : 'production') . ' table')
        : bad_('free allocations', 'none left in the '
            . ($srcId ? 'development' : 'production') . ' table',
            'The trigger runs, finds no allocation, and logs "no available allocations". Upload or '
            . 'extend the allocation table. Note development and production have SEPARATE tables.');
}

// ------------------------------------------------------------------ 4. the redirect
echo "\n4. The survey redirect\n";

$redirects = db_query("select survey_id, form_name, end_survey_redirect_url,
    end_survey_redirect_next_survey, save_and_return from redcap_surveys
    where project_id = $PID and end_survey_redirect_url is not null and end_survey_redirect_url <> ''");

$pipingSurveys = [];
while ($r = db_fetch_assoc($redirects)) {
    $pipesField = str_contains((string) $r['end_survey_redirect_url'], "[$urlField]");
    if (!$pipesField) { note_($r['form_name'], 'redirects to ' . $r['end_survey_redirect_url']); continue; }
    $pipingSurveys[] = $r['form_name'];

    ok_('redirect', "{$r['form_name']} -> [$urlField]");
    // Both are hard requirements of Surveys/index.php:1822, not style.
    (int) $r['end_survey_redirect_next_survey'] === 0
        ? ok_('auto-continue off', "on {$r['form_name']} - required for a redirect to fire")
        : bad_('auto-continue', "{$r['form_name']} has auto-continue ON, so the redirect never fires",
            'Surveys/index.php:1822 requires !$end_survey_redirect_next_survey. Turn auto-continue '
            . 'off on this survey, or move the redirect to the survey that actually ends the chain.');
    (int) $r['save_and_return'] === 0
        ? ok_('save-and-return off', "on {$r['form_name']} - also required")
        : bad_('save-and-return', "{$r['form_name']} has save-and-return ON, so the redirect never fires",
            'Same guard. The redirect only runs when !$save_and_return.');
}

if ($pipingSurveys === []) {
    bad_('redirect', "no survey redirects to [$urlField]",
        "Set the handoff survey's Survey Termination Options -> Redirect to a URL to [$urlField]. "
        . 'Without it the field is written and never used, so the participant reaches the end of the '
        . 'chain and stops there.');
} elseif (count($pipingSurveys) > 1) {
    // Not fatal, but only one survey can be the end of the chain - the others redirect from the
    // middle of it, which would skip the surveys after them.
    bad_('redirect', 'more than one survey redirects to it: ' . implode(', ', $pipingSurveys),
        'Each of these sends the participant into their session at that point, skipping whatever '
        . 'follows. Keep the redirect on the survey that actually ends the chain.');
}

// ------------------------------------------------------------------ 5. per-record state
echo "\n5. What each record actually holds\n";

$data = \REDCap::getData(['project_id' => $PID,
    'fields' => [$Proj->table_pk, trim((string) ($module->getProjectSetting('study-group-field', $PID) ?: 'study_group')), $urlField],
    'return_format' => 'array']);
$groupField = trim((string) ($module->getProjectSetting('study-group-field', $PID) ?: 'study_group'));

$tally = ['session link' => 0, 'handoff fallback' => 0, 'EMPTY' => 0, 'other' => 0];
foreach ($data as $record => $events) {
    $group = ''; $url = '';
    foreach ($events as $v) {
        if (($v[$groupField] ?? '') !== '') $group = $v[$groupField];
        if (($v[$urlField] ?? '') !== '') $url = $v[$urlField];
    }
    if ($url === '')                                     $kind = 'EMPTY';
    elseif (str_contains($url, 'sessionHandoff'))         $kind = 'handoff fallback';
    elseif (str_contains($url, '/surveys/?s='))           $kind = 'session link';
    else                                                  $kind = 'other';
    $tally[$kind]++;
    printf("        %-12s group=%-3s %s\n", $record, $group === '' ? '-' : $group, $kind);
}
foreach ($tally as $k => $n) if ($n) note_($k, (string) $n);

$tally['EMPTY'] === 0
    ? ok_('no record is empty', 'every record has somewhere to go at the end of the chain')
    : bad_('empty fields', "{$tally['EMPTY']} record(s) hold nothing",
        'A redirect piping an empty field reaches redirect(\'\') - a 302 with an empty Location and '
        . 'a blank page. Run backfill-study-group-arms.php to fill them.');

// ------------------------------------------------------------------ 6. the getSurveyLink hazard
echo "\n6. No link may exist at an event that does not host the session\n";

if ($host !== null) {
    $rows = db_query("select p.event_id, count(*) n from redcap_surveys_participants p
        join redcap_surveys s on s.survey_id = p.survey_id
        where s.project_id = $PID and s.form_name = '" . db_escape($host) . "' group by p.event_id");
    $stray = [];
    while ($r = db_fetch_assoc($rows)) {
        $eid = (int) $r['event_id'];
        $designated = in_array($host, $Proj->eventsForms[$eid] ?? [], true);
        note_("event $eid", "{$r['n']} link(s)" . ($designated ? '' : '  <-- NOT designated here'));
        if (!$designated) $stray[] = $eid;
    }
    $stray === []
        ? ok_('no stray links', "every $host link sits at an event that hosts it")
        : bad_('stray links', 'links exist at event(s) ' . implode(', ', $stray) . " where $host is not designated",
            'REDCap::getSurveyLink() does NOT check designation (REDCap.php:1740-1745) - it checks '
            . 'only that the record exists in the given event\'s ARM. So a link minted at a control '
            . 'arm\'s event looks valid and would hand a control participant the intervention. This '
            . 'is what [survey-url:' . $host . '] does when piped from an arm-1 survey. Delete the '
            . 'rows and never resolve the event by anything but the record\'s allocation.');
}

// ------------------------------------------------------------------ verdict
echo "\n";
if ($problems === []) {
    echo "PASS - the handoff is wired end to end.\n";
    exit(0);
}
printf("%d problem(s): %s\n", count($problems), implode(', ', array_unique($problems)));
exit(1);
