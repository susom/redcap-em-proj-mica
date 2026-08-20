<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\NotificationPolicy;
use Stanford\MICA\SchemaValidator;

/**
 * The policy has to fail closed. A malformed one must not be partly applied.
 */
#[CoversClass(NotificationPolicy::class)]
final class NotificationPolicyTest extends TestCase
{
    private ArtifactRegistry $artifacts;
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->artifacts = new ArtifactRegistry();
        $this->validator = new SchemaValidator($this->artifacts);
    }

    private function load(?string $json): NotificationPolicy
    {
        return NotificationPolicy::fromJson($json, $this->artifacts, $this->validator);
    }

    /** @param array<string,mixed> $overrides */
    private function json(array $overrides): string
    {
        return json_encode(array_replace_recursive(
            $this->artifacts->getJson('notification_policy_default'),
            $overrides
        ), JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------ the default

    public function testAnUnsetSettingUsesTheVendoredDefaultAndIsNotAnError(): void
    {
        // Shipping the handoff's default is the intended out-of-the-box state, not a misconfiguration.
        foreach ([null, '', '   '] as $blank) {
            $policy = $this->load($blank);

            $this->assertSame([], $policy->errors());
            $this->assertFalse($policy->usedFallback());
            $this->assertSame('1.0', $policy->toArray()['schema_version']);
        }
    }

    public function testTheShippedDefaultIsTheConservativeOne(): void
    {
        $policy = $this->load(null);

        // The deliberate launch blocker.
        $this->assertNull($policy->criticalAcknowledgmentMinutes());
        // Nothing reaches a care team or PI before a human confirms.
        $this->assertFalse($policy->preReviewEnabled());
        $this->assertSame([], $policy->digests());
        // And the one clause that cannot be turned off.
        $this->assertTrue($policy->notifyReviewersWhenReady());
    }

    // ------------------------------------------------------------------ failing closed

    public function testUnparseableJsonFallsBackToTheDefaultRatherThanToNothing(): void
    {
        $policy = $this->load('{not json');

        $this->assertTrue($policy->usedFallback());
        $this->assertStringContainsString('not valid JSON', $policy->errors()[0]);
        // The point: it is the *default* that is in force, not an empty policy.
        $this->assertTrue($policy->notifyReviewersWhenReady());
        $this->assertFalse($policy->preReviewEnabled());
    }

    public function testAPolicyThatFailsItsSchemaIsDiscardedWholeNotFieldByField(): void
    {
        // Valid acknowledgment window, but pre_review_notifications is missing its required keys.
        $policy = $this->load(json_encode([
            'schema_version'           => '1.0',
            'ra_review_policy'         => [
                'critical_acknowledgment_minutes' => 30,
                'notify_assigned_ra_when_ready'   => true,
            ],
            'pre_review_notifications' => ['enabled' => true],
            'digests'                  => [],
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($policy->usedFallback());
        $this->assertNotSame([], $policy->errors());
        // Neither half of the broken policy survived - not the 30 minutes it got right, and
        // emphatically not the `enabled: true` it got wrong.
        $this->assertNull($policy->criticalAcknowledgmentMinutes());
        $this->assertFalse($policy->preReviewEnabled());
    }

    public function testAPolicyTryingToTurnOffReviewerNoticesIsRejected(): void
    {
        // `notify_assigned_ra_when_ready` is a const in the schema: a finding that reaches a queue
        // nobody is told about is a finding nobody reviews.
        $policy = $this->load($this->json([
            'ra_review_policy' => ['notify_assigned_ra_when_ready' => false],
        ]));

        $this->assertTrue($policy->usedFallback());
        $this->assertTrue($policy->notifyReviewersWhenReady());
    }

    public function testAPolicyWithASofterPreReviewLabelIsRejected(): void
    {
        $policy = $this->load($this->json([
            'pre_review_notifications' => ['required_label' => 'Possible concern identified'],
        ]));

        $this->assertTrue($policy->usedFallback());
        $this->assertSame(NotificationPolicy::REQUIRED_PRE_REVIEW_LABEL, $policy->preReviewLabel());
    }

    public function testTheLabelIsTheConstantEvenIfAValidPolicyIsInForce(): void
    {
        // Belt and braces: the accessor never reads the label out of the policy data at all.
        $policy = $this->load($this->json(['pre_review_notifications' => ['enabled' => true]]));

        $this->assertFalse($policy->usedFallback());
        $this->assertSame(
            'Unverified automated SafetyScan finding pending human review',
            $policy->preReviewLabel()
        );
    }

    // ------------------------------------------------------------------ accessors

    public function testAValidPolicyIsUsedAsGiven(): void
    {
        $policy = $this->load($this->json([
            'ra_review_policy'         => ['critical_acknowledgment_minutes' => 45],
            'pre_review_notifications' => [
                'enabled'            => true,
                'eligible_urgencies' => ['critical', 'high'],
                'recipient_roles'    => ['on_call_research_staff'],
            ],
        ]));

        $this->assertSame([], $policy->errors());
        $this->assertFalse($policy->usedFallback());
        $this->assertSame(45, $policy->criticalAcknowledgmentMinutes());
        $this->assertTrue($policy->preReviewEnabled());
        $this->assertSame(['critical', 'high'], $policy->preReviewUrgencies());
        $this->assertSame(['on_call_research_staff'], $policy->preReviewRecipientRoles());
    }

    public function testDisabledDigestsAreNotReturned(): void
    {
        $policy = $this->load($this->json([
            'digests' => [
                ['digest_id' => 'on', 'enabled' => true, 'cadence' => 'daily',
                    'recipient_roles' => ['research_assistant'], 'delivery_channel' => 'secure_email',
                    'include_participant_level_detail' => false],
                ['digest_id' => 'off', 'enabled' => false, 'cadence' => 'weekly',
                    'recipient_roles' => ['principal_investigator'], 'delivery_channel' => 'secure_email',
                    'include_participant_level_detail' => false],
            ],
        ]));

        $this->assertFalse($policy->usedFallback());
        $this->assertCount(1, $policy->digests());
        $this->assertSame('on', $policy->digests()[0]['digest_id']);
        $this->assertCount(1, $policy->digestsFor('daily'));
        $this->assertSame([], $policy->digestsFor('weekly'));
    }

    public function testADigestAskingForParticipantDetailIsRejectedAndTheAnswerStaysNo(): void
    {
        $policy = $this->load($this->json([
            'digests' => [
                ['digest_id' => 'greedy', 'enabled' => true, 'cadence' => 'daily',
                    'recipient_roles' => ['principal_investigator'], 'delivery_channel' => 'secure_email',
                    'include_participant_level_detail' => true],
            ],
        ]));

        $this->assertTrue($policy->usedFallback());
        $this->assertFalse($policy->digestsMayIncludeParticipantDetail());
    }
}
