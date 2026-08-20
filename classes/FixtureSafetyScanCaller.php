<?php

namespace Stanford\MICA;

require_once __DIR__ . "/SafetyScanCallerInterface.php";

/**
 * `scan-mock-mode`: replay a fixture instead of calling a model.
 *
 * Exists so the Playwright suite and the live verification scripts can exercise the whole pipeline -
 * queue, claim, verify, write findings, RA queue - with no network and no provider spend. That
 * matters more than convenience: the failure branches worth testing end to end (a fabricated quote,
 * an `unable_to_assess`, a content filter) are ones a real model will not produce on demand.
 *
 * Two things it deliberately does *not* do:
 *
 *   It does not bypass verification. A fixture goes through the same schema validation and the same
 *   byte-exact quote check as a real response, which is what makes a mock-mode E2E meaningful - a
 *   fixture whose quotes do not match the transcript fails, exactly as a real model would.
 *
 *   It does not stand in for a live smoke test. Mock mode proves the plumbing; only a real call
 *   proves the model and the deployment. The Stage 6 launch gate refuses production while
 *   `scan-mock-mode` is on, for that reason.
 *
 * Fixture selection is by name, from the transcript's own content, so a test can choose a branch
 * without threading configuration through the queue: a transcript is written with a marker and the
 * matching fixture answers.
 */
class FixtureSafetyScanCaller implements SafetyScanCallerInterface
{
    /** Selector marker a test transcript can contain, mapped to a fixture file. */
    public const MARKER = '[[scan-fixture:';

    private string $fixtureDir;
    private string $default;
    /** @var callable(string): void */
    private $logger;

    /** @param callable(string): void|null $logger */
    public function __construct(
        ?string $fixtureDir = null,
        string $default = 'no_supported_concern',
        ?callable $logger = null
    ) {
        $this->fixtureDir = $fixtureDir ?? __DIR__ . '/../tests/fixtures/scan';
        $this->default = $default;
        $this->logger = $logger ?? static function (string $m): void {
        };
    }

    public function scan(
        string $modelAlias,
        string $systemPrompt,
        string $transcriptJson,
        array $outputSchema
    ): array {
        $name = $this->selectFixture($transcriptJson);
        $path = $this->fixtureDir . '/' . $name . '.json';

        if (!is_file($path)) {
            // A missing fixture is a broken test setup, and it must not look like a clean scan.
            return [
                'runStatus'        => 'service_error',
                'output'           => null,
                'error'            => "scan-mock-mode is on but fixture \"$name\" does not exist at $path.",
                'resolvedModel'    => null,
                'latencyMs'        => 0,
                'promptTokens'     => null,
                'completionTokens' => null,
                'schemaWasSent'    => true,
            ];
        }

        $fixture = json_decode((string) file_get_contents($path), true);

        if (!is_array($fixture)) {
            return [
                'runStatus'        => 'invalid_json',
                'output'           => null,
                'error'            => "Fixture \"$name\" is not valid JSON.",
                'resolvedModel'    => null,
                'latencyMs'        => 0,
                'promptTokens'     => null,
                'completionTokens' => null,
                'schemaWasSent'    => true,
            ];
        }

        // A fixture may script a transport failure rather than a model answer, so the retry and
        // give-up paths are reachable without breaking a provider on purpose.
        if (isset($fixture['__runStatus'])) {
            $this->log("mock mode: fixture \"$name\" scripts a {$fixture['__runStatus']} failure");

            return [
                'runStatus'        => (string) $fixture['__runStatus'],
                'output'           => null,
                'error'            => (string) ($fixture['__error'] ?? "scripted by fixture \"$name\""),
                'resolvedModel'    => 'mock',
                'latencyMs'        => 1,
                'promptTokens'     => null,
                'completionTokens' => null,
                'schemaWasSent'    => (bool) ($fixture['__schemaWasSent'] ?? true),
            ];
        }

        $this->log("mock mode: replaying fixture \"$name\"");

        return [
            'runStatus'        => 'ok',
            'output'           => $this->resolveMessageIds($fixture, $transcriptJson),
            'error'            => null,
            'resolvedModel'    => 'mock:' . $name,
            'latencyMs'        => 1,
            'promptTokens'     => null,
            'completionTokens' => null,
            'schemaWasSent'    => true,
        ];
    }

    /**
     * Resolve `"message_id": "#1"` in a fixture to the real message whose `sequence` is 1.
     *
     * A fixture cannot hardcode a message id: real ids are `L<log_id>` from the database, so a
     * fixture written against `L1` cites a message that is not in the transcript and every scan
     * fails as `citation_mismatch`. Which is exactly what happened the first time this ran - the
     * release path was untestable in mock mode.
     *
     * `#n` means "the nth message", which is what a fixture author actually means. An unresolvable
     * placeholder is left alone rather than guessed, so it still fails verification loudly.
     *
     * This resolves an *identifier*; it does not touch `exact_quote`. The quote still has to match
     * the stored text byte for byte, which is what keeps `fabricated_quote` a real test.
     */
    private function resolveMessageIds(array $fixture, string $transcriptJson): array
    {
        $transcript = json_decode($transcriptJson, true);
        if (!is_array($transcript) || empty($transcript['messages'])) {
            return $fixture;
        }

        $bySequence = [];
        foreach ($transcript['messages'] as $message) {
            $bySequence[(int) ($message['sequence'] ?? 0)] = (string) ($message['message_id'] ?? '');
        }

        foreach ($fixture['findings'] ?? [] as $f => $finding) {
            foreach ($finding['evidence'] ?? [] as $e => $evidence) {
                $id = (string) ($evidence['message_id'] ?? '');

                if (preg_match('/^#(\d+)$/', $id, $m) !== 1) {
                    continue;
                }

                $resolved = $bySequence[(int) $m[1]] ?? null;
                if ($resolved !== null) {
                    $fixture['findings'][$f]['evidence'][$e]['message_id'] = $resolved;
                }
            }
        }

        return $fixture;
    }

    private function selectFixture(string $transcriptJson): string
    {
        $start = strpos($transcriptJson, self::MARKER);
        if ($start === false) {
            return $this->default;
        }

        $from = $start + strlen(self::MARKER);
        $end = strpos($transcriptJson, ']]', $from);
        if ($end === false) {
            return $this->default;
        }

        $name = substr($transcriptJson, $from, $end - $from);

        // Basename-only, so a marker inside participant text cannot read a file outside the fixture
        // directory. Mock mode is development-only, but a path traversal reachable from message
        // content is not something to leave lying around.
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) === 1 ? $name : $this->default;
    }

    private function log(string $message): void
    {
        ($this->logger)($message);
    }
}
