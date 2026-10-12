<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ModelSelection;

/**
 * The switch, the keys it selects, and what it does to the settings dialog.
 *
 * The dialog half matters because of how REDCap renders a super-users-only field: it leaves it out
 * of the dialog for everyone else, and a branching condition on a missing field is false - so
 * without applyToConfig() a designer would see neither model dropdown.
 */
#[CoversClass(ModelSelection::class)]
final class ModelSelectionTest extends TestCase
{
    /** @return array<string,array{0:mixed,1:string}> */
    public static function switchValues(): array
    {
        return [
            'unset'        => [null, 'standard'],
            'false'        => [false, 'standard'],
            'empty string' => ['', 'standard'],
            "'0'"          => ['0', 'standard'],
            'true'         => [true, 'high'],
            "'1'"          => ['1', 'high'],
            "'true'"       => ['true', 'high'],
            'anything else' => ['yes', 'standard'],
        ];
    }

    #[DataProvider('switchValues')]
    public function testTheSwitchSelectsThePolicy($value, string $expected): void
    {
        $this->assertSame($expected, ModelSelection::policy($value));
    }

    public function testEachRoleReadsItsOwnKeyPerPolicy(): void
    {
        $this->assertSame('llm-model', ModelSelection::key('counselor', 'standard'));
        $this->assertSame('llm-model-high', ModelSelection::key('counselor', 'high'));
        $this->assertSame('safetyscan-model-alias', ModelSelection::key('safetyscan', 'standard'));
        $this->assertSame('safetyscan-model-alias-high', ModelSelection::key('safetyscan', 'high'));
    }

    public function testAnUnknownRoleOrPolicyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModelSelection::key('counselor', 'secret');
    }

    public function testPlaceholdersAreRecognised(): void
    {
        $this->assertTrue(ModelSelection::isPlaceholder('placeholder-high-model-1'));
        $this->assertTrue(ModelSelection::isPlaceholder(' placeholder-x'));
        $this->assertFalse(ModelSelection::isPlaceholder('gpt-5-4'));
        $this->assertFalse(ModelSelection::isPlaceholder(null));
    }

    public function testConfigJsonCarriesTheSwitchAndBothHighThresholdDropdowns(): void
    {
        $config = json_decode((string) file_get_contents(__DIR__ . '/../../config.json'), true);
        $byKey = array_column($config['project-settings'], null, 'key');

        $switch = $byKey[ModelSelection::SWITCH_KEY];
        $this->assertSame('checkbox', $switch['type']);
        $this->assertTrue($switch['super-users-only']);
        $this->assertArrayNotHasKey('default', $switch, 'off unless a super user turns it on');

        foreach (ModelSelection::KEYS as $role => $keys) {
            $this->assertSame(
                ['field' => ModelSelection::SWITCH_KEY, 'value' => false],
                $byKey[$keys['standard']]['branchingLogic'],
                "$role standard dropdown shows while the switch is off"
            );
            $high = $byKey[$keys['high']];
            $this->assertSame('dropdown', $high['type']);
            $this->assertSame(['field' => ModelSelection::SWITCH_KEY, 'value' => true], $high['branchingLogic']);
            $this->assertArrayNotHasKey('default', $high, 'a blank high alias is refused, never defaulted');
            $this->assertSame(
                self::AI_HUB_HIGH,
                array_column($high['choices'], 'value'),
                "$role offers AI Hub's list"
            );
            foreach ($high['choices'] as $choice) {
                $this->assertStringContainsString('(high-threshold', $choice['name']);
            }
        }
    }

    /** AI Hub's high-threshold deployments (2026-10-12), each probed with enforced structured output. */
    private const AI_HUB_HIGH = [
        'gpt-5-4-high-threshold-policy', 'gpt-5-5-high-threshold-policy', 'gpt-5-2-high-threshold-policy',
        'gpt-5-4-mini-high-threshold-policy', 'gpt-5-4-nano-high-threshold-policy',
        'gpt-4-1-mini-high-threshold-policy',
    ];

    public function testAPolicyDeploymentBelongsToItsFamily(): void
    {
        $this->assertSame('gpt-5-4', ModelSelection::family('gpt-5-4-high-threshold-policy'));
        $this->assertSame('gpt-5-4-nano', ModelSelection::family('gpt-5-4-nano-high-threshold-policy'));
        $this->assertSame('gpt-4-1-mini', ModelSelection::family('gpt-4-1-mini-low-threshold-policy'));
        $this->assertSame('gpt-5-6-sol', ModelSelection::family('gpt-5-6-sol'));
    }

    public function testEveryHighThresholdChoiceIsAStructuredOutputFamilyForTheScan(): void
    {
        foreach (self::AI_HUB_HIGH as $alias) {
            $this->assertContains(
                ModelSelection::family($alias),
                \Stanford\MICA\SecureChatSafetyScanCaller::OPENAI_SCHEMA_MODELS,
                "$alias would lose structured output"
            );
        }
    }

    /** @return list<array<string,mixed>> */
    private function dialog(): array
    {
        $config = json_decode((string) file_get_contents(__DIR__ . '/../../config.json'), true);

        return $config['project-settings'];
    }

    private function setting(array $settings, string $key): ?array
    {
        foreach ($settings as $s) {
            if (($s['key'] ?? null) === $key) {
                return $s;
            }
        }

        return null;
    }

    public function testOtherRegisteredHighThresholdAliasesAreAddedAfterTheListedOnes(): void
    {
        $registered = ['gpt-5-4-high-threshold-policy', 'gpt-5-4', 'claude-x', 'gpt-4-1-mini-x-high-threshold-policy'];
        $out = ModelSelection::applyToConfig($this->dialog(), 'high', true, $registered, ['gpt-5-4', 'gpt-4-1-mini-x']);

        $counselor = array_column($this->setting($out, 'llm-model-high')['choices'], 'value');
        $this->assertSame(
            array_merge(self::AI_HUB_HIGH, ['gpt-5-4', 'claude-x', 'gpt-4-1-mini-x-high-threshold-policy']),
            $counselor
        );

        // The scan only gets aliases whose family has enforced structured output.
        $scan = array_column($this->setting($out, 'safetyscan-model-alias-high')['choices'], 'value');
        $this->assertSame(array_merge(self::AI_HUB_HIGH, ['gpt-5-4', 'gpt-4-1-mini-x-high-threshold-policy']), $scan);

        // The standard lists are left exactly as config.json has them.
        $this->assertSame($this->setting($this->dialog(), 'llm-model'), $this->setting($out, 'llm-model'));
    }

    public function testAnAliasAlreadyListedIsNotAddedTwice(): void
    {
        $out = ModelSelection::applyToConfig($this->dialog(), 'high', true, ['gpt-5-5-high-threshold-policy'], []);

        $values = array_column($this->setting($out, 'llm-model-high')['choices'], 'value');
        $this->assertSame(self::AI_HUB_HIGH, $values);
    }

    public function testALiveAliasStillGoesAheadOfALegacyPlaceholder(): void
    {
        $settings = [[
            'key' => 'llm-model-high',
            'choices' => [['value' => 'a', 'name' => 'A'], ['value' => 'placeholder-high-model-1', 'name' => 'P']],
        ]];
        $out = ModelSelection::applyToConfig($settings, 'high', true, ['b'], []);

        $this->assertSame(['a', 'b', 'placeholder-high-model-1'], array_column($out[0]['choices'], 'value'));
    }

    public function testASuperUserKeepsBothPairsAndTheLiveSwitch(): void
    {
        $out = ModelSelection::applyToConfig($this->dialog(), 'standard', true, [], []);

        foreach (['llm-model', 'llm-model-high', 'safetyscan-model-alias', 'safetyscan-model-alias-high'] as $key) {
            $this->assertArrayHasKey('branchingLogic', $this->setting($out, $key), $key);
        }
    }

    /** @return array<string,array{0:string,1:list<string>,2:list<string>}> */
    public static function nonSuperUser(): array
    {
        return [
            'switch off' => [
                'standard',
                ['llm-model', 'safetyscan-model-alias'],
                ['llm-model-high', 'safetyscan-model-alias-high'],
            ],
            'switch on'  => [
                'high',
                ['llm-model-high', 'safetyscan-model-alias-high'],
                ['llm-model', 'safetyscan-model-alias'],
            ],
        ];
    }

    #[DataProvider('nonSuperUser')]
    public function testANonSuperUserSeesOnlyTheActivePairWithNoBranching(
        string $policy,
        array $shown,
        array $gone
    ): void {
        $in = $this->dialog();
        $out = ModelSelection::applyToConfig($in, $policy, false, [], []);

        foreach ($shown as $key) {
            $this->assertNotNull($this->setting($out, $key), "$key is shown");
            $this->assertArrayNotHasKey('branchingLogic', $this->setting($out, $key), $key);
        }
        foreach ($gone as $key) {
            $this->assertNull($this->setting($out, $key), "$key is removed");
        }
        $this->assertCount(count($in) - 2, $out, 'nothing else is touched');
    }
}
