<?php
/**
 * Register an AI Hub deployment as a SecureChatAI model alias.
 *
 *   php register-securechat-model.php <alias> [--clone-from=gpt-5-6-sol] [--apply]
 *
 * Example:
 *   php register-securechat-model.php gpt-5-6-luna --apply
 *
 * WHY THIS EXISTS. SecureChatAI has no built-in model list: `api-settings` is a repeatable
 * system sub_setting an admin fills in, stored as eight PARALLEL JSON arrays (the parent flag plus
 * its seven sub-keys). A consumer module such as MICA only offers alias strings in a dropdown - the
 * option is inert until the registry row exists. MICA's `assertModelIsRegistered()` (MICA.php:497)
 * checks `getAvailableModels()` and throws
 *
 *     The configured AI model "X" is not available. Please contact your administrator.
 *
 * which is what a participant's chat turns into. Found 2026-09-22: PIDs 268 and 271 were both
 * configured with `llm-model = gpt-5-6-luna` while the local registry held only `claude-opus-4-7`
 * and `gpt-5-6-sol`.
 *
 * THE ALIAS IS ALSO THE URL PATH SEGMENT, so it has to be a real AI Hub deployment name - those are
 * not derivable from the marketing version (GPT-5.6 ships as sol / luna / terra; there is no bare
 * `gpt-5-6`). This script therefore **probes the endpoint before writing** and refuses on anything
 * other than a 200.
 *
 * CLONING. Everything except the alias and the URL is copied from an existing entry, so the token,
 * key variable and input variable stay consistent with a deployment already known to work. Only
 * clone from an entry on the same provider - copying the Bedrock entry to register an Azure
 * deployment would carry the wrong URL shape.
 */

$args  = array_slice($argv, 1);
$alias = null; $cloneFrom = 'gpt-5-6-sol'; $apply = false;
foreach ($args as $a) {
    if (preg_match('/^--clone-from=(.+)$/', $a, $m)) $cloneFrom = $m[1];
    elseif ($a === '--apply')                        $apply = true;
    elseif ($alias === null)                         $alias = $a;
}
if ($alias === null) {
    fwrite(STDERR, "usage: php register-securechat-model.php <alias> [--clone-from=ALIAS] [--apply]\n");
    exit(1);
}

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

const PREFIX = 'secure_chat_ai';
/** The parent flag plus the seven sub-keys declared in config.json, in config order. */
const KEYS = ['api-settings', 'model-alias', 'model-id', 'api-url', 'api-token',
              'api-key-var', 'api-input-var', 'default-model'];

$EM = '\ExternalModules\ExternalModules';

$reg = [];
foreach (KEYS as $k) {
    $v = $EM::getSystemSetting(PREFIX, $k);
    $reg[$k] = is_array($v) ? $v : [];
}
$aliases = $reg['model-alias'];
$n = count($aliases);

printf("SecureChatAI registry: %d entr%s\n", $n, $n === 1 ? 'y' : 'ies');
foreach ($aliases as $i => $a) printf("  [%d] %-22s %s\n", $i, $a, $reg['api-url'][$i] ?? '');
echo "\n";

// Ragged arrays would corrupt the repeatable setting, so check before touching anything.
foreach (KEYS as $k) {
    if (count($reg[$k]) !== $n) {
        fwrite(STDERR, "REFUSING: '$k' has " . count($reg[$k]) . " entries but model-alias has $n.\n"
                     . "  The registry is already ragged; fix it in the UI before adding to it.\n");
        exit(1);
    }
}

if (in_array($alias, $aliases, true)) {
    printf("'%s' is already registered (index %d) - nothing to do.\n", $alias, array_search($alias, $aliases, true));
    exit(0);
}

$src = array_search($cloneFrom, $aliases, true);
if ($src === false) {
    fwrite(STDERR, "REFUSING: clone source '$cloneFrom' is not in the registry.\n");
    exit(1);
}

// The alias is the deployment name in the path, so swap it there and nowhere else.
$srcUrl = (string) $reg['api-url'][$src];
$newUrl = str_replace($cloneFrom, $alias, $srcUrl);
if ($newUrl === $srcUrl) {
    fwrite(STDERR, "REFUSING: '$cloneFrom' does not appear in its own endpoint URL, so the new URL\n"
                 . "  cannot be derived by substitution. Add this entry through the UI.\n  $srcUrl\n");
    exit(1);
}

printf("cloning [%d] %s\n  url: %s\n   ->  %s\n\n", $src, $cloneFrom, $srcUrl, $newUrl);

// ---- probe before writing: an unregistered-but-also-nonexistent alias is a 404, not a fix -------
$token = (string) $reg['api-token'][$src];
$keyVar = (string) $reg['api-key-var'][$src];
$ch = curl_init($newUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ["$keyVar: $token", 'Content-Type: application/json'],
    // Non-PHI, and `max_completion_tokens` because the 5.6 line is reasoning-class and rejects
    // `max_tokens` outright.
    CURLOPT_POSTFIELDS => json_encode([
        'messages' => [['role' => 'user', 'content' => 'Reply with the single word: ok']],
        'max_completion_tokens' => 16,
    ]),
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

printf("probe: HTTP %d\n", $code);
if ($code !== 200) {
    fwrite(STDERR, "REFUSING: the endpoint did not answer 200, so this alias is not a usable\n"
                 . "  deployment for this token. Registering it would only move the failure later.\n"
                 . '  ' . substr((string) $body, 0, 300) . "\n");
    exit(1);
}
$decoded = json_decode((string) $body, true);
printf("  model: %s\n  reply: %s\n\n",
    $decoded['model'] ?? '(unknown)',
    trim((string) ($decoded['choices'][0]['message']['content'] ?? '')));

if (!$apply) { echo "nothing written. re-run with --apply to register.\n"; exit(0); }

// ---- rollback first, then append one element to every parallel array ---------------------------
$path = __DIR__ . '/rollback-securechat-registry-' . date('Ymd-His') . '.json';
file_put_contents($path, json_encode($reg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$new = $reg;
$new['api-settings'][]  = 'true';
$new['model-alias'][]   = $alias;
$new['model-id'][]      = $alias;         // Azure: the deployment name is the model id
$new['api-url'][]       = $newUrl;
$new['api-token'][]     = $token;
$new['api-key-var'][]   = $keyVar;
$new['api-input-var'][] = (string) $reg['api-input-var'][$src];
$new['default-model'][] = false;          // never steal the default from an existing entry

foreach (KEYS as $k) $EM::setSystemSetting(PREFIX, $k, $new[$k]);

printf("registered '%s'\n", $alias);
printf("rollback written to %s\n\n", $path);

// ---- verify through the consumer's own entry point ---------------------------------------------
$sc = $EM::getModuleInstance(PREFIX);
$available = $sc->getAvailableModels();
printf("getAvailableModels() -> %s\n", implode(', ', (array) $available));
printf("'%s' visible to consumers: %s\n", $alias,
    in_array($alias, (array) $available, true) ? 'YES' : 'NO - investigate before relying on it');
