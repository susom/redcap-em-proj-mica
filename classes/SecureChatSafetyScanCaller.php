<?php

namespace Stanford\MICA;

require_once __DIR__ . "/SafetyScanCallerInterface.php";

/**
 * SafetyScan over SecureChatAI.
 *
 * Almost all of this class is failure classification, because the provider wrapper makes that hard
 * on purpose and getting it wrong is the difference between "manual review" and a silent clean
 * screen. Three verified facts about SecureChatAI shape it (13-securechatai-current-state-delta.md,
 * re-verified against the source):
 *
 * 1. **`sanitizeOutputForUI()` strips `error` and `type`** and returns a friendly assistant message,
 *    so a consumer cannot tell a provider failure from a real answer by looking for an error key.
 *    The interim signal, until SecureChatAI PR #1 lands, is `model === null && usage === null` on
 *    the non-agent path. Sound only because MICA never sets `agent_mode`; asserted below rather
 *    than assumed, and the whole heuristic is quarantined in one method so its removal is one edit.
 *
 * 2. **`json_schema` is silently `unset()` for any model outside an OpenAI allowlist**
 *    (`['gpt-4-1', …, 'o4-mini']` at SecureChatAI.php:399). Gemini - the SafetyScan model candidate
 *    - is not in it. So structured output is *requested and dropped*, the model answers in prose,
 *    and the scan fails as `invalid_json` for a configuration reason. That is what
 *    `schemaWasSent: false` on the run row is for; SecureChatAI PR #2 is the real fix.
 *
 * 3. **`structured_output` is only populated on the OpenAI-compatible path.** For a Claude-family
 *    model `normalizeResponse()` sets `content` alone. So the reader tries `structured_output`
 *    first and falls back to parsing `content` - which is not belt-and-braces, it is the only way a
 *    non-OpenAI alias can work at all today.
 */
class SecureChatSafetyScanCaller implements SafetyScanCallerInterface
{
    /** Mirrors SecureChatAI.php:399. Duplicated knowingly - see schemaWouldBeSent(). */
    private const OPENAI_SCHEMA_MODELS = [
        'gpt-4-1', 'gpt-4-1-nano', 'gpt-5', 'gpt-5-4', 'gpt-5-4-nano', 'o1', 'o3', 'o3-mini', 'o4-mini',
    ];

    private MICA $module;
    private ?int $projectId;

    public function __construct(MICA $module, ?int $projectId = null)
    {
        $this->module = $module;
        $this->projectId = $projectId;
    }

    public function scan(
        string $modelAlias,
        string $systemPrompt,
        string $transcriptJson,
        array $outputSchema
    ): array {
        $schemaWasSent = $this->schemaWouldBeSent($modelAlias);
        $startedAt = microtime(true);

        /**
         * When structured output is unavailable, the schema goes in the prompt instead.
         *
         * The pinned prompt does not describe the output shape - it relies entirely on `json_schema`
         * - so on a deployment whose only model is outside SecureChatAI's OpenAI allowlist the model
         * has no way to know the shape and the scan fails as `schema_invalid` every single time. Not
         * a model-quality problem: observed on PID 257, where Claude produced a genuinely good
         * clinical read under invented key names (`concern: "self_harm_risk"` for
         * `concern_type: "self_harm"`, bare quote strings where verifiable evidence objects are
         * required) and was correctly rejected in full.
         *
         * This is a fallback, not a replacement. It fires only when the alternative is guaranteed
         * failure, the pinned artifact is untouched and its hash still recorded, and the run row
         * records `schema_in_prompt` so a finding says how its shape was obtained - a finding
         * produced this way is not strictly comparable to one produced with real structured output.
         * SecureChatAI PR #2 remains the durable fix.
         */
        $prompt = $schemaWasSent
            ? $systemPrompt
            : $this->promptWithSchema($systemPrompt, $outputSchema);

        try {
            $response = $this->module->getSecureChatInstance()->callAI(
                $modelAlias,
                [
                    'messages' => [
                        ['role' => 'system', 'content' => $prompt],
                        ['role' => 'user', 'content' => $transcriptJson],
                    ],
                    'json_schema' => $outputSchema,
                ],
                $this->projectId,
                // No username: the scan is a system action against a pseudonymous transcript, and
                // there is no participant identity to attach even if one were wanted.
                null
            );
        } catch (\Throwable $e) {
            return $this->failure(
                $this->classifyThrowable($e),
                get_class($e) . ': ' . $e->getMessage(),
                $startedAt,
                $schemaWasSent
            );
        }

        $latency = (int) round((microtime(true) - $startedAt) * 1000);

        if (!is_array($response)) {
            return $this->failure(
                'service_error',
                'The provider returned a non-array response.',
                $startedAt,
                $schemaWasSent
            );
        }

        // An explicit error survives when the caller is not the UI path; check it before the
        // heuristic, because a real error type is far better evidence than an absence of fields.
        if (isset($response['error'])) {
            $message = is_string($response['error']) ? $response['error'] : json_encode($response['error']);
            return $this->failure(
                $this->classifyErrorText((string) ($response['type'] ?? '') . ' ' . $message),
                $message,
                $startedAt,
                $schemaWasSent,
                $response
            );
        }

        if ($this->looksLikeASanitizedFailure($response)) {
            return $this->failure(
                'service_error',
                'The provider reported a failure that SecureChatAI rewrote as an assistant message '
                . '(no model and no usage on the response). Until SecureChatAI PR #1 lands there is '
                . 'no error type to read, so this is classified as a transient service error rather '
                . 'than released as a scan result.',
                $startedAt,
                $schemaWasSent,
                $response
            );
        }

        $decoded = $this->decodeOutput($response);

        if ($decoded === null) {
            return $this->failure(
                'invalid_json',
                $schemaWasSent
                    ? 'The model did not return parseable JSON despite structured output being requested.'
                    : 'The model did not return parseable JSON, and structured output was NOT sent: '
                    . "SecureChatAI drops json_schema for any model outside its OpenAI allowlist, and "
                    . "\"$modelAlias\" is outside it. This is a configuration problem, not a model "
                    . 'failure - see stage-4 §4.1 (SecureChatAI PR #2).',
                $startedAt,
                $schemaWasSent,
                $response
            );
        }

        return [
            'runStatus'         => 'ok',
            'output'            => $decoded,
            'error'             => null,
            'resolvedModel'     => $this->resolvedModel($response),
            'latencyMs'         => $latency,
            'promptTokens'      => $this->intOrNull($response['usage']['prompt_tokens'] ?? null),
            'completionTokens'  => $this->intOrNull($response['usage']['completion_tokens'] ?? null),
            'schemaWasSent'     => $schemaWasSent,
            // How the model was told the shape. False/false together means it was not told
            // at all, which is a configuration fault rather than a model failure.
            'schemaInPrompt'    => !$schemaWasSent,
        ];
    }

    /**
     * The pinned prompt plus the output schema, for a model that will never be sent one.
     *
     * Deliberately verbatim JSON Schema rather than a prose summary: a summary is a second
     * description of the contract that can drift from the pinned artifact, and the artifact is the
     * thing the research team validated. The wording calls out near-misses specifically, because that
     * is what actually happens - an invented key name or a bare string where an object is required
     * is rejected in full rather than partially accepted, and the model cannot know that unless told.
     */
    private function promptWithSchema(string $systemPrompt, array $outputSchema): string
    {
        return $systemPrompt . "\n\n" . implode("\n", [
            '## OUTPUT FORMAT (REQUIRED)',
            '',
            'Structured output is not available for this model on this deployment, so the required '
            . 'schema is given here instead.',
            '',
            'Reply with a SINGLE JSON object and nothing else: no prose before or after it, no '
            . 'markdown code fence, no explanation.',
            '',
            'It must validate against the JSON Schema below. Use the property names and enum values '
            . 'verbatim. A near-miss is rejected in full rather than partially accepted - an invented '
            . 'key name, or a bare string where an object with named fields is required, loses the '
            . 'entire scan.',
            '',
            json_encode(
                $outputSchema,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) ?: '{}',
        ]);
    }

    /**
     * Whether SecureChatAI will actually forward `json_schema` for this alias.
     *
     * Duplicating its allowlist is unpleasant and is the right trade: the alternative is discovering
     * at scan time that structured output was dropped, with nothing on the run row to say so. When
     * PR #2 lands and the allowlist becomes configuration, this becomes a call into the provider and
     * the constant goes away. A drifted copy fails safe - it reports `schemaWasSent: false` for a
     * model that did get the schema, which mislabels a run row but changes no decision.
     */
    private function schemaWouldBeSent(string $modelAlias): bool
    {
        return in_array($modelAlias, self::OPENAI_SCHEMA_MODELS, true);
    }

    /**
     * The interim failure signal, quarantined here so removing it is one edit.
     *
     * `model === null && usage === null` means SecureChatAI rewrote a provider failure as friendly
     * text. It is sound only while MICA never sets `agent_mode` - agent turns share that shape - so
     * the request built above deliberately does not, and this comment is the reason why.
     */
    private function looksLikeASanitizedFailure(array $response): bool
    {
        return ($response['model'] ?? null) === null && ($response['usage'] ?? null) === null;
    }

    /**
     * `structured_output` first, then `content` as JSON.
     *
     * The fallback is load-bearing rather than defensive: `normalizeResponse()` only populates
     * `structured_output` on the OpenAI-compatible path, so a Claude- or Gemini-family alias returns
     * its JSON as a plain `content` string and would otherwise be unreadable.
     */
    private function decodeOutput(array $response): ?array
    {
        if (isset($response['structured_output']) && is_array($response['structured_output'])) {
            return $response['structured_output'];
        }

        $content = $response['content'] ?? null;
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        // Models fenced in ```json blocks more often than not when the schema was dropped. Stripping
        // the fence is not "being lenient about the contract" - the contract is the *schema*, which
        // is still validated in full afterwards; this only gets us to something parseable.
        $trimmed = trim($content);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed) ?? $trimmed;
        }

        $decoded = json_decode(trim($trimmed), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function resolvedModel(array $response): ?string
    {
        // The exact deployment that answered, which the handoff requires on every record ("Record
        // the exact resolved deployment for every turn"). Null when the provider does not say.
        $model = $response['model'] ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    private function classifyThrowable(\Throwable $e): string
    {
        return $this->classifyErrorText(get_class($e) . ' ' . $e->getMessage());
    }

    /**
     * Map provider wording onto the pinned failure taxonomy.
     *
     * Unmatched text becomes `service_error`, which is the *transient* class - so an unrecognised
     * failure retries and then lands in manual review, rather than being taken for a refusal and
     * closed out on the first attempt.
     */
    private function classifyErrorText(string $text): string
    {
        $text = strtolower($text);

        foreach (
            [
            'timeout'        => ['timed out', 'timeout', 'deadline exceeded', 'curl error 28'],
            'content_filter' => [
                'content filter', 'content_filter', 'responsibleai', 'blockreason',
                'safety', 'jailbreak', 'prohibited',
            ],
            'refusal'        => ['refus', 'i cannot assist', 'cannot comply', 'declin'],
            ] as $status => $needles
        ) {
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $status;
                }
            }
        }

        return 'service_error';
    }

    /** @return array<string,mixed> */
    private function failure(
        string $runStatus,
        string $error,
        float $startedAt,
        bool $schemaWasSent,
        ?array $response = null
    ): array {
        return [
            'runStatus'        => $runStatus,
            // Never carries output on a failure, so no caller can read findings off a failed
            // attempt even by mistake.
            'output'           => null,
            'error'            => $error,
            'resolvedModel'    => $response === null ? null : $this->resolvedModel($response),
            'latencyMs'        => (int) round((microtime(true) - $startedAt) * 1000),
            'promptTokens'     => $this->intOrNull($response['usage']['prompt_tokens'] ?? null),
            'completionTokens' => $this->intOrNull($response['usage']['completion_tokens'] ?? null),
            'schemaWasSent'    => $schemaWasSent,
            'schemaInPrompt'   => !$schemaWasSent,
        ];
    }

    private function intOrNull($value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
