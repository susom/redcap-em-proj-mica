<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\SessionHostMap;
use Stanford\MICA\SessionWindow;

#[CoversClass(SessionWindow::class)]
final class SessionWindowTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testTheEdWindowRunsFromTheFirstMessageForTheConfiguredHours(): void
    {
        $w = new SessionWindow(24, 14);

        $inside = $w->decide(SessionHostMap::BASELINE, self::NOW - 3600, false, self::NOW);
        $this->assertFalse($inside['close']);
        $this->assertSame(SessionWindow::SKIP_WITHIN_WINDOW, $inside['reason']);

        $past = $w->decide(SessionHostMap::BASELINE, self::NOW - (25 * 3600), false, self::NOW);
        $this->assertTrue($past['close']);
        $this->assertNull($past['reason']);
    }

    public function testTheBoosterWindowIsMeasuredInDaysNotHours(): void
    {
        $w = new SessionWindow(24, 14);

        // 25 hours: past the ED window, nowhere near the booster's.
        $this->assertFalse($w->decide(SessionHostMap::BOOSTER, self::NOW - (25 * 3600), false, self::NOW)['close']);
        $this->assertTrue($w->decide(SessionHostMap::BOOSTER, self::NOW - (15 * 86400), false, self::NOW)['close']);
    }

    /** The boundary is inclusive: at exactly the closing second the window is over. */
    public function testTheWindowClosesExactlyOnTheBoundary(): void
    {
        $w = new SessionWindow(24, 14);
        $start = self::NOW - (24 * 3600);

        $this->assertTrue($w->decide(SessionHostMap::BASELINE, $start, false, self::NOW)['close']);
        $this->assertFalse($w->decide(SessionHostMap::BASELINE, $start + 1, false, self::NOW)['close']);
    }

    /**
     * The reopen path, and the reason this check comes before the window check. A reopened session's
     * window is past by definition, so asking "is the window over?" first would set the flag straight
     * back and undo the CRC's action within the hour.
     */
    public function testASessionAlreadyClosedOnceIsNeverClosedAgain(): void
    {
        $w = new SessionWindow(24, 14);
        $longPast = self::NOW - (400 * 86400);

        $result = $w->decide(SessionHostMap::BASELINE, $longPast, true, self::NOW);

        $this->assertFalse($result['close'], 'a reopen must survive every later pass');
        $this->assertSame(SessionWindow::SKIP_ALREADY_CLOSED, $result['reason']);
    }

    /**
     * The stated consequence of anchoring on the first message: an opened-but-unused session has no
     * clock. Asserted so it is a decision on the record rather than an omission somebody later reads
     * as a bug.
     */
    #[DataProvider('noFirstMessage')]
    public function testASessionWithNoMessagesHasNoWindow(?int $firstMessageAt): void
    {
        $w = new SessionWindow(24, 14);
        $result = $w->decide(SessionHostMap::BASELINE, $firstMessageAt, false, self::NOW);

        $this->assertFalse($result['close']);
        $this->assertSame(SessionWindow::SKIP_NO_MESSAGES, $result['reason']);
    }

    /** @return array<string,array{0:?int}> */
    public static function noFirstMessage(): array
    {
        return ['null' => [null], 'zero' => [0], 'negative' => [-1]];
    }

    /**
     * A misconfigured window must not close every session the moment it starts, which is what a 0
     * would do. The shipped default applies instead and the setting is reported elsewhere.
     */
    #[DataProvider('unusableWindows')]
    public function testAnUnusableWindowFallsBackToTheDefaultRatherThanClosingEverything(?int $hours): void
    {
        $w = new SessionWindow($hours, $hours);

        $this->assertSame(SessionWindow::DEFAULT_ED_HOURS * 3600, $w->windowSeconds(SessionHostMap::BASELINE));
        $this->assertFalse(
            $w->decide(SessionHostMap::BASELINE, self::NOW - 60, false, self::NOW)['close'],
            'a session one minute old is not over'
        );
    }

    /** @return array<string,array{0:?int}> */
    public static function unusableWindows(): array
    {
        return ['zero' => [0], 'negative' => [-5], 'unset' => [null]];
    }

    public function testAnUnknownSessionTypeUsesTheEdWindowRatherThanNone(): void
    {
        // Falling through to "no window" would leave such a session open forever.
        $w = new SessionWindow(24, 14);
        $this->assertSame(24 * 3600, $w->windowSeconds('something_else'));
    }

    // ------------------------------------------------------------------ attribution

    public function testAttributionPicksTheMostRecentlyIssuedLink(): void
    {
        $result = SessionWindow::attribute([
            ['response_id' => 2355, 'session_type' => 'baseline'],
            ['response_id' => 2999, 'session_type' => 'booster'],
        ]);

        $this->assertSame(2999, $result['session']['response_id']);
        $this->assertTrue($result['ambiguous'], 'two open sessions is reportable, not silent');
    }

    public function testASingleOpenSessionIsNotAmbiguous(): void
    {
        $result = SessionWindow::attribute([['response_id' => 2355, 'session_type' => 'baseline']]);

        $this->assertSame(2355, $result['session']['response_id']);
        $this->assertFalse($result['ambiguous']);
    }

    public function testNoOpenSessionsAttributesToNothing(): void
    {
        $this->assertSame(
            ['session' => null, 'ambiguous' => false],
            SessionWindow::attribute([])
        );
    }
}
