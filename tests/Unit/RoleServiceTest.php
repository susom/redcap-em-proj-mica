<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ReviewAccessException;
use Stanford\MICA\RoleService as R;

/**
 * The security boundary, so the matrix is tested exhaustively rather than by example: every role
 * against every action, plus the anonymous and unknown cases.
 */
#[CoversClass(R::class)]
final class RoleServiceTest extends TestCase
{
    /**
     * A project with four REDCap roles, three of them mapped to a MICA role.
     *
     * `no_role_dave` is on the project with rights and NO role - the case role-based access has to
     * get right, and the one a username list could not express at all.
     */
    private function service(array $mapping = [], bool $superUser = false, array $roster = []): R
    {
        return new R(
            $mapping + [R::RA => ['660'], R::PI => ['662'], R::AUDITOR => ['663']],
            $roster + [
                'ra_alice'      => '660',
                'pi_bob'        => '662',
                'auditor_carol' => '663',
                'no_role_dave'  => null,
                'other_erin'    => '661',   // a real REDCap role, mapped to nothing
            ],
            $superUser
        );
    }

    public static function everyRoleAndAction(): array
    {
        $cases = [];
        $users = [R::RA => 'ra_alice', R::PI => 'pi_bob', R::AUDITOR => 'auditor_carol'];

        foreach (array_keys(R::MATRIX) as $action) {
            foreach ($users as $role => $username) {
                $cases["$role can $action"] = [
                    $username,
                    $action,
                    in_array($role, R::MATRIX[$action], true),
                ];
            }
        }

        return $cases;
    }

    #[DataProvider('everyRoleAndAction')]
    public function testTheMatrixIsEnforcedExactly(string $username, string $action, bool $expected): void
    {
        $this->assertSame($expected, $this->service()->can($username, $action));
    }

    public function testAnUnknownUserCanDoNothing(): void
    {
        foreach (array_keys(R::MATRIX) as $action) {
            $this->assertFalse($this->service()->can('stranger', $action), "stranger must not $action");
        }
    }

    public function testAnonymousCanDoNothing(): void
    {
        // A blank username happens on a no-auth request. It has to be nobody, not everybody.
        foreach ([null, '', '   '] as $username) {
            foreach (array_keys(R::MATRIX) as $action) {
                $this->assertFalse($this->service()->can($username, $action));
            }
        }
    }

    public function testAnUnlistedActionIsDenied(): void
    {
        // The failure direction that matters: a new endpoint that forgets to declare itself is
        // refused, so it is caught by its own first test rather than by an auditor.
        foreach (['ra_alice', 'pi_bob', 'auditor_carol'] as $username) {
            $this->assertFalse($this->service()->can($username, 'deleteEverything'));
        }
        $this->assertFalse($this->service([], true)->can('root', 'deleteEverything'));
    }

    public function testAnUnlistedActionSaysItIsAProgrammingError(): void
    {
        try {
            $this->service()->requireRole('ra_alice', 'reviewQuoue');   // typo
            $this->fail('expected a refusal');
        } catch (ReviewAccessException $e) {
            $this->assertStringContainsString('programming error', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ sysadmin

    public function testASuperUserCannotSubmitAClinicalDecision(): void
    {
        // Not because they could not technically do it, but because a disposition carries the
        // reviewer's clinical judgment - and one submitted by whoever held admin rights would be
        // indistinguishable from an RA's. The RA-first workflow depends on that difference.
        $sysadmin = $this->service([], true);

        $this->assertFalse($sysadmin->can('root', 'submitDisposition'));
        $this->assertFalse($sysadmin->can('root', 'submitAction'));
        $this->assertFalse($sysadmin->can('root', 'reviewQueue'), 'nor read participant content');
        $this->assertTrue($sysadmin->can('root', 'savePolicy'), 'but configuration is theirs');
    }

    public function testSuperUserStatusAloneGrantsTheSysadminRole(): void
    {
        $this->assertSame([R::SYSADMIN], $this->service([], true)->rolesFor('root'));
        $this->assertSame([], $this->service([], false)->rolesFor('root'));
    }

    // ------------------------------------------------------------------ multiple roles

    public function testOneRedcapRoleMayMapToSeveralMicaRoles(): void
    {
        // Small studies overlap: the same REDCap role often covers reviewing and oversight.
        $service = $this->service([R::RA => ['660', '662']]);

        $this->assertSame([R::RA, R::PI], $service->rolesFor('pi_bob'));
        $this->assertTrue($service->can('pi_bob', 'submitDisposition'));
        $this->assertTrue($service->can('pi_bob', 'auditTrail'));
    }

    public function testAUserWithNoRedcapRoleHasNoAccess(): void
    {
        // The honest consequence of role-based access, and the whole reason it is better: somebody
        // whose study access was never set up cannot read a transcript, and nobody has to remember
        // to remove them from a second list.
        $service = $this->service();

        $this->assertSame([], $service->rolesFor('no_role_dave'));
        $this->assertTrue($service->isOnProject('no_role_dave'), 'but they ARE on the project');
        $this->assertNull($service->redcapRoleFor('no_role_dave'));

        foreach (array_keys(R::MATRIX) as $action) {
            $this->assertFalse($service->can('no_role_dave', $action));
        }
    }

    public function testAnUnmappedRedcapRoleGrantsNothing(): void
    {
        $service = $this->service();

        $this->assertSame('661', $service->redcapRoleFor('other_erin'), 'they have a role');
        $this->assertSame([], $service->rolesFor('other_erin'), 'it just is not mapped');
    }

    public function testRemovingSomebodyFromTheProjectRemovesTheirMicaAccess(): void
    {
        // The failure a username list produced: access outliving study membership. Here there is
        // nothing to forget - a user absent from redcap_user_rights holds no role.
        $service = new R([R::RA => ['660']], [], false);

        $this->assertFalse($service->isOnProject('ra_alice'));
        $this->assertFalse($service->can('ra_alice', 'submitDisposition'));
    }

    public function testAnUnconfiguredMappingIsDetectable(): void
    {
        // Nobody can review anything, which the page needs to say specifically - it is a very
        // different problem from "you personally lack access".
        $this->assertTrue((new R([], ['ra_alice' => '660']))->isUnconfigured());
        $this->assertFalse($this->service()->isUnconfigured());
    }

    public function testAddingAnAdminRightDoesNotRemoveAClinicalOne(): void
    {
        // Deny is never inherited. Otherwise granting someone super-user status would silently
        // strip their ability to review, which is a very surprising way to lose access.
        $service = $this->service([], true);

        $this->assertTrue($service->can('ra_alice', 'submitDisposition'));
        $this->assertContains(R::SYSADMIN, $service->rolesFor('ra_alice'));
    }

    public function testSuperUserStatusIsNotAProjectRole(): void
    {
        // It is the one MICA role that does not come from a REDCap project role, because super-user
        // status does not either - and a super user with no project role still configures the module.
        $service = $this->service([], true, ['root' => null]);

        $this->assertSame([R::SYSADMIN], $service->rolesFor('root'));
        $this->assertTrue($service->can('root', 'savePolicy'));
    }

    // ------------------------------------------------------------------ the auditor

    public function testAnAuditorSeesAggregatesOnly(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isDeidentifiedOnly('auditor_carol'));
        $this->assertFalse($service->can('auditor_carol', 'reviewSession'), 'never a transcript');
        $this->assertFalse($service->can('auditor_carol', 'submitDisposition'));
        $this->assertTrue($service->can('auditor_carol', 'reviewHistory'));
        $this->assertTrue($service->can('auditor_carol', 'auditTrail'));
    }

    public function testAnAuditorRoleThatIsAlsoAReviewerRoleIsNotRestricted(): void
    {
        // Adding an oversight mapping must not take away a clinical view the role already had.
        $service = $this->service([R::AUDITOR => ['663', '660']]);

        $this->assertFalse($service->isDeidentifiedOnly('ra_alice'));
        $this->assertTrue($service->can('ra_alice', 'reviewSession'));
    }

    public function testAnRaIsNotInTheirOwnOversightRecord(): void
    {
        $this->assertFalse($this->service()->can('ra_alice', 'auditTrail'));
    }

    // ------------------------------------------------------------------ details

    public function testUsernamesAreCaseInsensitive(): void
    {
        // REDCap treats them that way, and access that silently does not apply because of
        // capitalisation is worse than none at all.
        $service = $this->service([], false, ['RA_Alice' => '660']);

        $this->assertTrue($service->can('ra_alice', 'submitDisposition'));
        $this->assertTrue($service->can('RA_ALICE', 'submitDisposition'));
    }

    public function testRoleIdsCompareAsStrings(): void
    {
        // Settings hand them over as strings and redcap_user_rights stores an int, so 663 and "663"
        // must not be able to disagree.
        $service = new R([R::RA => [660]], ['ra_alice' => 660]);

        $this->assertTrue($service->can('ra_alice', 'submitDisposition'));
    }

    public function testBlanksInTheMappingAreIgnored(): void
    {
        $service = $this->service([R::RA => ['660', '', '   ', null]]);

        $this->assertTrue($service->can('ra_alice', 'submitDisposition'));
        $this->assertSame(['660'], $service->mappedRedcapRoles(R::RA), 'and nothing spurious');
    }

    public function testDuplicatesCollapse(): void
    {
        $this->assertSame(
            ['660'],
            $this->service([R::RA => ['660', '660']])->mappedRedcapRoles(R::RA)
        );
    }

    public function testHasAnyRoleGatesThePageItself(): void
    {
        $service = $this->service();

        $this->assertTrue($service->hasAnyRole('ra_alice'));
        $this->assertTrue($service->hasAnyRole('auditor_carol'));
        $this->assertFalse($service->hasAnyRole('stranger'));
        $this->assertFalse($service->hasAnyRole(null));
    }

    public function testThePrimaryRoleIsTheMostPrivilegedHeld(): void
    {
        // What lands on an audit row: it should not understate what the actor was entitled to do.
        // The PI's REDCap role is also mapped as a reviewer here, so they hold both.
        $service = $this->service([R::RA => ['660', '662']]);

        $this->assertSame(R::PI, $service->primaryRoleFor('pi_bob'));
        $this->assertSame(R::RA, $service->primaryRoleFor('ra_alice'));
        $this->assertSame(R::AUDITOR, $service->primaryRoleFor('auditor_carol'));
        $this->assertNull($service->primaryRoleFor('stranger'));
    }

    public function testTheRefusalMessageSaysWhoMayAndWhoYouAre(): void
    {
        try {
            $this->service()->requireRole('auditor_carol', 'submitDisposition');
            $this->fail('expected a refusal');
        } catch (ReviewAccessException $e) {
            $this->assertStringContainsString('submitDisposition', $e->getMessage());
            $this->assertStringContainsString('ra, pi', $e->getMessage(), 'who may');
            $this->assertStringContainsString('auditor', $e->getMessage(), 'who you are');
        }
    }

    public function testRequireRolePassesSilentlyWhenAllowed(): void
    {
        $this->service()->requireRole('ra_alice', 'reviewQueue');
        $this->addToAssertionCount(1);
    }

    public function testCareTeamIsNotARole(): void
    {
        // They receive notifications and get no dashboard access at all - which is why the
        // notification path carries minimum-necessary content rather than a deep link.
        $this->assertNotContains('care_team', array_keys(R::SETTINGS));
        foreach (R::MATRIX as $action => $roles) {
            $this->assertNotContains('care_team', $roles, "care_team must not be able to $action");
        }
    }

    public function testEveryActionInTheMatrixGrantsSomebodySomething(): void
    {
        // An action nobody can perform is dead surface that reads as a feature.
        foreach (R::MATRIX as $action => $roles) {
            $this->assertNotEmpty($roles, "$action is unreachable by every role");
        }
    }

    public function testTheMappingIsConfigurationNotARoster(): void
    {
        // The design decision, asserted: the settings hold REDCap ROLE ids, never usernames. A
        // username creeping back into one of these would rebuild the second roster this replaced.
        foreach (array_keys(R::SETTINGS) as $role) {
            foreach ($this->service()->mappedRedcapRoles($role) as $value) {
                $this->assertMatchesRegularExpression('/^\d+$/', $value, "$role maps a non-role-id");
            }
        }
    }

    public function testEveryRoleWithASettingCanDoSomething(): void
    {
        foreach (array_keys(R::SETTINGS) as $role) {
            $reachable = array_filter(R::MATRIX, static fn(array $r): bool => in_array($role, $r, true));
            $this->assertNotEmpty($reachable, "the $role role grants nothing");
        }
    }
}
