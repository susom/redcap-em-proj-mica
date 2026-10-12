<?php
/**
 * Fixture for the AI token-policy E2E (e2e/token-policy.js, verify-token-policy.php).
 *
 *   docker exec <web> php e2e-token-policy-fixture.php <pid> setup|teardown
 *
 * setup:
 *   1. Refuses unless this is a development server AND SecureChatAI's own token-policy fixture is
 *      active (its high-threshold token is the fake `e2e-high-key`), so every model call this E2E
 *      makes lands on SecureChatAI's mock AI Hub (secure_chat_ai/tests/e2e/mock_aihub.php) and none
 *      reaches AI Hub with a transcript in it.
 *   2. Backs up this project's MICA model settings (the switch and all four alias settings) and the
 *      module's active version, to BACKUP (mode 0600). Refuses if a backup is already there.
 *   3. Makes THIS directory the active MICA version (the folder name carries it, e.g. v9.9.8), so a
 *      branch checked out in a worktree beside the main folder is what REDCap serves. Every project
 *      with MICA enabled runs this code until teardown.
 *   4. Points the standard aliases at `gpt-5-4` (registered in the mock's standard set) and clears
 *      the switch and the high-threshold aliases, so each test starts from "off".
 * teardown: restores the version and the settings exactly, and removes the backup.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pid  = (int) ($argv[1] ?? 0);
$mode = (string) ($argv[2] ?? '');
if ($pid <= 0 || !in_array($mode, ['setup', 'teardown'], true)) {
    fwrite(STDERR, "usage: php e2e-token-policy-fixture.php <pid> setup|teardown\n");
    exit(2);
}

$_GET['pid'] = (string) $pid;
define('NOAUTH', true);
require_once '/var/www/html/redcap_connect.php';

function out(string $label, string $value = ''): void { printf("  [ok] %-44s %s\n", $label, $value); }
function fail(string $msg): void { fwrite(STDERR, "  [FAIL] $msg\n"); exit(1); }
function q(string $sql, array $params = [])
{
    $r = db_query($sql, $params);
    if ($r === false) {
        fail('query failed: ' . db_error());
    }
    return $r;
}

if (empty($GLOBALS['is_development_server'])) {
    fail('Refusing to run: not a development server (is_development_server is off).');
}

const KEYS = [
    'use-high-threshold-models',
    'llm-model', 'llm-model-high',
    'safetyscan-model-alias', 'safetyscan-model-alias-high',
];
$backup = "/tmp/mica_e2e_token_policy_$pid.json";

$moduleId = (int) db_result(q("select external_module_id from redcap_external_modules where directory_prefix = 'proj_mica'"), 0);
$chatId = (int) db_result(q("select external_module_id from redcap_external_modules where directory_prefix = 'secure_chat_ai'"), 0);
if (!$moduleId || !$chatId) {
    fail('proj_mica and secure_chat_ai must both be registered locally');
}
$in = implode(', ', array_fill(0, count(KEYS), '?'));

if ($mode === 'teardown') {
    echo "\n=== restoring MICA token-policy settings on PID $pid ===\n";
    if (!is_file($backup)) {
        out('no backup found', 'nothing to restore');
        exit(0);
    }
    $saved = json_decode((string) file_get_contents($backup), true);
    if (!is_array($saved) || !isset($saved['version'], $saved['rows'])) {
        fail("backup unreadable: $backup (left in place)");
    }
    q("update redcap_external_module_settings set value = ? where external_module_id = ? and project_id is null and `key` = 'version'",
        [$saved['version'], $moduleId]);
    out('active MICA version restored', $saved['version']);
    q("delete from redcap_external_module_settings where external_module_id = ? and project_id = ? and `key` in ($in)",
        array_merge([$moduleId, $pid], KEYS));
    foreach ($saved['rows'] as $r) {
        q("insert into redcap_external_module_settings (external_module_id, project_id, `key`, type, value) values (?, ?, ?, ?, ?)",
            [$moduleId, $pid, $r['key'], $r['type'], $r['value']]);
    }
    out('model settings restored', count($saved['rows']) . ' rows');
    q("delete from redcap_external_module_settings where external_module_id = ? and project_id = ? and `key` = 'token-policy-default'",
        [$chatId, $pid]);
    unlink($backup);
    echo "\n*** DONE ***\n";
    exit(0);
}

echo "\n=== MICA token-policy E2E fixture, PID $pid ===\n";

if (is_file($backup)) {
    fail("a backup already exists at $backup - run teardown first");
}
$highToken = db_result(q("select value from redcap_external_module_settings where external_module_id = ? and project_id is null and `key` = 'aihub-token-high-threshold'", [$chatId]), 0);
if ($highToken !== 'e2e-high-key') {
    fail('SecureChatAI\'s token-policy fixture is not active (run secure_chat_ai tests/e2e/fixture.php setup and start its mock first)');
}
if ((int) db_result(q("select count(*) from redcap_external_module_settings where external_module_id = ? and project_id = ? and `key` = 'token-policy-default'", [$chatId, $pid]), 0) > 0) {
    fail("PID $pid already has a SecureChatAI Minimum Token Policy; this fixture would delete it on teardown");
}

$version = (string) db_result(q("select value from redcap_external_module_settings where external_module_id = ? and project_id is null and `key` = 'version'", [$moduleId]), 0);
if (!preg_match('/_(v\d+\.\d+\.\d+)$/', basename(dirname(__DIR__, 3)), $m)) {
    fail('cannot read a version from this folder name: ' . basename(dirname(__DIR__, 3)));
}
$rows = [];
$r = q("select `key`, type, value from redcap_external_module_settings where external_module_id = ? and project_id = ? and `key` in ($in)",
    array_merge([$moduleId, $pid], KEYS));
while ($row = db_fetch_assoc($r)) {
    $rows[] = $row;
}
file_put_contents($backup, json_encode(['version' => $version, 'rows' => $rows]));
chmod($backup, 0600);
out('backed up', count($rows) . " settings + version $version -> $backup");

q("update redcap_external_module_settings set value = ? where external_module_id = ? and project_id is null and `key` = 'version'",
    [$m[1], $moduleId]);
out('active MICA version', "{$m[1]} (was $version) - every MICA project runs this folder until teardown");

q("delete from redcap_external_module_settings where external_module_id = ? and project_id = ? and `key` in ($in)",
    array_merge([$moduleId, $pid], KEYS));
foreach (['llm-model' => 'gpt-5-4', 'safetyscan-model-alias' => 'gpt-5-4'] as $key => $value) {
    q("insert into redcap_external_module_settings (external_module_id, project_id, `key`, type, value) values (?, ?, ?, 'string', ?)",
        [$moduleId, $pid, $key, $value]);
}
out('standard aliases', 'llm-model = safetyscan-model-alias = gpt-5-4; switch off; high aliases blank');
echo "\n*** DONE - run teardown when finished ***\n";
