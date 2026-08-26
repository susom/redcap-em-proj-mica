<?php

/**
 * See the exact JSON body MICA sends to the LLM, as a runnable curl command.
 *
 *   S=/var/www/html/modules-local/proj_mica_v9.9.9/docs/phase-3-handoff/scripts
 *   docker exec <web> php $S/capture-llm-payload.php on
 *   ... have a chat turn happen in the SPA ...
 *   docker exec <web> php $S/capture-llm-payload.php last
 *   docker exec <web> php $S/capture-llm-payload.php off
 *
 * Commands: on | off | status | list | last | shape
 *
 * Full write-up: ../20-llm-request-capture.md
 *
 * ## What this is for
 *
 * The body that reaches the provider is NOT the array MICA builds. Between `MICA::redcap_module_ajax`
 * and the socket, SecureChatAI merges its own defaults, strips parameters the model will not accept,
 * rewrites `json_schema` into `response_format`, and swaps the model alias for the deployment id. So
 * reading MICA.php tells you what MICA asked for, not what was sent. `shape` prints the derivation;
 * `on`/`last` capture the real bytes.
 *
 * ## Why it is off by default, and why arming takes two steps
 *
 * The request body is the prompt, and the prompt is PHI. SecureChatAI is deliberately built so this
 * never lands in a log - `callAI()`'s catch says "Never log $params directly", and the HTTP-error
 * path omits the response body for the same reason. The capture therefore writes to a directory on
 * disk that somebody has to create, never to the REDCap log tables, and never writes the API key.
 *
 * Disarm by running `off`, or by deleting the directory. Either one alone is enough.
 */

$COMMAND = strtolower(trim($argv[1] ?? 'status'));
$PID     = (int) (getenv('MICA_PID') ?: 257);

/** Host path: <redcap-docker-compose>/www/temp/mica/llm-capture */
const DEFAULT_CAPTURE_DIR = '/var/www/html/temp/mica/llm-capture';
const SETTING_KEY         = 'securechat-capture-dir';

define('NOAUTH', true);
$_GET['pid'] = (string) $PID;
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
    fwrite(STDERR, "Could not locate redcap_connect.php. Set REDCAP_ROOT=/path/to/redcap web root.\n");
    exit(1);
}

/** @var \Stanford\SecureChatAI\SecureChatAI $chat */
$chat = \ExternalModules\ExternalModules::getModuleInstance('secure_chat_ai');
/** @var \Stanford\MICA\MICA $mica */
$mica = \ExternalModules\ExternalModules::getModuleInstance('proj_mica');

$configuredDir = trim((string) ($chat->getSystemSetting(SETTING_KEY) ?? ''));
$envDir        = trim((string) (getenv('SECURECHAT_CAPTURE_DIR') ?: ''));
$activeDir     = $envDir !== '' ? $envDir : $configuredDir;

switch ($COMMAND) {
    case 'on':
        $dir = trim((string) ($argv[2] ?? DEFAULT_CAPTURE_DIR));
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            fwrite(STDERR, "Could not create $dir\n");
            exit(1);
        }
        // CLI has no REDCap user, and the framework refuses to save a setting without one.
        $chat->disableUserBasedSettingPermissions();
        $chat->setSystemSetting(SETTING_KEY, $dir);
        echo "Capture ARMED.\n";
        echo "  directory : $dir\n";
        echo "  writable  : " . (is_writable($dir) ? 'yes' : 'NO - capture will stay off') . "\n\n";
        echo "Now make one chat turn happen, then run: capture-llm-payload.php last\n";
        echo "Turn it back off when you are done: capture-llm-payload.php off\n";
        break;

    case 'off':
        $chat->disableUserBasedSettingPermissions();
        $chat->removeSystemSetting(SETTING_KEY);
        echo "Capture DISARMED (setting removed).\n";
        if ($envDir !== '') {
            echo "WARNING: SECURECHAT_CAPTURE_DIR=$envDir is still set in the environment and wins\n"
               . "         over the setting. Unset it to fully disarm.\n";
        }
        if ($configuredDir !== '' && is_dir($configuredDir)) {
            echo "Captured files are still on disk in $configuredDir - they contain prompt text.\n";
            echo "Delete them when you no longer need them.\n";
        }
        break;

    case 'status':
        echo "Capture is " . ($activeDir !== '' && is_dir($activeDir) && is_writable($activeDir) ? 'ON' : 'OFF') . "\n";
        echo "  system setting   : " . ($configuredDir !== '' ? $configuredDir : '(unset)') . "\n";
        echo "  env override     : " . ($envDir !== '' ? $envDir : '(unset)') . "\n";
        echo "  directory exists : " . ($activeDir !== '' && is_dir($activeDir) ? 'yes' : 'no') . "\n";
        echo "  files captured   : " . count(capture_files($activeDir)) . "\n";
        break;

    case 'list':
        foreach (capture_files($activeDir) as $f) {
            printf("  %s  %7d bytes\n", basename($f), filesize($f));
        }
        break;

    case 'last':
        $files = capture_files($activeDir);
        if (empty($files)) {
            echo "Nothing captured yet.\n";
            echo $activeDir === ''
                ? "Capture is off - run: capture-llm-payload.php on\n"
                : "Capture is armed at $activeDir; make a chat turn happen and try again.\n";
            exit(1);
        }
        $body = end($files);
        $curl = preg_replace('/\.body\.json$/', '.curl.sh', $body);

        echo "=== JSON body sent to the provider ===\n";
        echo "    $body\n\n";
        $decoded = json_decode((string) file_get_contents($body), true);
        echo $decoded === null
            ? (string) file_get_contents($body)
            : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo "\n\n=== Runnable curl ===\n";
        echo "    $curl\n\n";
        echo is_file((string) $curl) ? (string) file_get_contents((string) $curl) : "(missing)\n";
        break;

    case 'shape':
        print_shape($mica, $chat, $PID);
        break;

    default:
        fwrite(STDERR, "Unknown command '$COMMAND'. Use: on | off | status | list | last | shape\n");
        exit(1);
}

/** @return string[] captured body files, oldest first */
function capture_files(string $dir): array
{
    if ($dir === '' || !is_dir($dir)) {
        return [];
    }
    $files = glob(rtrim($dir, '/') . '/*.body.json') ?: [];
    sort($files);
    return $files;
}

/**
 * Derive the body WITHOUT calling the provider, by replaying SecureChatAI's own filtering rules
 * against this project's settings. Prompt content is replaced with placeholders, so this is safe to
 * paste into a ticket - it shows the envelope, not the conversation.
 */
function print_shape($mica, $chat, int $PID): void
{
    $alias = (string) $mica->getProjectSetting('llm-model', $PID);
    echo "PID $PID  llm-model = " . ($alias !== '' ? $alias : '(unset)') . "\n\n";

    if ($alias === '') {
        echo "No model configured; nothing to derive.\n";
        return;
    }

    // Mirrors SecureChatAI::filterDefaultParamsForModel(). Duplicated knowingly, for the same reason
    // SecureChatSafetyScanCaller::SCHEMA_MODELS is: this script must not need the provider to answer.
    $reasoningModels = ['o1', 'o3-mini', 'o3', 'o4-mini', 'gpt-5', 'gpt-5-6-sol', 'gpt-5-6-luna', 'gpt-5-6-terra'];
    $isReasoning     = in_array($alias, $reasoningModels, true);

    $micaParams = [];
    foreach ([
        'temperature'       => 'gpt-temperature',
        'top_p'             => 'gpt-top-p',
        'frequency_penalty' => 'gpt-frequency-penalty',
        'presence_penalty'  => 'gpt-presence-penalty',
        'max_tokens'        => 'gpt-max-tokens',
        'reasoning_effort'  => 'reasoning-effort',
    ] as $param => $setting) {
        $value = $mica->getProjectSetting($setting, $PID);
        if ($value === null || (is_string($value) && trim($value) === '')) {
            continue;
        }
        $micaParams[$param] = is_numeric($value)
            ? (str_contains((string) $value, '.') ? (float) $value : (int) $value)
            : $value;
    }

    echo "MICA adds these from its project settings: "
        . (empty($micaParams) ? '(none)' : json_encode($micaParams)) . "\n";

    $defaults = [
        'temperature'       => (float) $chat->getSystemSetting('gpt-temperature') ?: 0.7,
        'top_p'             => (float) $chat->getSystemSetting('gpt-top-p') ?: 0.9,
        'frequency_penalty' => (float) $chat->getSystemSetting('gpt-frequency-penalty') ?: 0.5,
        'presence_penalty'  => (float) $chat->getSystemSetting('gpt-presence-penalty') ?: 0,
        'max_tokens'        => (int) $chat->getSystemSetting('gpt-max-tokens') ?: 16384,
        'reasoning_effort'  => $chat->getSystemSetting('reasoning-effort'),
        'stop'              => null,
    ];
    $merged = array_merge($defaults, $micaParams);

    $messagesPlaceholder = [
        ['role' => 'system',    'content' => '<counselor system prompt + baseline context>'],
        ['role' => 'user',      'content' => '<participant turn 1>'],
        ['role' => 'assistant', 'content' => '<counselor turn 1>'],
        ['role' => 'user',      'content' => '<participant turn N>'],
    ];

    if ($isReasoning) {
        $body = [
            'model'                 => $alias,
            'messages'              => $messagesPlaceholder,
            'max_completion_tokens' => $merged['max_completion_tokens'] ?? ($merged['max_tokens'] ?? 32000),
        ];
        if (isset($merged['reasoning_effort'])) {
            $body['reasoning_effort'] = $merged['reasoning_effort'];
        }
        echo "\n'$alias' is a reasoning alias, so SecureChatAI REPLACES the merged parameters with a\n"
           . "strict three-key body - temperature, top_p, the penalties, stop and session_id are all\n"
           . "dropped before the request is built (SecureChatAI.php:406-421).\n";
    } else {
        $body = $merged;
        $body['model']    = $alias;
        $body['messages'] = $messagesPlaceholder;
        unset($body['max_tokens'], $body['session_id'], $body['agent_mode'], $body['project_id'], $body['log_turn']);
        echo "\n'$alias' is not a reasoning alias, so the merged parameters survive minus max_tokens\n"
           . "and the internal keys (SecureChatAI.php:423-429).\n";
    }

    echo "\n=== Derived request body ===\n";
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    echo "\nThe `model` key is then overwritten with the deployment's model-id, and the URL, auth header\n"
       . "and key all come from the SecureChatAI api-settings row for this alias. Run `on` then `last`\n"
       . "for the real bytes rather than this derivation.\n";
}
