<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `capture-llm-payload.php`'s `$reasoningModels` is a knowing duplicate of the alias list that gates
 * SecureChatAI's strict-body branch. Same reasoning as {@see SchemaModelMirrorTest}, different list,
 * and a worse failure mode if it drifts.
 *
 * That branch does not filter parameters, it REPLACES them: a reasoning alias gets a four-key body
 * and `temperature` / `top_p` / the penalties / `stop` never leave the box (SecureChatAI.php:406-421).
 * The script's `shape` command is the one people reach for - it needs no network and prints no
 * prompt text, so it is what gets pasted into a ticket to answer "what are we sending?". If
 * SecureChatAI gains a reasoning alias and this mirror does not, `shape` confidently prints the
 * wrong envelope: every stripped parameter shown as if it were on the wire. Nothing errors.
 *
 * There are TWO reasoning lists in SecureChatAI and they are not the same list - line 394 gates
 * whether reasoning params survive and omits `o3`/`o4-mini`. This mirrors the strict-body one, so
 * the anchor below is deliberately tied to `$strict` rather than to `in_array($model, [...])` alone.
 */
final class ReasoningModelMirrorTest extends TestCase
{
    /** Where SecureChatAI lives when both modules are checked out side by side. */
    private const PROVIDER_SOURCE = __DIR__ . '/../../../secure_chat_ai_v9.9.9/SecureChatAI.php';

    private const SCRIPT_SOURCE = __DIR__ . '/../../docs/phase-3-handoff/scripts/capture-llm-payload.php';

    /** @return list<string> */
    private function mirror(): array
    {
        $this->assertFileExists(self::SCRIPT_SOURCE);

        preg_match(
            '/\$reasoningModels\s*=\s*\[(.*?)\];/s',
            (string) file_get_contents(self::SCRIPT_SOURCE),
            $match
        );

        $this->assertNotEmpty(
            $match[1] ?? '',
            'no $reasoningModels literal in capture-llm-payload.php - it was renamed, and `shape` is '
            . 'now deriving the envelope from something this test cannot see'
        );

        preg_match_all("/'([^']+)'/", $match[1], $aliases);

        return $aliases[1];
    }

    /**
     * PID 257's configured counselor. The load-bearing case: this alias is why the live project sends
     * a four-key body, and why its `gpt-temperature` project setting is inert.
     */
    public function testTheAliasConfiguredOnPid257IsTreatedAsReasoning(): void
    {
        $this->assertContains('gpt-5-6-sol', $this->mirror());
    }

    /**
     * A schema-capable-but-not-reasoning alias must not leak in: the two lists overlap without being
     * equal, and treating `gpt-4-1` as reasoning would print a body missing everything it does send.
     */
    public function testANonReasoningAliasIsAbsent(): void
    {
        $mirror = $this->mirror();

        $this->assertNotContains('gpt-4-1', $mirror);
        $this->assertNotContains('gpt-4o', $mirror);
        $this->assertNotContains('claude-opus-4-7', $mirror);
    }

    /**
     * The actual invariant: the mirror equals the literal that guards `$strict`. Parses the provider
     * source rather than loading it - SecureChatAI.php needs the REDCap framework and this suite is
     * framework-free (tests/bootstrap.php).
     */
    public function testTheMirrorMatchesTheListThatGuardsTheStrictBody(): void
    {
        if (!is_file(self::PROVIDER_SOURCE)) {
            $this->markTestSkipped('SecureChatAI is not checked out alongside MICA; nothing to compare.');
        }

        // `[^\]]*` rather than a lazy `.*?`: there are three `in_array($model, [...])` calls in a row
        // and a lazy match starting at the first one will happily run past the other two to reach
        // `$strict`, capturing all 29 aliases as if they were one list.
        preg_match(
            '/in_array\(\$model,\s*\[([^\]]*)\]\)\)\s*\{\s*\$strict\s*=/s',
            (string) file_get_contents(self::PROVIDER_SOURCE),
            $match
        );

        $this->assertNotEmpty(
            $match[1] ?? '',
            'no reasoning-alias list found guarding $strict in SecureChatAI.php - the branch was '
            . 'restructured, and the mirror is now anchored to nothing'
        );

        preg_match_all("/'([^']+)'/", $match[1], $aliases);

        $this->assertSame(
            $aliases[1],
            $this->mirror(),
            'the reasoning-alias list in SecureChatAI.php has drifted from $reasoningModels in '
            . 'capture-llm-payload.php, so `shape` now prints the wrong request envelope'
        );
    }
}
