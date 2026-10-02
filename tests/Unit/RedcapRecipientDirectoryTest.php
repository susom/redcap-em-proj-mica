<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\RedcapRecipientDirectory;

/**
 * Who hears that safety-scan findings are ready: the named list when there is one, the role when not.
 *
 * Only the rule is tested here; the role lookup itself is a REDCap query and is exercised live by
 * docs/phase-3-handoff/scripts/verify-notifications.php.
 */
#[CoversClass(RedcapRecipientDirectory::class)]
final class RedcapRecipientDirectoryTest extends TestCase
{
    private const ROLE = ['ra.one@example.org', 'ra.two@example.org'];

    private int $roleLookups = 0;

    private function choose(string $named): array
    {
        return RedcapRecipientDirectory::chooseReviewerAddresses($named, function (): array {
            $this->roleLookups++;
            return self::ROLE;
        });
    }

    /** @return array<string,array{string}> */
    public static function blankSettings(): array
    {
        return [
            'empty'           => [''],
            'spaces'          => ['   '],
            'delimiters only' => [" ,; \n "],
        ];
    }

    #[DataProvider('blankSettings')]
    public function testABlankSettingKeepsTheReviewerRole(string $named): void
    {
        $this->assertSame(self::ROLE, $this->choose($named));
        $this->assertSame(1, $this->roleLookups);
    }

    public function testNamedAddressesReplaceTheRoleEntirely(): void
    {
        $this->assertSame(
            ['crc.a@example.org', 'crc.b@example.org'],
            $this->choose("crc.a@example.org;\ncrc.b@example.org")
        );
        $this->assertSame(0, $this->roleLookups, 'the role must not be consulted, let alone merged in');
    }

    public function testCommasSemicolonsAndNewlinesAllSeparate(): void
    {
        $this->assertSame(
            ['a@example.org', 'b@example.org', 'c@example.org'],
            $this->choose("a@example.org, b@example.org;c@example.org\r\n")
        );
    }

    public function testTheSameAddressTwiceIsEmailedOnce(): void
    {
        // Case-insensitive, as mail addresses are in practice; which spelling survives does not matter.
        $chosen = $this->choose('CRC@example.org, crc@example.org');
        $this->assertCount(1, $chosen);
        $this->assertSame('crc@example.org', strtolower($chosen[0]));
    }

    public function testAnInvalidEntryIsDroppedWithoutLosingTheValidOnes(): void
    {
        $this->assertSame(['crc@example.org'], $this->choose('crc@example.org, not-an-address'));
    }

    public function testAnAddressPastedFromAMailClientIsAccepted(): void
    {
        // On a Production project an all-invalid list fails a launch gate, which stops new sessions,
        // so the common paste shape must not be what does it.
        $this->assertSame(
            ['brian@example.org', 'jane@example.org'],
            $this->choose('Brian Smith <brian@example.org>; "Doe, Jane" <jane@example.org>')
        );
    }

    public function testUnmatchedAddressesAreTheNamedOnesWithNoReviewerAccount(): void
    {
        $this->assertSame(
            ['gone@example.org'],
            RedcapRecipientDirectory::unmatchedAddresses(
                ['CRC@example.org', 'gone@example.org'],
                ['crc@example.org', 'pi@example.org']
            )
        );
    }

    public function testAListWithOnlyInvalidEntriesReachesNobodyRatherThanFallingBackToTheRole(): void
    {
        // Falling back would email people the study deliberately took off the list. Nobody is a
        // recorded failure plus a launch-gate problem; a silent substitution is neither.
        $this->assertSame([], $this->choose('crc at example dot org'));
        $this->assertSame(0, $this->roleLookups);
    }
}
