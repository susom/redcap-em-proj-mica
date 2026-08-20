<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\GateResult;
use Stanford\MICA\LaunchReadiness;
use Stanford\MICA\RoleService;
use Stanford\MICA\Tests\Support\FakeLaunchEnvironment;

/**
 * The class whose whole purpose is to say no.
 *
 * Each test breaks exactly one thing and asserts that exactly one gate fails, because a gate that
 * trips on unrelated problems gets read as noise and then gets bypassed.
 */
#[CoversClass(LaunchReadiness::class)]
final class LaunchReadinessTest extends TestCase
{
    private FakeLaunchEnvironment $env;

    protected function setUp(): void
    {
        $this->env = new FakeLaunchEnvironment();
    }

    private function gates(): LaunchReadiness
    {
        return new LaunchReadiness($this->env);
    }

    /** @return array<string,GateResult> keyed by gate id */
    private function byId(): array
    {
        $out = [];
        foreach ($this->gates()->evaluate() as $gate) {
            $out[$gate->id] = $gate;
        }

        return $out;
    }

    private function failedIds(): array
    {
        return array_map(static fn(GateResult $g): string => $g->id, $this->gates()->blockers());
    }

    public function testAFullyConfiguredProjectIsReady(): void
    {
        // The baseline the other tests break one thing from.
        $this->assertTrue($this->gates()->isReady());
        $this->assertSame([], $this->failedIds());
        $this->assertSame('All launch gates pass.', $this->gates()->staffExplanation());
    }

    public function testEveryGateReportsOnEveryEvaluation(): void
    {
        // A gate that only appears when it fails cannot be shown as a checklist, and a checklist is
        // what an administrator needs to see what is left.
        $this->assertSame(
            [
                'critical_acknowledgment_minutes',
                'reviewers',
                'artifacts',
                'models',
                'policy',
                'recipients',
                'scan_mock_mode',
            ],
            array_keys($this->byId())
        );
    }

    public function testAMistypedNotificationAddressBlocksLaunch(): void
    {
        // A malformed address is skipped at send time rather than failing the whole message, so the
        // symptom would otherwise be a care team that never heard about a confirmed critical finding,
        // with nothing in the trail saying anyone was left out.
        $this->env->recipientProblems = ['The care team list contains 1 entry: care@@example.org'];

        $this->assertSame(['recipients'], $this->failedIds());
        $this->assertStringContainsString(
            'care@@example.org',
            $this->byId()['recipients']->detail
        );
    }

    // ------------------------------------------------------------- gate 1, the deliberate blocker

    public function testTheAcknowledgmentTargetBlocksLaunchWhenUnset(): void
    {
        // Ships as null ON PURPOSE. A sensible-looking default would quietly answer a clinical
        // governance question on the study's behalf, which is the one thing this gate prevents.
        $this->env->policyData['ra_review_policy']['critical_acknowledgment_minutes'] = null;

        $this->assertSame(['critical_acknowledgment_minutes'], $this->failedIds());
        $this->assertFalse($this->gates()->isReady());
    }

    public function testItIsMarkedAsADecisionNotABreakage(): void
    {
        // So the UI can say "waiting on a decision" rather than "something is broken" - which is
        // the difference between escalating to leadership and escalating to IT.
        $this->env->policyData['ra_review_policy']['critical_acknowledgment_minutes'] = null;

        $gate = $this->byId()['critical_acknowledgment_minutes'];

        $this->assertTrue($gate->deliberate);
        $this->assertStringContainsString('on purpose', $gate->detail);
        $this->assertStringContainsString('governance', $gate->detail);
    }

    public function testItIsTheOnlyDeliberateGate(): void
    {
        // Every other failure is a configuration slip. Marking more of them "deliberate" would blunt
        // the one that is.
        foreach ($this->byId() as $id => $gate) {
            $this->assertSame(
                $id === 'critical_acknowledgment_minutes',
                $gate->deliberate,
                "$id has the wrong deliberate flag"
            );
        }
    }

    public function testASetTargetPasses(): void
    {
        $this->env->policyData['ra_review_policy']['critical_acknowledgment_minutes'] = 30;

        $this->assertTrue($this->byId()['critical_acknowledgment_minutes']->passed);
        $this->assertStringContainsString('30 minutes', $this->byId()['critical_acknowledgment_minutes']->detail);
    }

    // ------------------------------------------------------------- gate 2, reviewers

    public function testNoMappedReviewerRoleFailsTheReviewersGate(): void
    {
        $this->env->roleMapping = [];

        $this->assertSame(['reviewers'], $this->failedIds());
        $this->assertStringContainsString('no finding could ever be reviewed', $this->byId()['reviewers']->detail);
    }

    public function testAMappedRoleWithNobodyInItAlsoFails(): void
    {
        // The subtler half: a role exists, the mapping exists, and the queue has no audience.
        // "The assigned RA" the policy insists on notifying is whoever is in the role.
        $this->env->reviewers = [];

        $this->assertSame(['reviewers'], $this->failedIds());
        $this->assertStringContainsString('no user is in it', $this->byId()['reviewers']->detail);
        $this->assertStringContainsString('User Rights', $this->byId()['reviewers']->howToFix);
    }

    public function testTheTwoReviewerFailuresGiveDifferentInstructions(): void
    {
        // One is fixed in the module config, the other in User Rights. Same gate, different screen.
        $this->env->roleMapping = [];
        $noMapping = $this->byId()['reviewers']->howToFix;

        $this->env->roleMapping = [RoleService::RA => ['660']];
        $this->env->reviewers = [];
        $noMembers = $this->byId()['reviewers']->howToFix;

        $this->assertNotSame($noMapping, $noMembers);
        $this->assertStringContainsString('Configure', $noMapping);
        $this->assertStringContainsString('User Rights', $noMembers);
    }

    // ------------------------------------------------------------- gate 3, artifacts

    public function testATamperedArtifactBlocksLaunch(): void
    {
        $this->env->artifactError = new \RuntimeException('counselor_prompt hash mismatch');

        $this->assertSame(['artifacts'], $this->failedIds());
        $this->assertStringContainsString('hash mismatch', $this->byId()['artifacts']->detail);
    }

    public function testItTellsThemNotToEditTheArtifactInPlace(): void
    {
        // The tempting wrong fix: change the file to match, or re-hash it. Either destroys the tie
        // between a scan result and the prompt the research team validated.
        $this->env->artifactError = new \RuntimeException('mismatch');

        $this->assertStringContainsString('Do NOT edit', $this->byId()['artifacts']->howToFix);
        $this->assertStringContainsString('validated', $this->byId()['artifacts']->howToFix);
    }

    // ------------------------------------------------------------- gate 4, models

    public function testAnUnregisteredAliasBlocksLaunch(): void
    {
        $this->env->safetyScan = 'gemini-3.5-flash';   // production candidate, not in the dev registry

        $this->assertSame(['models'], $this->failedIds());
        $this->assertStringContainsString('gemini-3.5-flash', $this->byId()['models']->detail);
        $this->assertStringContainsString('safetyscan', $this->byId()['models']->detail);
    }

    public function testItNamesWhatISRegistered(): void
    {
        // Otherwise the administrator has to go and look, and the gate has told them half a fact.
        $this->env->available = ['claude-opus-4-7'];

        $this->assertStringContainsString('claude-opus-4-7', $this->byId()['models']->detail);
    }

    public function testItExplainsWhyAnUnregisteredAliasIsDangerousRatherThanMerelyWrong(): void
    {
        // It does not fail loudly at call time - it returns the provider's canned apology, which
        // MICA then stored as a counselor turn. That is docs 14 D1, and it is why this is a gate.
        $this->env->counselor = 'nope';

        $this->assertStringContainsString('canned apology', $this->byId()['models']->howToFix);
    }

    public function testAnEmptyRegistryFailsRatherThanVacuouslyPassing(): void
    {
        $this->env->available = [];

        $this->assertContains('models', $this->failedIds());
    }

    public function testNoAliasConfiguredAtAllFails(): void
    {
        $this->env->counselor = null;
        $this->env->safetyScan = '';

        $this->assertSame(['models'], $this->failedIds());
        $this->assertStringContainsString('No model alias is configured', $this->byId()['models']->detail);
    }

    // ------------------------------------------------------------- gate 5, policy

    public function testAnInvalidPolicyBlocksLaunch(): void
    {
        $this->env->policyErrors = ['/pre_review_notifications/required_label: const mismatch'];

        $this->assertSame(['policy'], $this->failedIds());
        $this->assertStringContainsString('required_label', $this->byId()['policy']->detail);
    }

    // ------------------------------------------------------------- gate 6, mock mode

    public function testMockModeBlocksLaunchAsItsOwnGate(): void
    {
        // Its own gate, not folded into the model gate: "the scanner is replaying fixtures" and "the
        // alias is not registered" are different problems and would send someone to different
        // screens. Reporting one as the other wastes the reviewer's time at the worst moment.
        $this->env->mockMode = true;

        $this->assertSame(['scan_mock_mode'], $this->failedIds());
        $detail = $this->byId()['scan_mock_mode']->detail;
        $this->assertStringContainsString('replaying fixtures', $detail);
        $this->assertStringContainsString('no session would actually be screened', $detail);
    }

    // ------------------------------------------------------------- enforcement

    public function testAProductionProjectWithFailingGatesRefusesToStartASession(): void
    {
        $this->env->production = true;
        $this->env->mockMode = true;

        $this->assertFalse($this->gates()->mayStartSession());
    }

    public function testADevelopmentProjectStartsSessionsRegardless(): void
    {
        // That is what a development project is for. The chatbot shows a banner instead.
        $this->env->production = false;
        $this->env->mockMode = true;
        $this->env->policyData['ra_review_policy']['critical_acknowledgment_minutes'] = null;

        $this->assertFalse($this->gates()->isReady());
        $this->assertTrue($this->gates()->mayStartSession());
    }

    public function testEnforcementKeysOnProjectStatusNotOnAProductionModeClaim(): void
    {
        // Keying it on the module's production-mode checkbox would mean a study that never ticked
        // that box is never gated - which is exactly the study most likely to have skipped the rest
        // of the setup too. There is no production-mode input to this class at all, on purpose.
        $this->assertFalse(
            method_exists(LaunchReadiness::class, 'isProductionMode'),
            'the gates must not consult a self-asserted production-mode flag'
        );
        $this->assertFalse(
            method_exists(\Stanford\MICA\LaunchEnvironmentInterface::class, 'isProductionMode'),
            'nor should the environment offer one'
        );
    }

    // ------------------------------------------------------------- the development banner

    public function testADevelopmentProjectWithFailingGatesGetsABanner(): void
    {
        $this->env->production = false;
        $this->env->mockMode = true;

        $banner = $this->gates()->developmentBanner();

        $this->assertNotNull($banner);
        $this->assertSame(1, $banner['count']);
        $this->assertSame(['Scan mock mode is off'], $banner['titles']);
        $this->assertStringContainsString('development only', $banner['headline']);
    }

    public function testTheBannerCarriesNoGateDetailBecauseTheChatPageNeedsNoAuth(): void
    {
        // pages/chatbot.php is in no-auth-pages, so anything here is readable by anyone with the
        // survey link. Titles are generic; details are not - the models gate enumerates the
        // SecureChatAI registry and the recipients gate quotes configured email addresses.
        $this->env->production = false;
        $this->env->mockMode = true;
        $this->env->counselor = 'not-registered';
        $this->env->recipientProblems = ['The care team list contains care@@example.org'];

        $banner = $this->gates()->developmentBanner();
        $serialised = json_encode($banner);

        $this->assertArrayNotHasKey('detail', $banner);
        $this->assertArrayNotHasKey('how_to_fix', $banner);
        $this->assertStringNotContainsString('not-registered', $serialised);
        $this->assertStringNotContainsString('care@@example.org', $serialised);
        $this->assertStringNotContainsString('gpt-5-4', $serialised, 'the model registry leaked');
    }

    public function testTheBannerSeparatesADecisionFromAMisconfiguration()
    {
        // The acknowledgment target is waiting on study leadership, not broken. Telling an
        // administrator to go and fix it wastes the one read they will give this.
        $this->env->production = false;
        $this->env->policyData['ra_review_policy']['critical_acknowledgment_minutes'] = null;
        $this->env->mockMode = true;

        $banner = $this->gates()->developmentBanner();

        $this->assertCount(2, $banner['titles']);
        $this->assertSame(['Critical-finding acknowledgment target'], $banner['awaiting_decision']);
    }

    public function testAProductionProjectGetsNoBannerBecauseItGetsARefusal(): void
    {
        // A refusal is a stronger statement in the same place. Two of them would be noise.
        $this->env->production = true;
        $this->env->mockMode = true;

        $this->assertNull($this->gates()->developmentBanner());
    }

    public function testAFullyConfiguredDevelopmentProjectGetsNoBanner(): void
    {
        // A permanent "this is dev" badge teaches people to ignore the banner, which is the one
        // thing it cannot afford.
        $this->env->production = false;

        $this->assertNull($this->gates()->developmentBanner());
    }

    // ------------------------------------------------------------- evaluation cost

    public function testGatesAreEvaluatedOncePerInstance(): void
    {
        // One page load asks more than once - the chat page calls mayStartSession() and then
        // developmentBanner(); the dashboard calls isReady() and evaluate(). Each evaluation hashes
        // every pinned artifact and runs a user-rights join, so without memoisation a participant's
        // page load would pay for it several times over.
        $gates = $this->gates();
        $this->env->verifyCalls = 0;

        $gates->evaluate();
        $gates->isReady();
        $gates->blockers();
        $gates->developmentBanner();
        $gates->staffExplanation();

        $this->assertSame(1, $this->env->verifyCalls);
    }

    // ------------------------------------------------------------- what the participant sees

    public function testTheParticipantRefusalSaysNothingAboutGates(): void
    {
        // A participant is not the audience for a configuration report, and listing what is
        // unconfigured would be both useless to them and a small information leak.
        $this->env->mockMode = true;
        $this->env->counselor = 'nope';

        $refusal = $this->gates()->participantRefusal();

        $this->assertSame('The study team has been notified.', $refusal);
        foreach (['gate', 'mock', 'alias', 'policy', 'nope'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $refusal);
        }
    }

    public function testItFallsBackToAnApprovedSentenceWhenNoneIsConfigured(): void
    {
        // Never a blank message: an empty refusal is a participant staring at nothing.
        $this->env->fallbackText = '   ';

        $this->assertStringContainsString('study team', $this->gates()->participantRefusal());
    }

    public function testTheStaffExplanationListsEveryBlockerWithItsFix(): void
    {
        $this->env->mockMode = true;
        $this->env->policyData['ra_review_policy']['critical_acknowledgment_minutes'] = null;

        $explanation = $this->gates()->staffExplanation();

        $this->assertStringContainsString('2 launch gate(s) fail', $explanation);
        $this->assertStringContainsString('Critical-finding acknowledgment target', $explanation);
        $this->assertStringContainsString('Scan mock mode is off', $explanation);
        $this->assertStringContainsString('Untick', $explanation, 'and how to fix each');
    }

    public function testGatesAreSerialisableForTheDashboard(): void
    {
        foreach ($this->gates()->evaluate() as $gate) {
            $array = $gate->toArray();

            $this->assertSame(
                ['id', 'title', 'passed', 'detail', 'how_to_fix', 'deliberate'],
                array_keys($array)
            );
            $this->assertIsBool($array['passed']);
            $this->assertNotSame('', $array['title']);
        }
    }

    public function testAPassingGateNeedsNoFixInstructions(): void
    {
        // Noise on a green checklist is what makes people stop reading it - and this checklist has
        // to stay readable, because it is the last thing between a misconfiguration and enrolment.
        // Applies to EVERY gate including the deliberate one: once the target is set there is
        // nothing left to do about it.
        foreach ($this->gates()->evaluate() as $gate) {
            if ($gate->passed) {
                $this->assertSame('', $gate->howToFix, "{$gate->id} explains a fix it does not need");
            }
        }
    }
}
