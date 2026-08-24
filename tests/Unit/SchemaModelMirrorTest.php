<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stanford\MICA\SecureChatSafetyScanCaller;

/**
 * `SecureChatSafetyScanCaller::OPENAI_SCHEMA_MODELS` is a knowing duplicate of the `$schemaModels`
 * literal in SecureChatAI.php. Both this suite and that module ship independently, so nothing but a
 * test stops the two from drifting - and drift is silent in the direction that matters.
 *
 * If SecureChatAI gains a schema-capable alias and the mirror is not updated, `schemaWouldBeSent()`
 * returns false for it. The scan then takes the prompt-injected-schema fallback even though real
 * structured output was available, and stamps `schemaWasSent: false` on the run row - so a finding
 * records the wrong provenance, in the one path that has to be auditable. Nothing errors.
 *
 * `schemaWouldBeSent()` is private and the class needs a live MICA instance, so the constant is read
 * by reflection rather than exercised through a constructed object. That is the whole surface: the
 * method is a one-line `in_array` against it.
 */
#[CoversClass(SecureChatSafetyScanCaller::class)]
final class SchemaModelMirrorTest extends TestCase
{
    /**
     * Where SecureChatAI lives when both modules are checked out side by side, as they are in a
     * REDCap `modules/` tree. Absent in a MICA-only checkout, which the sync test tolerates.
     */
    private const PROVIDER_SOURCE = __DIR__ . '/../../../secure_chat_ai_v9.9.9/SecureChatAI.php';

    /** @return list<string> */
    private function mirror(): array
    {
        $constants = (new ReflectionClass(SecureChatSafetyScanCaller::class))->getConstants();
        $this->assertArrayHasKey(
            'OPENAI_SCHEMA_MODELS',
            $constants,
            'the mirrored allowlist was renamed or removed; schemaWouldBeSent() has no source'
        );

        return $constants['OPENAI_SCHEMA_MODELS'];
    }

    /**
     * The GPT-5.6 aliases are schema-capable upstream (they mirror gpt-5-4, not the reasoning
     * models), so a 5.6 counselor must get real structured output rather than the fallback.
     *
     * These are the three names in the AI Hub service spec's `deployment-id` enum. There is no
     * bare `gpt-5-6` and no `-nano`: the variants are sol/luna/terra, and a name outside that enum
     * is a 404 path segment, not a family shorthand.
     */
    public function testTheGpt56AliasesAreTreatedAsSchemaCapable(): void
    {
        $mirror = $this->mirror();

        $this->assertContains('gpt-5-6-sol', $mirror);
        $this->assertContains('gpt-5-6-luna', $mirror);
        $this->assertContains('gpt-5-6-terra', $mirror);
    }

    /**
     * The names that do not exist. Guards the specific mistake made once already: inventing
     * `gpt-5-6` / `gpt-5-6-nano` by analogy with the 5.4 pair.
     */
    public function testTheInventedGpt56NamesAreAbsent(): void
    {
        $mirror = $this->mirror();

        $this->assertNotContains('gpt-5-6', $mirror);
        $this->assertNotContains('gpt-5-6-nano', $mirror);
    }

    /**
     * A reasoning alias must never appear here by accident: SecureChatAI sends those through a
     * strict param set, and the two lists answer different questions.
     */
    public function testAnUnregisteredAliasIsNotTreatedAsSchemaCapable(): void
    {
        $this->assertNotContains('gemini-2.5-flash', $this->mirror());
        $this->assertNotContains('claude-opus-4-7', $this->mirror());
    }

    /**
     * The actual invariant: the mirror equals the literal it mirrors. Parses the provider source
     * rather than loading it - SecureChatAI.php needs the REDCap framework, and this suite is
     * deliberately framework-free (see tests/bootstrap.php).
     */
    public function testTheMirrorMatchesSecureChatAisOwnList(): void
    {
        if (!is_file(self::PROVIDER_SOURCE)) {
            $this->markTestSkipped('SecureChatAI is not checked out alongside MICA; nothing to compare.');
        }

        $source = file_get_contents(self::PROVIDER_SOURCE);
        preg_match_all('/\$schemaModels\s*=\s*\[(.*?)\];/s', $source, $matches);

        $this->assertNotEmpty(
            $matches[1],
            'no $schemaModels literal found in SecureChatAI.php - it was renamed, and the mirror is '
            . 'now anchored to nothing'
        );

        $mirror = $this->mirror();
        foreach ($matches[1] as $i => $literal) {
            preg_match_all("/'([^']+)'/", $literal, $aliases);
            $this->assertSame(
                $mirror,
                $aliases[1],
                "\$schemaModels occurrence #{$i} in SecureChatAI.php has drifted from "
                . 'SecureChatSafetyScanCaller::OPENAI_SCHEMA_MODELS'
            );
        }
    }
}
