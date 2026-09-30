<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\InjectInstruments;

/**
 * The load-bearing case is the first one: PIDs 271 and 279 as configured on 2026-09-30. Every name
 * in that list is a display name, so none may be fetched - a fetched unknown name is how the whole
 * record reached the Day-1 prompt.
 */
#[CoversClass(InjectInstruments::class)]
final class InjectInstrumentsTest extends TestCase
{
    /** PID 279's instruments that the inject lists name, as `Project::$forms` keys them. */
    private const FORMS = ['ddq' => [], 'audit' => [], 'bscq' => [], 'sip2r' => [], 'sunday' => []];

    public function testDisplayNamesAreAllUnknownSoNothingIsFetched(): void
    {
        $this->assertSame(
            ['known' => [], 'unknown' => ['DDQ', 'AUDIT', 'BSCQ', 'SIP-2R']],
            InjectInstruments::resolve('DDQ, AUDIT, BSCQ, SIP-2R', self::FORMS)
        );
    }

    public function testFormNamesAreKnownInListOrder(): void
    {
        $this->assertSame(
            ['known' => ['ddq', 'audit', 'bscq', 'sip2r'], 'unknown' => []],
            InjectInstruments::resolve('ddq, audit, bscq, sip2r', self::FORMS)
        );
    }

    public function testAMixedListKeepsTheKnownAndReportsTheRest(): void
    {
        // The booster value on 279, with one typo added: the good names must survive a bad one.
        $this->assertSame(
            ['known' => ['ddq', 'sunday'], 'unknown' => ['sundays']],
            InjectInstruments::resolve('ddq,sunday,sundays', self::FORMS)
        );
    }

    /** @return array<string,array{0:?string}> */
    public static function emptyValues(): array
    {
        return ['null' => [null], 'empty' => [''], 'blanks and commas' => [' , ,, ']];
    }

    #[DataProvider('emptyValues')]
    public function testAnEmptySettingNamesNothing(?string $raw): void
    {
        $this->assertSame(['known' => [], 'unknown' => []], InjectInstruments::resolve($raw, self::FORMS));
    }

    public function testMatchingIsExactNotCaseFolded(): void
    {
        // Correcting `Ddq` to `ddq` would be a guess about which data the study meant to send.
        $this->assertSame(
            ['known' => ['ddq'], 'unknown' => ['Ddq']],
            InjectInstruments::resolve('Ddq, ddq', self::FORMS)
        );
    }

    public function testWhitespaceAroundNamesIsIgnored(): void
    {
        $this->assertSame(
            ['known' => ['ddq', 'audit'], 'unknown' => []],
            InjectInstruments::resolve("  ddq ,\taudit\n", self::FORMS)
        );
    }
}
