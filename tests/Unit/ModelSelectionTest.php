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
            foreach ($high['choices'] as $choice) {
                $this->assertTrue(
                    ModelSelection::isPlaceholder($choice['value']),
                    'only placeholders until AI Hub publishes the list'
                );
                $this->assertStringContainsString('PLACEHOLDER', $choice['name']);
            }
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

    public function testLiveHighThresholdAliasesAreOfferedAheadOfThePlaceholders(): void
    {
        $out = ModelSelection::applyToConfig($this->dialog(), 'high', true, ['gpt-5-4', 'claude-x'], ['gpt-5-4']);

        $counselor = array_column($this->setting($out, 'llm-model-high')['choices'], 'value');
        $this->assertSame(['gpt-5-4', 'claude-x', 'placeholder-high-model-1', 'placeholder-high-model-2'], $counselor);

        // The scan only gets aliases it can be screened with (enforced structured output).
        $scan = array_column($this->setting($out, 'safetyscan-model-alias-high')['choices'], 'value');
        $this->assertSame(['gpt-5-4', 'placeholder-high-model-1', 'placeholder-high-model-2'], $scan);

        // The standard lists are left exactly as config.json has them.
        $this->assertSame($this->setting($this->dialog(), 'llm-model'), $this->setting($out, 'llm-model'));
    }

    public function testAnAliasAlreadyListedIsNotAddedTwice(): void
    {
        $out = ModelSelection::applyToConfig($this->dialog(), 'high', true, ['placeholder-high-model-1'], []);

        $values = array_column($this->setting($out, 'llm-model-high')['choices'], 'value');
        $this->assertSame(['placeholder-high-model-1', 'placeholder-high-model-2'], $values);
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
