<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\UserRightsCheck;

#[CoversClass(UserRightsCheck::class)]
final class UserRightsCheckTest extends TestCase
{
    /** The shape REDCap's UserRights::getPrivileges($pid)[$pid] returns. */
    private const PROJECT = [
        'ihabz' => ['user_rights' => '1', 'design' => '1'],
        'test'  => ['user_rights' => '0', 'design' => '0'],
        'zoe'   => ['user_rights' => '1', 'design' => '0'],
    ];

    /**
     * The defect this class exists for. The old implementation read
     * `current(getPrivileges($pid)[$pid])` - the alphabetically first user - so its answer did not
     * depend on who was asking, and `test` (user_rights=0) was granted because `ihabz` sorts first
     * and has the right. Verified against the real PID 257 before the fix.
     */
    public function testAnswerDependsOnTheUserAskingNotOnArrayOrder(): void
    {
        $this->assertTrue(UserRightsCheck::hasSessionAdminRights(self::PROJECT, 'ihabz'));
        $this->assertFalse(
            UserRightsCheck::hasSessionAdminRights(self::PROJECT, 'test'),
            'a user with user_rights=0 was granted - the positional lookup is back'
        );
        $this->assertTrue(UserRightsCheck::hasSessionAdminRights(self::PROJECT, 'zoe'));
    }

    /** ...and the mirror-image failure: a real admin denied because someone else sorts first. */
    public function testAdminIsNotDeniedBecauseAnotherUserSortsFirst(): void
    {
        $privileges = ['aaron' => ['user_rights' => '0'], 'ihabz' => ['user_rights' => '1']];
        $this->assertTrue(UserRightsCheck::hasSessionAdminRights($privileges, 'ihabz'));
    }

    #[DataProvider('deniedCases')]
    public function testDenied(array $privileges, ?string $username, string $why): void
    {
        $this->assertFalse(UserRightsCheck::hasSessionAdminRights($privileges, $username), $why);
    }

    public static function deniedCases(): array
    {
        return [
            'not a user on this project' => [self::PROJECT, 'stranger', 'unknown users must be denied'],
            'anonymous'                  => [self::PROJECT, null, 'a null username must never pass'],
            'empty username'             => [self::PROJECT, '', 'an empty username must never pass'],
            'whitespace username'        => [self::PROJECT, '   ', 'whitespace is not a user'],
            'empty project'              => [[], 'ihabz', 'no rights rows means no rights'],
            'rights row not an array'    => [['ihabz' => 'yes'], 'ihabz', 'a malformed row must not pass'],
            'user_rights key absent'     => [['ihabz' => ['design' => '1']], 'ihabz', 'a missing key is not it'],
            'user_rights null'           => [['ihabz' => ['user_rights' => null]], 'ihabz', 'null is not permission'],
            'user_rights empty string'   => [['ihabz' => ['user_rights' => '']], 'ihabz', 'empty is not permission'],
            'user_rights zero string'    => [['ihabz' => ['user_rights' => '0']], 'ihabz', '0 is not permission'],
            'design rights are not it'   => [
                ['ihabz' => ['design' => '1', 'user_rights' => '0']],
                'ihabz',
                'design rights are not user_rights',
            ],
        ];
    }

    /** REDCap stores these as strings, but an int 1 from some other path must still work. */
    public function testIntegerOneIsAccepted(): void
    {
        $this->assertTrue(UserRightsCheck::hasSessionAdminRights(['ihabz' => ['user_rights' => 1]], 'ihabz'));
    }

    /**
     * `true` would also stringify to '1', but no REDCap path produces it; asserting the current
     * behaviour rather than leaving it undefined.
     */
    public function testBooleanTrueIsAccepted(): void
    {
        $this->assertTrue(UserRightsCheck::hasSessionAdminRights(['ihabz' => ['user_rights' => true]], 'ihabz'));
    }

    public function testSuperUserBypassesProjectRights(): void
    {
        // Consistent with the rest of REDCap, and with the framework's own link check.
        $this->assertTrue(UserRightsCheck::hasSessionAdminRights([], 'site_admin', true));
        $this->assertTrue(UserRightsCheck::hasSessionAdminRights(self::PROJECT, 'test', true));
    }

    /** Being a super user must not rescue an anonymous request. */
    public function testSuperUserFlagStillRequiresNothingOfAnonymous(): void
    {
        // Documents the deliberate ordering: isSuperUser() is resolved by the framework from a real
        // session, so if it is true there IS a user; the flag is trusted over the username here.
        $this->assertTrue(UserRightsCheck::hasSessionAdminRights([], null, true));
        $this->assertFalse(UserRightsCheck::hasSessionAdminRights([], null, false));
    }
}
