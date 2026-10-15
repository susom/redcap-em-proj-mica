<?php
/**
 * AI token policy, end to end below the browser: which AI Hub model set and key each MICA call
 * reaches, and that every refusal is made before anything is sent.
 *
 *   docker exec <web> php verify-token-policy.php <pid>
 *
 * Needs SecureChatAI's token-policy fixture and mock AI Hub running, then this module's fixture:
 *   docker exec <web> php .../secure_chat_ai_v9.9.9/tests/e2e/fixture.php setup
 *   docker exec -d <web> php -S 127.0.0.1:8099 .../secure_chat_ai_v9.9.9/tests/e2e/mock_aihub.php
 *   docker exec <web> php e2e-token-policy-fixture.php <pid> setup
 *
 * The mock serves `/<policy>/<alias>` and logs the path and key of every request, so "which set,
 * which key" is read off the wire rather than inferred. Its registry: standard gpt-4-1, gpt-5-4;
 * high-threshold gpt-5-4, gpt-4-1-nano. Each case resets the log, sets the project's settings,
 * makes the real call (SecureChatSafetyScanCaller::scan, the counselor's assertModelIsRegistered +
 * callAI), and reads the log back. The transcript is two invented lines.
 *
 * Leaves the project's settings as the fixture set them.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$PID = (int) ($argv[1] ?? 0);
if ($PID <= 0) {
    fwrite(STDERR, "usage: php verify-token-policy.php <pid>\n");
    exit(2);
}
$_GET['pid'] = (string) $PID;
define('NOAUTH', true);
require_once '/var/www/html/redcap_connect.php';

const MOCK = 'http://127.0.0.1:8099';
const MOCK_LOG = '/tmp/sca_mock_aihub.jsonl';

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s %s%s\n", $ok ? '[ok]  ' : '[FAIL]', $name, !$ok && $detail !== '' ? " - $detail" : '');
}

$mica = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');
if (!method_exists($mica, 'tokenPolicy')) {
    fwrite(STDERR, "The active MICA version has no token-policy support: run e2e-token-policy-fixture.php setup.\n");
    exit(1);
}
$chat = $mica->getSecureChatInstance();
if (@file_get_contents(MOCK . '/__reset') === false) {
    fwrite(STDERR, 'The mock AI Hub is not answering at ' . MOCK . ".\n");
    exit(1);
}
$mica->disableUserBasedSettingPermissions();
$chat->disableUserBasedSettingPermissions();

/** @param array<string,mixed> $settings null removes the setting */
function settings(array $settings): void
{
    global $mica, $PID;
    foreach ($settings as $key => $value) {
        $value === null ? $mica->removeProjectSetting($key, $PID) : $mica->setProjectSetting($key, $value, $PID);
    }
}

/** @return list<array<string,mixed>> */
function wire(): array
{
    $lines = is_file(MOCK_LOG) ? file(MOCK_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    return array_map(static fn(string $l): array => json_decode($l, true), $lines);
}

function reset_wire(): void
{
    file_get_contents(MOCK . '/__reset');
}

function scan(string $alias): array
{
    global $mica, $PID;
    reset_wire();
    return (new \Stanford\MICA\SecureChatSafetyScanCaller($mica, $PID))->scan(
        $alias,
        'Return a JSON object.',
        '{"messages":[{"role":"participant","text":"E2E token policy - synthetic line"}]}',
        ['type' => 'object']
    );
}

/** The counselor path as the ajax handler runs it: the guard, then callAI with the policy params. */
function counselor(): array
{
    global $mica, $PID;
    reset_wire();
    $guard = new \ReflectionMethod($mica, 'assertModelIsRegistered');
    $guard->setAccessible(true);
    $model = $mica->modelAlias('counselor', $PID);
    try {
        $guard->invoke($mica, $model);
    } catch (\Exception $e) {
        return ['refused' => $e->getMessage(), 'response' => null];
    }
    $response = $mica->getSecureChatInstance()->callAI(
        $model,
        ['messages' => [['role' => 'user', 'content' => 'E2E token policy - synthetic turn']]] + $mica->tokenPolicyParams($PID),
        $PID,
        null
    );
    return ['refused' => null, 'response' => $response];
}

$std = ['path' => '/standard/gpt-5-4', 'key' => 'e2e-std-key'];
$high = ['path' => '/high/gpt-5-4', 'key' => 'e2e-high-key'];
$hits = static fn(array $want): bool => ($w = wire()) !== []
    && array_filter($w, static fn($r) => $r['path'] === $want['path'] && $r['key'] === $want['key']) === $w;

echo "\n=== MICA AI token policy, PID $PID ===\n";
echo "\nStandard (switch off)\n";
settings(['use-high-threshold-models' => null, 'llm-model-high' => null, 'safetyscan-model-alias-high' => null]);
check('V1 policy is standard', $mica->tokenPolicy($PID) === 'standard');
check('V2 registry is the standard set', $mica->availableModelAliases($PID) === ['gpt-4-1', 'gpt-5-4'],
    json_encode($mica->availableModelAliases($PID)));
$r = scan($mica->scanModelAlias($PID));
check('V3 scan sent once, to the standard set on the standard key', $hits($std), json_encode(wire()));
check('V4 scan request carries no token_policy upstream', !array_filter(wire(), fn($w) => $w['raw_has_token_policy']));
check('V5 scan request asked for structured output', in_array('response_format', wire()[0]['body_keys'] ?? [], true));
$c = counselor();
check('V6 counselor turn answered from the standard set on the standard key', $c['refused'] === null && $hits($std),
    ($c['refused'] ?? '') . json_encode(wire()));

echo "\nHigh-threshold (switch on)\n";
settings(['use-high-threshold-models' => true, 'llm-model-high' => 'gpt-5-4', 'safetyscan-model-alias-high' => 'gpt-5-4']);
check('H1 policy is high', $mica->tokenPolicy($PID) === 'high');
check('H2 registry is the high-threshold set', $mica->availableModelAliases($PID) === ['gpt-5-4', 'gpt-4-1-nano'],
    json_encode($mica->availableModelAliases($PID)));
check('H3 each role reads its high-threshold setting',
    $mica->modelAlias('counselor', $PID) === 'gpt-5-4' && $mica->scanModelAlias($PID) === 'gpt-5-4');
$r = scan($mica->scanModelAlias($PID));
check('H4 scan sent once, to the high-threshold set on the high-threshold key', $hits($high), json_encode(wire()));
check('H5 scan request carries no token_policy upstream', !array_filter(wire(), fn($w) => $w['raw_has_token_policy']));
check('H6 scan request still asked for structured output', in_array('response_format', wire()[0]['body_keys'] ?? [], true));
$c = counselor();
check('H7 counselor turn answered from the high-threshold set on the high-threshold key',
    $c['refused'] === null && $hits($high), ($c['refused'] ?? '') . json_encode(wire()));
check('H8 the answer is the high-threshold model\'s',
    str_contains((string) ($c['response']['content'] ?? ''), 'path=/high/gpt-5-4 key=e2e-high-key'),
    (string) ($c['response']['content'] ?? ''));

echo "\nHigh-threshold refusals: nothing is sent\n";
$refusals = [
    'R1 a placeholder alias'               => [['llm-model-high' => 'placeholder-high-model-1', 'safetyscan-model-alias-high' => 'placeholder-high-model-1'], 'placeholder'],
    'R2 a blank alias'                     => [['llm-model-high' => null, 'safetyscan-model-alias-high' => null], 'is blank'],
    'R3 an alias only in the standard set' => [['llm-model-high' => 'gpt-4-1', 'safetyscan-model-alias-high' => 'gpt-4-1'], 'not registered in SecureChatAI under the high-threshold policy'],
];
foreach ($refusals as $name => [$set, $why]) {
    settings($set);
    $alias = $mica->scanModelAlias($PID);
    $r = scan($alias);
    check("$name: scan refused before sending", $r['runStatus'] === 'service_error'
        && str_contains((string) $r['error'], 'refused under the AI token policy')
        && str_contains((string) $r['error'], $why) && wire() === [], json_encode([$r['error'], wire()]));
    check("$name: never falls back to the standard alias", $alias !== 'gpt-5-4' && $alias !== 'gemini-2.5-flash', $alias);
    $c = counselor();
    check("$name: counselor turn refused before sending", $c['refused'] !== null && wire() === [], json_encode(wire()));
    check("$name: participant sees no policy detail", $c['refused'] !== null
        && str_contains($c['refused'], 'is not available. Please contact your administrator.')
        && !str_contains($c['refused'], 'policy') && !str_contains($c['refused'], 'high-threshold'), (string) $c['refused']);
}

echo "\nSecureChatAI's own project minimum above MICA's switch\n";
settings(['use-high-threshold-models' => null, 'llm-model-high' => null, 'safetyscan-model-alias-high' => null]);
$chat->setProjectSetting('token-policy-default', 'high', $PID);
$r = scan($mica->scanModelAlias($PID));
check('M1 scan refused before sending', $r['runStatus'] === 'service_error'
    && str_contains((string) $r['error'], 'Use high-threshold AI models" is off') && wire() === [], json_encode([$r['error'], wire()]));
$c = counselor();
check('M2 counselor turn refused before sending', $c['refused'] !== null && wire() === [], json_encode(wire()));
$chat->removeProjectSetting('token-policy-default', $PID);
$r = scan($mica->scanModelAlias($PID));
check('M3 removing the minimum restores the standard set', $hits($std), json_encode(wire()));

echo "\nLaunch readiness, models gate\n";
$gate = static function () use ($mica, $PID) {
    foreach ($mica->launchReadinessFor($PID)->evaluate() as $g) {
        if ($g->id === 'models') {
            return $g;
        }
    }
    return null;
};
$g = $gate();
check('L1 standard: passes', $g !== null && $g->passed, $g->detail ?? '');
settings(['use-high-threshold-models' => true, 'llm-model-high' => 'gpt-5-4', 'safetyscan-model-alias-high' => 'gpt-5-4']);
$g = $gate();
check('L2 high-threshold: passes and says so', $g !== null && $g->passed && str_contains($g->detail, 'high-threshold'), $g->detail ?? '');
settings(['safetyscan-model-alias-high' => 'placeholder-high-model-2']);
$g = $gate();
check('L3 a placeholder fails, named as one', $g !== null && !$g->passed && str_contains($g->detail, 'placeholder'), $g->detail ?? '');

// The settings dialog is checked in a real browser (e2e/token-policy.js): the framework only lets
// a unit test spoof super-user status, and the dialog's show/hide is client-side anyway.

// Back to the fixture's starting point.
settings(['use-high-threshold-models' => null, 'llm-model-high' => null, 'safetyscan-model-alias-high' => null]);
reset_wire();

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
