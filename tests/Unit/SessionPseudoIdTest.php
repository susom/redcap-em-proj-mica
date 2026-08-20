<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\SessionPseudoId;
use Stanford\MICA\TranscriptException;

#[CoversClass(SessionPseudoId::class)]
final class SessionPseudoIdTest extends TestCase
{
    private const SALT = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testIsDeterministic(): void
    {
        // A refinalize, and the counselor and scan paths, must all produce the same id for the same
        // session - otherwise findings cannot be tied back to the session they came from.
        $a = SessionPseudoId::derive(self::SALT, '257', '2', 'baseline', 1);
        $b = SessionPseudoId::derive(self::SALT, '257', '2', 'baseline', 1);

        $this->assertSame($a, $b);
    }

    public function testIsThirtyTwoHexCharacters(): void
    {
        $id = SessionPseudoId::derive(self::SALT, '257', '2', 'baseline', 1);

        $this->assertSame(32, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id, '02-data-model.md §2');
    }

    public function testDoesNotContainTheRecordId(): void
    {
        // The point of the whole class. The record id is the key the study team joins on, so it is
        // a direct identifier and must never reach a model.
        $id = SessionPseudoId::derive(self::SALT, '257', '104729', 'baseline', 1);

        $this->assertStringNotContainsString('104729', $id);
        $this->assertStringNotContainsString('257', $id);
    }

    public function testEveryInputChangesTheId(): void
    {
        $base = SessionPseudoId::derive(self::SALT, '257', '2', 'baseline', 1);

        $this->assertNotSame($base, SessionPseudoId::derive(self::SALT, '258', '2', 'baseline', 1));
        $this->assertNotSame($base, SessionPseudoId::derive(self::SALT, '257', '3', 'baseline', 1));
        $this->assertNotSame($base, SessionPseudoId::derive(self::SALT, '257', '2', 'booster', 1));
        $this->assertNotSame(
            $base,
            SessionPseudoId::derive(self::SALT, '257', '2', 'baseline', 2),
            'two sessions in one window must not share a pseudonymous SESSION id'
        );
    }

    public function testADifferentSaltGivesADifferentId(): void
    {
        $this->assertNotSame(
            SessionPseudoId::derive(self::SALT, '257', '2', 'baseline', 1),
            SessionPseudoId::derive(str_repeat('f', 64), '257', '2', 'baseline', 1)
        );
    }

    public function testAMissingSaltFailsClosedWithTheOperationalWarning(): void
    {
        try {
            SessionPseudoId::derive('', '257', '2', 'baseline', 1);
            $this->fail('an unsalted pseudo id is reversible by anyone who can guess a record id');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('never rotate', $e->getMessage());
            $this->assertStringContainsString(SessionPseudoId::SALT_SETTING, $e->getMessage());
        }
    }

    public function testAShortSaltIsRejected(): void
    {
        $this->expectException(TranscriptException::class);
        SessionPseudoId::derive('tooshort', '257', '2', 'baseline', 1);
    }

    public function testAPipeInAnInputIsRejected(): void
    {
        // "|" is the separator and nothing is escaped, so a value containing one could make two
        // different sessions hash identically. Cheap to assert, silent if not.
        $this->expectException(TranscriptException::class);
        $this->expectExceptionMessageMatches('/same value/');

        SessionPseudoId::derive(self::SALT, '257', '2|baseline', 'x', 1);
    }

    public function testAnEmptyRecordIsRejected(): void
    {
        $this->expectException(TranscriptException::class);
        SessionPseudoId::derive(self::SALT, '257', '', 'baseline', 1);
    }

    public function testGeneratedSaltIsLongEnoughForItsOwnValidator(): void
    {
        $salt = SessionPseudoId::generateSalt();

        $this->assertSame(64, strlen($salt));
        // Round trip: the generator must produce something derive() accepts, or setup is broken.
        $this->assertSame(32, strlen(SessionPseudoId::derive($salt, '257', '2', 'baseline', 1)));
        $this->assertNotSame($salt, SessionPseudoId::generateSalt(), 'must not be constant');
    }
}
