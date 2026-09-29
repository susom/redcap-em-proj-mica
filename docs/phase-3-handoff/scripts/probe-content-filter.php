<?php

/**
 * Does this server's AI Hub connection refuse self-harm text?
 *
 *   php probe-content-filter.php [alias ...]        # default: gpt-5-6-luna gpt-5-6-sol
 *
 * Run it from the REDCap web root on the server in question, as a user that can read REDCap's
 * database config. Read-only: it sends two made-up sentences to each alias through the alias's own
 * SecureChatAI registry entry and prints what came back. Nothing is written anywhere.
 *
 * WHY. SecureChatAI throws the provider's response body away on an error (PHI safety), so a refusal
 * reaches its log as `HTTP error: 400 (response body omitted; length=N bytes)` and nothing more. On
 * 2026-09-28 prod 35968 logged exactly that, 988 bytes, for a counselor turn whose participant text was
 * "Honestly I don't see the point of anything anymore." The first three messages of the same test
 * had gone through. A refusal that depends on the words is what Azure's content filter does, and the
 * same sentence went through on the development server's key. The body is the only thing that says
 * which it was, and reading it needs the server's own key, which is why this runs on the server.
 *
 * What it prints, per alias:
 *   - the registry entry's host and the sha256 prefix of its key, so two servers' keys can be
 *     compared without either being shown (the development key starts 5633477c62)
 *   - for a neutral sentence and for the sentence above: HTTP status, body size, and on a refusal
 *     the provider's error code and message and which content-filter categories fired
 *   - on success, the filter's own severity rating of the prompt, when the provider returns one
 *
 * Both sentences are synthetic. No participant text, record id or transcript is sent, and the key is
 * never printed.
 */

$aliases = array_slice($argv, 1) ?: ['gpt-5-6-luna', 'gpt-5-6-sol'];

define('NOAUTH', true);
require_once probeFindRedcapConnect();

function probeFindRedcapConnect(): string
{
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) {
        return "$env/redcap_connect.php";
    }
    foreach ([getcwd(), '/var/www/html'] as $guess) {
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
    fwrite(STDERR, "Could not locate redcap_connect.php. Run from the REDCap web root or set REDCAP_ROOT.\n");
    exit(2);
}

const PROBES = [
    'neutral'   => 'Reply with the single word: ok',
    'self-harm' => "Honestly I don't see the point of anything anymore.",
];

$em = '\ExternalModules\ExternalModules';
$registry = [];
foreach (['model-alias', 'api-url', 'api-token', 'api-key-var'] as $key) {
    $value = $em::getSystemSetting('secure_chat_ai', $key);
    $registry[$key] = is_array($value) ? $value : [];
}

$failed = false;

foreach ($aliases as $alias) {
    $i = array_search($alias, $registry['model-alias'], true);
    if ($i === false) {
        printf("%s: NOT in this server's SecureChatAI registry\n\n", $alias);
        $failed = true;
        continue;
    }

    $url = (string) ($registry['api-url'][$i] ?? '');
    $token = (string) ($registry['api-token'][$i] ?? '');
    $header = (string) ($registry['api-key-var'][$i] ?? 'api-key');

    printf(
        "%s\n  host %s, key sha256 %s\n",
        $alias,
        parse_url($url, PHP_URL_HOST) ?: '(no url)',
        $token === '' ? '(empty)' : substr(hash('sha256', $token), 0, 10)
    );

    foreach (PROBES as $label => $sentence) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => ["$header: $token", 'Content-Type: application/json'],
            // max_completion_tokens: the GPT-5.6 line is reasoning-class and refuses max_tokens.
            CURLOPT_POSTFIELDS     => json_encode([
                'messages'              => [
                    ['role' => 'system', 'content' => 'You are a supportive assistant. Reply in one short sentence.'],
                    ['role' => 'user', 'content' => $sentence],
                ],
                'max_completion_tokens' => 400,
            ]),
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode($body, true);
        printf("  %-9s HTTP %d, %d bytes", $label, $code, strlen($body));

        if ($code === 0) {
            printf(" - no response: %s\n", $curlError);
            $failed = true;
            continue;
        }

        if ($code !== 200) {
            $failed = true;
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $filter = $error['innererror']['content_filter_result'] ?? [];
            $fired = array_keys(array_filter(
                is_array($filter) ? $filter : [],
                static fn($r): bool => is_array($r)
                    && (($r['filtered'] ?? false) === true || ($r['detected'] ?? false) === true)
            ));
            printf(
                "\n    code: %s\n    message: %s\n    filter fired: %s\n",
                (string) ($error['code'] ?? '(none)'),
                substr((string) ($error['message'] ?? substr($body, 0, 200)), 0, 200),
                $fired === [] ? '(none reported)' : implode(', ', $fired)
            );
            continue;
        }

        // A 200 still carries the filter's rating of the prompt; worth seeing how close it came.
        $ratings = [];
        foreach ($decoded['prompt_filter_results'][0]['content_filter_results'] ?? [] as $category => $r) {
            if (is_array($r) && isset($r['severity']) && $r['severity'] !== 'safe') {
                $ratings[] = "$category=" . $r['severity'];
            }
        }
        printf(" - answered%s\n", $ratings === [] ? '' : ' (prompt rated ' . implode(', ', $ratings) . ')');
    }
    echo "\n";
}

exit($failed ? 1 : 0);
