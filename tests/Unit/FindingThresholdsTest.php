<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\FindingThresholds as T;

/**
 * A filter on a safety queue, so most of these tests are about what it CANNOT hide.
 *
 * The trade this class makes: a queue full of findings nobody acts on teaches reviewers to skim, and
 * then the one that mattered gets skimmed too. So a study may narrow it - but not past the point
 * where narrowing becomes a way of not being told.
 */
#[CoversClass(T::class)]
final class FindingThresholdsTest extends TestCase
{
    private function finding(string $concern, string $urgency, int $index = 1): array
    {
        return ['finding_index' => $index, 'concern_type' => $concern, 'urgency' => $urgency];
    }

    // ------------------------------------------------------------------ the default

    public function testAnUnconfiguredProjectFiltersNothing(): void
    {
        // A study that has not thought about this must see everything the model reported. The failure
        // mode of a wrong default here is a finding nobody ever sees.
        $t = new T();

        $this->assertTrue($t->passesEverything());

        foreach (T::URGENCIES as $urgency) {
            $this->assertTrue($t->admits($this->finding('protocol_or_quality', $urgency)));
        }
    }

    public function testBlankAndUnknownFloorsMeanNoFloor(): void
    {
        foreach (['', '   ', 'nonsense', 'CRITICAL'] as $bad) {
            $this->assertSame('', (new T($bad))->minimumUrgency(), "\"$bad\" became a floor");
        }
    }

    // ------------------------------------------------------------------ what it cannot hide

    #[DataProvider('lifeSafetyConcerns')]
    public function testALifeSafetyConcernCannotBeExcluded(string $concern): void
    {
        // A missed signal in any of these is a life-safety event rather than queue noise.
        $t = new T('critical', [$concern]);

        $this->assertSame([], $t->excludedConcernTypes(), 'it was accepted into the exclusion list');
        $this->assertTrue($t->admits($this->finding($concern, 'quality')));
    }

    /** @return array<string,array{string}> */
    public static function lifeSafetyConcerns(): array
    {
        return array_combine(
            T::NEVER_EXCLUDABLE,
            array_map(static fn(string $c): array => [$c], T::NEVER_EXCLUDABLE)
        );
    }

    public function testCriticalAlwaysReachesTheQueue(): void
    {
        // An urgency floor above critical is not a preference, it is a way of not being told.
        $t = new T('critical', ['protocol_or_quality', 'privacy', 'medical_inaccuracy']);

        foreach (['protocol_or_quality', 'privacy', 'medical_inaccuracy', 'other'] as $concern) {
            $this->assertTrue(
                $t->admits($this->finding($concern, 'critical')),
                "critical $concern was filtered"
            );
        }
    }

    public function testAScanFailurePlaceholderIsNeverFiltered(): void
    {
        // "Not screened" is the last thing a filter should be able to hide, and it is not a model
        // finding at all - it is the marker saying the scan never completed.
        $t = new T('critical', [T::SCAN_FAILURE, 'other']);

        $this->assertTrue($t->admits($this->finding(T::SCAN_FAILURE, 'quality')));
        $this->assertTrue($t->admits($this->finding(T::SCAN_FAILURE, '')));
    }

    public function testAnUnrecognisedUrgencyIsShownRatherThanHidden(): void
    {
        // An urgency the taxonomy does not know is a bug upstream, and the safe response to a bug is
        // to show a human.
        $t = new T('high');

        $this->assertTrue($t->admits($this->finding('protocol_or_quality', 'severe')));
        $this->assertTrue($t->admits($this->finding('protocol_or_quality', '')));
    }

    public function testButAnUnrecognisedUrgencyDoesNotEscapeAnExclusion(): void
    {
        // Excluding a concern type was a decision about the CATEGORY, not about the severity, so a
        // broken urgency must not smuggle it back in.
        $t = new T('', ['protocol_or_quality']);

        $this->assertFalse($t->admits($this->finding('protocol_or_quality', 'severe')));
    }

    // ------------------------------------------------------------------ the floor

    #[DataProvider('floorCases')]
    public function testTheFloorAdmitsAtOrAboveItself(string $floor, string $urgency, bool $expected): void
    {
        $this->assertSame($expected, (new T($floor))->admits($this->finding('privacy', $urgency)));
    }

    /** @return array<string,array{string,string,bool}> */
    public static function floorCases(): array
    {
        $cases = [];

        foreach (['quality', 'moderate', 'high'] as $floor) {
            foreach (T::URGENCIES as $urgency) {
                $at = array_search($urgency, T::URGENCIES, true) >= array_search($floor, T::URGENCIES, true);
                $cases["floor $floor admits $urgency"] = [$floor, $urgency, $at];
            }
        }

        return $cases;
    }

    public function testTheReasonNamesTheRuleThatHeldItBack(): void
    {
        // A filter whose effects cannot be read back is indistinguishable from a scanner that found
        // nothing, which is the one confusion this whole pipeline is built to prevent.
        $floor = (new T('high'))->rejectionReason($this->finding('privacy', 'moderate'));
        $this->assertStringContainsString('"moderate" is below', $floor);
        $this->assertStringContainsString('"high" floor', $floor);

        $excluded = (new T('', ['privacy']))->rejectionReason($this->finding('privacy', 'moderate'));
        $this->assertStringContainsString('"privacy" is excluded', $excluded);
    }

    // ------------------------------------------------------------------ apply()

    public function testApplySplitsAndExplainsWithoutCopyingTheParticipantsWords(): void
    {
        $findings = [
            ['finding_index' => 1, 'concern_type' => 'self_harm', 'urgency' => 'quality',
                'finding_summary' => 'she described a plan', 'evidence' => [['exact_quote' => 'pills']]],
            ['finding_index' => 2, 'concern_type' => 'privacy', 'urgency' => 'moderate',
                'finding_summary' => 'named her employer', 'evidence' => [['exact_quote' => 'Acme Corp']]],
            ['finding_index' => 3, 'concern_type' => 'protocol_or_quality', 'urgency' => 'critical'],
        ];

        $out = (new T('high', ['privacy']))->apply($findings);

        // self_harm survives the floor (never excludable), critical survives the exclusion.
        $this->assertSame([1, 3], array_column($out['admitted'], 'finding_index'));
        $this->assertSame([2], array_column($out['filtered'], 'finding_index'));

        // The filtered record says what and why, and nothing a participant said.
        $serialised = json_encode($out['filtered']);
        $this->assertStringContainsString('privacy', $serialised);
        $this->assertStringNotContainsString('Acme Corp', $serialised);
        $this->assertStringNotContainsString('named her employer', $serialised);
    }

    public function testApplyOnAnUnconfiguredProjectFiltersNothing(): void
    {
        $findings = [
            $this->finding('protocol_or_quality', 'quality', 1),
            $this->finding('privacy', 'quality', 2),
        ];

        $out = (new T())->apply($findings);

        $this->assertCount(2, $out['admitted']);
        $this->assertSame([], $out['filtered']);
    }

    public function testApplyHandlesNoFindingsAtAll(): void
    {
        $out = (new T('high'))->apply([]);

        $this->assertSame([], $out['admitted']);
        $this->assertSame([], $out['filtered']);
    }

    // ------------------------------------------------------------------ housekeeping

    public function testTheExclusionListIsCleanedNotTrusted(): void
    {
        $t = new T('', ['privacy', 'privacy', '', 'self_harm', 'other']);

        // Deduplicated, blanks dropped, and the life-safety one removed on the way in - so an
        // excluded-by-mistake life-safety type cannot even be represented in this object.
        $this->assertSame(['privacy', 'other'], $t->excludedConcernTypes());
    }

    public function testToArrayReportsWhatIsInForce(): void
    {
        $this->assertSame(
            ['minimum_urgency' => 'high', 'excluded_concern_types' => ['privacy'], 'passes_everything' => false],
            (new T('high', ['privacy']))->toArray()
        );

        $this->assertTrue((new T())->toArray()['passes_everything']);
    }
}
