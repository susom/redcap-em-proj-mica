<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\EdSessionLink;
use Stanford\MICA\SessionHostMap;

/**
 * The load-bearing assertion in this file is `testStandardCareGetsNoSession...`.
 *
 * Everything else here is arithmetic over the project structure. That one is a protocol rule: a
 * control participant must never be handed a link to the intervention, which is the same rule that
 * governs arm materialization (`MICA.php:750-752`). It is asserted from two directions - the
 * outcome for arm 1, and that no arm without a designated host ever yields an event - so removing
 * either branch of the implementation fails a test.
 *
 * The structure fixtures mirror PID 257 as built (arms 1/2/3, Day 1 + follow-ups, `mica_ed_session`
 * designated to arms 2 and 3 only) rather than a minimal invention, because the bug this class
 * exists to prevent is an event-number confusion and a two-event fixture cannot express one.
 */
#[CoversClass(EdSessionLink::class)]
final class EdSessionLinkTest extends TestCase
{
    private const HOST = 'mica_ed_session';

    /** PID 257's real event ids and arm layout. @return array<int,array<string,mixed>> */
    private static function eventInfo(): array
    {
        return [
            1004 => ['arm_num' => 1, 'day_offset' => 1],   // Day 1 (ED), Standard Care
            1005 => ['arm_num' => 1, 'day_offset' => 2],
            1008 => ['arm_num' => 2, 'day_offset' => 1],   // Day 1 (ED), MICA
            1009 => ['arm_num' => 2, 'day_offset' => 2],   // Month 3, booster
            1012 => ['arm_num' => 3, 'day_offset' => 1],   // Day 1 (ED), MICA + Weekly SMS
            1014 => ['arm_num' => 3, 'day_offset' => 3],
        ];
    }

    /** @return array<int,list<string>> */
    private static function eventsForms(): array
    {
        return [
            1004 => ['admin', 'baseline1', 'consent', 'check_code'],   // no session host in arm 1
            1005 => ['check_code'],
            1008 => ['admin', 'baseline1', self::HOST, 'check_code'],
            1009 => ['check_code', 'mica_booster_session'],
            1012 => ['admin', 'baseline1', self::HOST, 'check_code'],
            1014 => ['check_code', 'mica_booster_session'],
        ];
    }

    private static function resolve($group): array
    {
        return EdSessionLink::resolve($group, self::HOST, self::eventsForms(), self::eventInfo());
    }

    // ------------------------------------------------------------------- the protocol rule

    public function testStandardCareGetsNoSessionEventEverUnderAnyRepresentation(): void
    {
        // Arm 1 exists and is a perfectly valid allocation - it simply has no session instrument.
        // A link here would be a control participant receiving the intervention.
        foreach (['1', 1, ' 1 '] as $group) {
            $out = self::resolve($group);
            $this->assertSame(EdSessionLink::NO_SESSION_IN_ARM, $out['status'], 'arm 1 must not resolve');
            $this->assertSame(1, $out['arm'], 'the arm is still reported - this is not an error');
            $this->assertNull($out['eventId'], 'no event means no link can be minted');
        }
    }

    /**
     * The invariant that keeps `REDCap::getSurveyLink()` safe to call.
     *
     * Measured on PID 257: `getSurveyLink()` does **not** check that an instrument is designated to
     * the event it is given. `REDCap.php:1740-1745` checks only that the instrument is a survey
     * *project-wide*, then that the record exists in the arm **of the given event**. So calling it
     * with an arm-1 event mints a working `mica_ed_session` link at an event where the instrument
     * does not exist - which is precisely how a Standard Care participant would end up holding a
     * link to the intervention. (REDCap's own `[survey-url:mica_ed_session]` smart variable does
     * exactly this: piped at event 1004 it created a new participant row at 1004.)
     *
     * The only thing standing between that and the control arm is this class never handing out an
     * event from outside the assigned arm. Asserted for every arm, not just the interesting one.
     */
    public function testAResolvedEventIsAlwaysInsideTheAssignedArm(): void
    {
        foreach ([1, 2, 3, 9] as $arm) {
            $out = self::resolve((string) $arm);
            if ($out['eventId'] === null) {
                continue;
            }
            $this->assertSame(
                $arm,
                (int) self::eventInfo()[$out['eventId']]['arm_num'],
                "arm $arm resolved to event {$out['eventId']}, which belongs to another arm"
            );
            $this->assertContains(
                self::HOST,
                self::eventsForms()[$out['eventId']],
                "arm $arm resolved to event {$out['eventId']}, which does not host " . self::HOST
            );
        }
    }

    public function testNoOutcomeCarriesAnEventUnlessItResolved(): void
    {
        // The other direction: whatever the input, an eventId only ever accompanies RESOLVED. This is
        // what stops a future refactor turning a refusal into a link.
        foreach (['', null, 'abc', '0', '-2', '2.5', '9', '1', [], '2'] as $group) {
            $out = self::resolve($group);
            if ($out['status'] !== EdSessionLink::RESOLVED) {
                $this->assertNull($out['eventId'], 'status ' . $out['status'] . ' must carry no event');
            } else {
                $this->assertNotNull($out['eventId']);
            }
        }
    }

    // ------------------------------------------------------------------- the happy paths

    public function testArmTwoResolvesToItsOwnDayOneEvent(): void
    {
        $out = self::resolve('2');
        $this->assertSame(EdSessionLink::RESOLVED, $out['status']);
        $this->assertSame(2, $out['arm']);
        $this->assertSame(1008, $out['eventId']);
    }

    public function testArmThreeResolvesToADifferentEventThanArmTwo(): void
    {
        // The whole point of resolving per record. A hardcoded 1008 would pass every other test.
        $this->assertSame(1012, self::resolve('3')['eventId']);
        $this->assertNotSame(self::resolve('2')['eventId'], self::resolve('3')['eventId']);
    }

    public function testTheEarliestHostingEventInTheArmWins(): void
    {
        // A study that designates the host to a later event as well must still get Day 1, and the
        // answer must not depend on array ordering - so the fixture lists the later event first.
        $eventInfo = [
            1099 => ['arm_num' => 2, 'day_offset' => 90],
            1008 => ['arm_num' => 2, 'day_offset' => 1],
        ];
        $eventsForms = [1099 => [self::HOST], 1008 => [self::HOST]];

        $out = EdSessionLink::resolve('2', self::HOST, $eventsForms, $eventInfo);
        $this->assertSame(1008, $out['eventId']);
    }

    // ------------------------------------------------------------------- refusals

    #[DataProvider('nonAllocations')]
    public function testValuesThatAreNotAnAllocation(string $label, $group, string $expected): void
    {
        $this->assertSame($expected, self::resolve($group)['status'], $label);
    }

    public static function nonAllocations(): array
    {
        return [
            'empty string'      => ['empty string', '', EdSessionLink::NOT_RANDOMIZED],
            'null'              => ['null', null, EdSessionLink::NOT_RANDOMIZED],
            'whitespace only'   => ['whitespace', "  \t ", EdSessionLink::NOT_RANDOMIZED],
            'array'             => ['array', [], EdSessionLink::NOT_RANDOMIZED],
            // Not NOT_RANDOMIZED: something was recorded, it just is not an arm. The distinction
            // matters because one is "wait" and the other is "someone needs to look at this".
            'non-numeric'       => ['non-numeric', 'MICA', EdSessionLink::BAD_GROUP],
            'zero'              => ['zero', '0', EdSessionLink::BAD_GROUP],
            'negative'          => ['negative', '-2', EdSessionLink::BAD_GROUP],
            // "2.5" must not become arm 2, and "2abc" must not become arm 2 either.
            'decimal'           => ['decimal', '2.5', EdSessionLink::BAD_GROUP],
            'numeric prefix'    => ['numeric prefix', '2abc', EdSessionLink::BAD_GROUP],
            'no such arm'       => ['no such arm', '9', EdSessionLink::BAD_GROUP],
        ];
    }

    public function testAnArmWhoseEventsExistButHostNothingIsNotABadGroup(): void
    {
        // Distinguishing these two is what lets the caller stay quiet about Standard Care while
        // still logging a genuine misconfiguration.
        $this->assertSame(EdSessionLink::BAD_GROUP, self::resolve('9')['status'], 'arm 9 does not exist');
        $this->assertSame(EdSessionLink::NO_SESSION_IN_ARM, self::resolve('1')['status'], 'arm 1 does');
    }

    public function testAnUnknownHostInstrumentResolvesToNothingRatherThanGuessing(): void
    {
        $out = EdSessionLink::resolve('2', 'mica_renamed_session', self::eventsForms(), self::eventInfo());
        $this->assertSame(EdSessionLink::NO_SESSION_IN_ARM, $out['status']);
        $this->assertNull($out['eventId']);
    }

    // ------------------------------------------------------------------- host lookup

    public function testHostInstrumentComesFromTheProjectsOwnMap(): void
    {
        // Default map: mica_ed_session is the baseline session, mica_booster_session the booster.
        $this->assertSame('mica_ed_session', EdSessionLink::hostInstrument(new SessionHostMap()));
    }

    public function testHostInstrumentFollowsAConfiguredRenameRatherThanTheLiteral(): void
    {
        // The reason hostInstrument() exists. A study that renames the host has a correct host map;
        // a hardcoded 'mica_ed_session' would silently mint nothing.
        $map = SessionHostMap::fromSetting(
            "mica_day1_chat:baseline:emergency_department\nmica_booster_session:booster:remote_followup"
        );
        $this->assertSame('mica_day1_chat', EdSessionLink::hostInstrument($map));
    }

    public function testABoosterOnlyMapHasNoBaselineHost(): void
    {
        $map = SessionHostMap::fromSetting('mica_booster_session:booster:remote_followup');
        $this->assertNull(EdSessionLink::hostInstrument($map));
    }
}
