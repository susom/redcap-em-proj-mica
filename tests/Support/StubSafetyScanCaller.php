<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\SafetyScanCallerInterface;

/** A scripted SafetyScan caller: hand it the report you want, inspect what it was asked. */
final class StubSafetyScanCaller implements SafetyScanCallerInterface
{
    /** @var list<array<string,mixed>> what each call received */
    public array $calls = [];

    /** @var list<array<string,mixed>> queued reports; the last one repeats once exhausted */
    private array $reports;

    public function __construct(array ...$reports)
    {
        $this->reports = $reports === [] ? [self::ok(['scan_result' => 'no_supported_concern'])] : $reports;
    }

    public function scan(
        string $modelAlias,
        string $systemPrompt,
        string $transcriptJson,
        array $outputSchema
    ): array {
        $this->calls[] = [
            'modelAlias'   => $modelAlias,
            'systemPrompt' => $systemPrompt,
            'transcript'   => $transcriptJson,
            'schema'       => $outputSchema,
        ];

        return count($this->reports) > 1 ? array_shift($this->reports) : $this->reports[0];
    }

    /** @param array<string,mixed> $output */
    public static function ok(array $output, bool $schemaWasSent = true): array
    {
        return [
            'runStatus'        => 'ok',
            'output'           => $output,
            'error'            => null,
            'resolvedModel'    => 'gemini-2.5-flash-002',
            'latencyMs'        => 1234,
            'promptTokens'     => 900,
            'completionTokens' => 120,
            'schemaWasSent'    => $schemaWasSent,
        ];
    }

    public static function failed(string $runStatus, string $error = 'boom', bool $schemaWasSent = true): array
    {
        return [
            'runStatus'        => $runStatus,
            'output'           => null,
            'error'            => $error,
            'resolvedModel'    => null,
            'latencyMs'        => 42,
            'promptTokens'     => null,
            'completionTokens' => null,
            'schemaWasSent'    => $schemaWasSent,
        ];
    }
}
