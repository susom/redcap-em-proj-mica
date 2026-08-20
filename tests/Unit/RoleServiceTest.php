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
    private function service(array $members = [], bool $superUser = false): R
    {
        return new R($members + [
            R::RA      => ['ra_alice'],
            R::PI      => ['pi_bob'],
            R::AUDITOR => ['auditor_carol'],
        ], $superUser);
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

    public function testARoleIsGrantedIfAnyHeldRoleGrantsIt(): void
    {
        // Small studies overlap - the PI is often also a reviewer.
        $service = $this->service([R::RA => ['ra_alice', 'pi_bob']]);

        $this->assertSame([R::RA, R::PI], $service->rolesFor('pi_bob'));
        $this->assertTrue($service->can('pi_bob', 'submitDisposition'));
        $this->assertTrue($service->can('pi_bob', 'auditTrail'));
    }

    public function testAddingAnAdminRightDoesNotRemoveAClinicalOne(): void
    {
        // Deny is never inherited. Otherwise granting someone super-user status would silently
        // strip their ability to review, which is a very surprising way to lose access.
        $service = $this->service([], true);

        $this->assertTrue($service->can('ra_alice', 'submitDisposition'));
        $this->assertContains(R::SYSADMIN, $service->rolesFor('ra_alice'));
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

    public function testAnAuditorWhoIsAlsoAReviewerIsNotRestricted(): void
    {
        // Adding an oversight role must not take away a clinical view they already had.
        $service = $this->service([R::AUDITOR => ['auditor_carol', 'ra_alice']]);

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
        // REDCap treats them that way, and a role that silently does not apply because of
        // capitalisation is worse than no role at all.
        $service = $this->service([R::RA => ['RA_Alice']]);

        $this->assertTrue($service->can('ra_alice', 'submitDisposition'));
        $this->assertTrue($service->can('RA_ALICE', 'submitDisposition'));
    }

    public function testWhitespaceAndBlanksInTheSettingAreIgnored(): void
    {
        $service = $this->service([R::RA => ['  ra_alice  ', '', '   ', null, 42]]);

        $this->assertTrue($service->can('ra_alice', 'submitDisposition'));
        $this->assertSame(['ra_alice'], $service->membersOf(R::RA), 'and nothing spurious');
    }

    public function testDuplicatesCollapse(): void
    {
        $this->assertSame(
            ['ra_alice'],
            $this->service([R::RA => ['ra_alice', 'RA_Alice', 'ra_alice']])->membersOf(R::RA)
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
        $service = $this->service([R::RA => ['ra_alice', 'pi_bob']]);

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

    public function testEveryRoleWithASettingCanDoSomething(): void
    {
        foreach (array_keys(R::SETTINGS) as $role) {
            $reachable = array_filter(R::MATRIX, static fn(array $r): bool => in_array($role, $r, true));
            $this->assertNotEmpty($reachable, "the $role role grants nothing");
        }
    }
}
