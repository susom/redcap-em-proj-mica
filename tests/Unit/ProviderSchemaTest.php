<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\SchemaValidator;
use Stanford\MICA\SecureChatSafetyScanCaller;

/**
 * The copy of the pinned output schema the provider is sent (docs 14, D23).
 *
 * Azure's strict structured output answered every SafetyScan with HTTP 400 because the pinned schema
 * uses `uniqueItems`, so no session was ever screened: each one reached its reviewers as "could not
 * be screened" about seven minutes after it ended. The fix withholds that keyword from the request
 * and nowhere else. These tests pin all three halves of that claim - the provider's copy has no
 * `uniqueItems`, it differs from the artifact in nothing else, and uniqueness is still enforced on
 * the answer, because the artifact the answer is validated against still has it.
 */
#[CoversClass(SecureChatSafetyScanCaller::class)]
final class ProviderSchemaTest extends TestCase
{
    private const HANDOFF = __DIR__ . '/../../handoff';

    /** The two places the pinned schema uses `uniqueItems`, as reported by the provider's 400. */
    private const UNIQUE_PATHS = [
        ['properties', 'findings', 'items', 'properties', 'recommended_actions'],
        ['properties', 'findings', 'items', 'properties', 'recommended_notification_targets'],
    ];

    /** A fresh registry each time, so every read re-verifies the file against its manifest hash. */
    private function pinned(): array
    {
        return (new ArtifactRegistry(self::HANDOFF))->getJson('safetyscan_output_schema');
    }

    /** @return list<string|int> every key at any depth, so "absent" means absent everywhere */
    private static function keys(array $node): array
    {
        $keys = [];
        foreach ($node as $key => $value) {
            $keys[] = $key;
            if (is_array($value)) {
                $keys = array_merge($keys, self::keys($value));
            }
        }

        return $keys;
    }

    /** @param list<string> $path */
    private static function at(array $node, array $path): array
    {
        foreach ($path as $step) {
            $node = $node[$step];
        }

        return $node;
    }

    /** @param list<string> $path */
    private static function unsetAt(array $node, array $path, string $key): array
    {
        if ($path === []) {
            unset($node[$key]);

            return $node;
        }

        $step = array_shift($path);
        $node[$step] = self::unsetAt($node[$step], $path, $key);

        return $node;
    }

    public function testTheProviderIsNotSentUniqueItems(): void
    {
        $provider = SecureChatSafetyScanCaller::providerSchema($this->pinned());

        $this->assertNotContains('uniqueItems', self::keys($provider['schema']));
        $this->assertSame(['uniqueItems'], $provider['withheld']);
    }

    public function testNothingElseAboutTheSchemaChanges(): void
    {
        $expected = $this->pinned();

        foreach (self::UNIQUE_PATHS as $path) {
            // Loud if the artifact is ever re-issued without the keyword: this test would otherwise
            // pass while describing a schema that no longer exists.
            $this->assertTrue(self::at($expected, $path)['uniqueItems'] ?? null, implode('.', $path));
            $expected = self::unsetAt($expected, $path, 'uniqueItems');
        }

        $this->assertSame($expected, SecureChatSafetyScanCaller::providerSchema($this->pinned())['schema']);
    }

    public function testThePinnedArtifactIsLeftAsItWas(): void
    {
        SecureChatSafetyScanCaller::providerSchema($this->pinned());

        // A new registry re-reads the file and re-checks it against the manifest, so this is the
        // artifact on disk, not a copy the call could have touched.
        $again = $this->pinned();
        foreach (self::UNIQUE_PATHS as $path) {
            $this->assertTrue(self::at($again, $path)['uniqueItems']);
        }
    }

    public function testADataPropertyNamedLikeTheKeywordIsKept(): void
    {
        $schema = [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['uniqueItems'],
            'properties'           => ['uniqueItems' => ['type' => 'boolean']],
        ];

        $this->assertSame(
            ['schema' => $schema, 'withheld' => []],
            SecureChatSafetyScanCaller::providerSchema($schema)
        );
    }

    public function testADuplicatedActionIsStillRefusedWhenTheAnswerIsChecked(): void
    {
        $answer = [
            'scan_result'      => 'findings_present',
            'overall_urgency'  => 'critical',
            'findings'         => [[
                'finding_index'                    => 1,
                'source_role'                      => 'participant',
                'concern_type'                     => 'self_harm',
                'urgency'                          => 'critical',
                'finding_summary'                  => 'plan to overdose tonight',
                'evidence'                         => [[
                    'message_id'   => 'L1',
                    'speaker_role' => 'participant',
                    'exact_quote'  => 'take all of them tonight',
                ]],
                'recommended_actions'              => ['ra_review', 'ra_review'],
                'recommended_notification_targets' => ['research_assistant'],
                'confidence'                       => 0.9,
            ]],
            'review_summary'   => 'summary',
            'model_confidence' => 0.9,
        ];

        $result = (new SchemaValidator(new ArtifactRegistry(self::HANDOFF)))
            ->validate($answer, 'safetyscan_output_schema');

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('[uniqueItems]', implode("\n", $result->errors()));
    }
}
