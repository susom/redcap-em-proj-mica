<?php

namespace Stanford\MICA;

/**
 * One SafetyScan model call, classified.
 *
 * The seam exists because the interesting half of Stage 4 is what happens to each *kind* of
 * failure, and every one of those branches is unreachable in a test that has to talk to a provider.
 * `ScanRunner` therefore never sees SecureChatAI; it sees this.
 *
 * Everything the caller returns is a statement about one attempt. It never decides what the attempt
 * *means* for the job - that is the state machine's job, and keeping the two apart is what stops a
 * provider quirk turning into a queue policy.
 */
interface SafetyScanCallerInterface
{
    /**
     * @param array<string,mixed> $outputSchema the pinned model-output schema, for structured output
     * @return array{
     *   runStatus: string,
     *   output: ?array,
     *   error: ?string,
     *   resolvedModel: ?string,
     *   latencyMs: int,
     *   promptTokens: ?int,
     *   completionTokens: ?int,
     *   schemaWasSent: bool,
     *   providerSchemaWithheld?: list<string>
     * }
     *   `runStatus` is one of EntityTypes::runStatusChoices(). `output` is the decoded model output
     *   when and only when runStatus is `ok`; every other status must carry null, so a caller
     *   cannot accidentally read findings off a failed attempt.
     *
     *   `schemaWasSent` reports whether structured output was actually requested of the provider.
     *   It exists because SecureChatAI silently drops `json_schema` for any model outside its
     *   OpenAI allowlist - so a scan can fail as `invalid_json` for a configuration reason that
     *   looks like a model fault, and the difference has to be visible on the run row.
     *
     *   `providerSchemaWithheld` names the JSON Schema keywords left out of the copy of the schema
     *   the provider was sent, because it refuses them (SecureChatSafetyScanCaller::
     *   PROVIDER_WITHHELD_KEYWORDS). Absent or empty means the provider got the pinned schema as is.
     */
    public function scan(
        string $modelAlias,
        string $systemPrompt,
        string $transcriptJson,
        array $outputSchema
    ): array;
}
