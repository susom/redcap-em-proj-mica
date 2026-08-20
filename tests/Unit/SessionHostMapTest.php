<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\SessionHostMap;
use Stanford\MICA\TranscriptException;

#[CoversClass(SessionHostMap::class)]
final class SessionHostMapTest extends TestCase
{
    public function testTheDefaultsMatchTheR01Structure(): void
    {
        $map = new SessionHostMap();

        $this->assertSame(
            ['session_type' => 'baseline', 'setting' => 'emergency_department'],
            $map->resolve('mica_ed_session')
        );
        $this->assertSame(
            ['session_type' => 'booster', 'setting' => 'remote_followup'],
            $map->resolve('mica_booster_session')
        );
    }

    public function testEveryValueItCanProduceIsInThePinnedSchemaEnums(): void
    {
        // The check that keeps this class honest: the constants here are the *schema's* vocabulary,
        // not the data model's, and the schema is the hash-pinned artifact.
        $schema = (new ArtifactRegistry(__DIR__ . '/../../handoff'))->getJson('safetyscan_input_schema');

        $sessionTypes = $schema['properties']['session_type']['enum'];
        $settings = $schema['properties']['setting']['enum'];

        foreach ((new SessionHostMap())->instruments() as $instrument) {
            $resolved = (new SessionHostMap())->resolve($instrument);
            $this->assertContains($resolved['session_type'], $sessionTypes);
            $this->assertContains($resolved['setting'], $settings);
        }

        // And the documented mismatch: the schema says emergency_department, not `ed`.
        $this->assertContains(SessionHostMap::ED, $settings);
        $this->assertNotContains('ed', $settings, 'the data model writes `ed`; the schema does not');
    }

    public function testAnUnknownInstrumentIsRefusedRatherThanGuessed(): void
    {
        // A wrong `setting` changes how a finding reads clinically - an ED disclosure and a remote
        // follow-up disclosure are not the same situation - and a wrong session_type puts the
        // session in the wrong arm of the study's own analysis.
        try {
            (new SessionHostMap())->resolve('some_other_survey');
            $this->fail('guessing the session type is worse than refusing');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('some_other_survey', $e->getMessage());
            $this->assertStringContainsString('nothing was finalized', $e->getMessage());
            $this->assertStringContainsString('mica_ed_session', $e->getMessage(), 'list what IS known');
        }
    }

    public function testParsesTheSettingForm(): void
    {
        $map = SessionHostMap::fromSetting(
            "# a comment\n"
            . "mica_ed_session:baseline:emergency_department\n"
            . "\n"
            . "  custom_host : booster : other_approved  \n"
        );

        $this->assertSame(['mica_ed_session', 'custom_host'], $map->instruments());
        $this->assertSame(
            ['session_type' => 'booster', 'setting' => 'other_approved'],
            $map->resolve('custom_host')
        );
    }

    public function testABlankSettingMeansTheDefaults(): void
    {
        foreach ([null, '', '   ', "# only comments\n"] as $raw) {
            $this->assertSame(
                ['mica_ed_session', 'mica_booster_session'],
                SessionHostMap::fromSetting($raw)->instruments(),
                'an unconfigured project must still work on the R01 structure'
            );
        }
    }

    public function testAMalformedLineIsRefusedWithItsLineNumber(): void
    {
        try {
            SessionHostMap::fromSetting("mica_ed_session:baseline\nmica_booster_session:booster:remote_followup");
            $this->fail('a half-configured map must not silently drop a host');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('line 1', $e->getMessage());
        }
    }

    #[DataProvider('invalidVocabulary')]
    public function testValuesOutsideTheSchemaEnumsAreRefused(string $line, string $expect): void
    {
        $this->expectException(TranscriptException::class);
        $this->expectExceptionMessageMatches($expect);

        SessionHostMap::fromSetting($line);
    }

    public static function invalidVocabulary(): array
    {
        return [
            'bad session type' => ['mica_ed_session:day1:emergency_department', '/not a session type/'],
            // The exact trap this class exists to catch: the data model's spelling.
            'data model ed'    => ['mica_ed_session:baseline:ed', '/not a setting/'],
            'invented setting' => ['mica_ed_session:baseline:clinic', '/not a setting/'],
        ];
    }

    public function testMicaLocationOverridesTheDefaultSetting(): void
    {
        // A participant seen in the ED at Month 3 is a real case; the override exists for it.
        $this->assertSame(
            'emergency_department',
            (new SessionHostMap())->resolve('mica_booster_session', 'ed')['setting']
        );
        // ...but it must not change which session it is.
        $this->assertSame(
            'booster',
            (new SessionHostMap())->resolve('mica_booster_session', 'ed')['session_type']
        );
    }

    public function testAnEmptyOverrideLeavesTheDefaultAlone(): void
    {
        foreach ([null, '', '  '] as $override) {
            $this->assertSame(
                'remote_followup',
                (new SessionHostMap())->resolve('mica_booster_session', $override)['setting']
            );
        }
    }

    public function testRadioCodesAndLabelsBothTranslate(): void
    {
        // REDCap hands back the coded value for a radio, so both have to work.
        $this->assertSame('emergency_department', SessionHostMap::normaliseLocation('1'));
        $this->assertSame('emergency_department', SessionHostMap::normaliseLocation('ed'));
        $this->assertSame('remote_followup', SessionHostMap::normaliseLocation('2'));
        $this->assertSame('other_approved', SessionHostMap::normaliseLocation('3'));
    }

    public function testAnUnrecognisedLocationIsRefusedNotDefaulted(): void
    {
        try {
            SessionHostMap::normaliseLocation('99', 'mica_ed_session');
            $this->fail('defaulting an unknown clinical setting is a silent data error');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('mica_ed_session', $e->getMessage());
            $this->assertStringContainsString('rather than guessing', $e->getMessage());
        }
    }
}
