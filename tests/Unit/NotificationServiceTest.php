<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\AuditLogger;
use Stanford\MICA\NotificationPolicy;
use Stanford\MICA\NotificationResult;
use Stanford\MICA\NotificationService as N;
use Stanford\MICA\RoleService;
use Stanford\MICA\SchemaValidator;
use Stanford\MICA\Tests\Support\FakeAuditStore;
use Stanford\MICA\Tests\Support\FakeFindingReviewStore;
use Stanford\MICA\Tests\Support\FakeNotificationChannel;
use Stanford\MICA\Tests\Support\FakeNotificationStore;
use Stanford\MICA\Tests\Support\FakeRecipientDirectory;

/**
 * The exit gate: nothing that reaches a care team, PI, protocol lead, or a formal privacy or
 * model-quality review may be sent for a finding no human has confirmed.
 */
#[CoversClass(N::class)]
final class NotificationServiceTest extends TestCase
{
    private const NOW    = 1_700_000_000;
    private const RECORD = '7';
    private const EVENT  = 42;
    private const PID    = '257';

    private FakeFindingReviewStore $findings;
    private FakeNotificationChannel $channel;
    private FakeNotificationStore $store;
    private FakeRecipientDirectory $directory;
    private FakeAuditStore $auditStore;
    private ArtifactRegistry $artifacts;
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->findings = new FakeFindingReviewStore();
        $this->channel = new FakeNotificationChannel();
        $this->store = new FakeNotificationStore();
        $this->directory = new FakeRecipientDirectory();
        $this->auditStore = new FakeAuditStore();
        $this->artifacts = new ArtifactRegistry();
        $this->validator = new SchemaValidator($this->artifacts);
    }

    /** @param array<string,mixed> $policyOverrides */
    private function service(array $policyOverrides = []): N
    {
        $json = $policyOverrides === []
            ? null
            : json_encode(array_replace_recursive(
                $this->artifacts->getJson('notification_policy_default'),
                $policyOverrides
            ), JSON_THROW_ON_ERROR);

        $policy = NotificationPolicy::fromJson($json, $this->artifacts, $this->validator);

        $roles = new RoleService(
            [RoleService::RA => ['660'], RoleService::PI => ['662']],
            ['ra_alice' => '660', 'pi_bob' => '662']
        );

        return new N(
            $policy,
            $this->channel,
            $this->store,
            $this->directory,
            $this->findings,
            new AuditLogger($this->auditStore, $roles),
            self::PID,
            'https://redcap.example.org/review',
            static fn(): int => self::NOW
        );
    }

    /** @param list<string> $actions */
    private function deliver(array $actions, ?string $actor = 'ra_alice'): array
    {
        return $this->service()->deliverActions(self::RECORD, self::EVENT, 1, $actions, $actor);
    }

    private function confirm(): void
    {
        $this->findings->finding['review_status'] = 'confirmed';
        $this->findings->finding['review_reviewed_at'] = '2026-08-20 09:00:00';
    }

    // ================================================================== THE GATE

    /**
     * @param string $status a status that is not "confirmed"
     */
    #[DataProvider('unconfirmedStatuses')]
    public function testAGatedActionIsRefusedForAnyStatusThatIsNotConfirmed(string $status): void
    {
        $this->findings->finding['review_status'] = $status;

        $out = $this->deliver(['alert_care_team']);

        $this->assertSame(NotificationResult::REFUSED, $out['result']->outcome);
        $this->assertSame(0, $this->channel->count(), 'Something was actually sent.');
    }

    /** @return array<string,array{string}> */
    public static function unconfirmedStatuses(): array
    {
        return [
            'pending'                 => ['pending'],
            'dismissed'               => ['dismissed'],
            'needs a second review'   => ['needs_second_review'],
            'never reviewed at all'   => [''],
            // Present in the workflow schema but unwritable by DispositionService. If an escalate
            // transition is ever added, DELIVERABLE_STATUSES is the one place to revisit - and this
            // row is what will fail to say so.
            'escalated'               => ['escalated'],
            'resolved'                => ['resolved'],
        ];
    }

    #[DataProvider('gatedActions')]
    public function testEveryGatedActionIsGated(string $action): void
    {
        $out = $this->deliver([$action]);

        $this->assertSame(NotificationResult::REFUSED, $out['result']->outcome);
        $this->assertSame(0, $this->channel->count());
    }

    /** @return array<string,array{string}> */
    public static function gatedActions(): array
    {
        return array_combine(
            N::GATED_ACTIONS,
            array_map(static fn(string $a): array => [$a], N::GATED_ACTIONS)
        );
    }

    public function testTheGateReadsTheStoreNotTheCallersIdeaOfTheStatus(): void
    {
        // The signature takes a locator, so there is no finding array to spoof - and the read happens
        // inside. Moving the store to pending after a confirmed read still refuses.
        $service = $this->service();
        $this->confirm();
        $this->findings->finding['review_status'] = 'pending';

        $out = $service->deliverActions(self::RECORD, self::EVENT, 1, ['alert_pi'], 'ra_alice');

        $this->assertSame(NotificationResult::REFUSED, $out['result']->outcome);
    }

    public function testARefusalNamesTheStatusAndTheRuleWithoutJargon(): void
    {
        $out = $this->deliver(['alert_care_team', 'alert_pi']);

        $reason = $out['result']->reason;
        $this->assertStringContainsString('"pending", not confirmed', $reason);
        $this->assertStringContainsString('alert_care_team', $reason);
        $this->assertStringContainsString('alert_pi', $reason);
        $this->assertStringContainsString('until a human has confirmed', $reason);
    }

    public function testAFindingWithNoStatusAtAllReadsAsNeverReviewedRatherThanAsEmpty(): void
    {
        // '' is what an instance that was written but never opened looks like. "is "", not confirmed"
        // would read as a bug in the message rather than a fact about the finding.
        $this->findings->finding['review_status'] = '';

        $this->assertStringContainsString(
            'not yet reviewed',
            $this->deliver(['alert_pi'])['result']->reason
        );
    }

    public function testARefusalIsRecordedBecauseASilentGateCannotBeShownToHaveFired(): void
    {
        $this->deliver(['alert_care_team']);

        $refused = $this->store->withStatus(NotificationResult::REFUSED);
        $this->assertCount(1, $refused);
        $this->assertSame(self::RECORD, $refused[0]['record']);
        $this->assertSame(0, $refused[0]['recipient_count']);
        $this->assertStringContainsString('not confirmed', $refused[0]['error']);
    }

    public function testARefusalIsAudited(): void
    {
        $this->deliver(['alert_pi'], 'ra_alice');

        $events = array_values(array_filter(
            $this->auditStore->events,
            static fn(array $e): bool => $e['event_type'] === AuditLogger::ACCESS_DENIED
        ));

        $this->assertCount(1, $events);
        $this->assertSame('ra_alice', $events[0]['actor']);
        $this->assertSame('submitAction', $events[0]['details']['denied_action']);
    }

    public function testAMixOfGatedAndUngatedActionsRefusesTheWholeCall(): void
    {
        // No partial delivery: a screen that says some of what you clicked happened, without saying
        // which, is worse than a plain refusal.
        $out = $this->deliver(['document_no_action', 'alert_care_team']);

        $this->assertSame(NotificationResult::REFUSED, $out['result']->outcome);
        $this->assertSame([], $out['per_action']);
        $this->assertSame(0, $this->channel->count());
    }

    // ================================================================== what is NOT gated

    public function testDocumentNoActionWorksOnAnUnconfirmedFindingBecauseScanFailuresNeedIt(): void
    {
        // A scan_failure placeholder can never be a confirmed model finding - it exists because the
        // scan did not run. Staff must still be able to record that they handled it manually.
        $this->findings->finding['finding_concern_type'] = 'scan_failure';

        $out = $this->deliver(['document_no_action']);

        $this->assertNotSame(NotificationResult::REFUSED, $out['result']->outcome);
        $this->assertSame(NotificationResult::SKIPPED, $out['per_action']['document_no_action']->outcome);
        $this->assertSame(0, $this->channel->count(), 'Bookkeeping should deliver to nobody.');
        $this->assertSame('1', $out['result']->actionFields['action_types___document_no_action']);
    }

    public function testRequestingASecondReviewerWorksBeforeConfirmationByDefinition(): void
    {
        // needs_second_review IS a disposition, so gating it on confirmation would make it
        // unreachable at exactly the moment it is meant to be used.
        $out = $this->deliver(['second_reviewer']);

        $this->assertTrue($out['per_action']['second_reviewer']->wasSent());
        $this->assertSame(
            ['ra@example.org', 'ra2@example.org'],
            $this->channel->last()['recipients'],
            'A second reviewer request goes to the reviewer role, not outside the review team.'
        );
    }

    public function testTheGatedAndUngatedSetsTogetherCoverEveryActionTheInstrumentOffers(): void
    {
        // If a choice is added to action_types and not classified here, deliverActions() throws on it
        // rather than quietly treating it as ungated. This asserts the classification is complete
        // against the instrument as applied.
        $csv = __DIR__ . '/../../docs/phase-3-handoff/dictionary/mica_safety_finding.csv';
        $this->assertFileExists($csv);

        $choices = '';
        $handle = fopen($csv, 'r');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (($row[0] ?? '') === 'action_types') {
                $choices = $row[5] ?? '';
            }
        }
        fclose($handle);

        $codes = array_map(
            static fn(string $pair): string => trim(explode(',', $pair, 2)[0]),
            explode('|', $choices)
        );

        $this->assertNotSame([''], $codes);
        $this->assertSame(
            [],
            array_diff($codes, array_merge(N::GATED_ACTIONS, N::UNGATED_ACTIONS)),
            'An action_types choice is not classified as gated or ungated.'
        );
    }

    // ================================================================== delivery

    public function testAConfirmedFindingDelivers(): void
    {
        $this->confirm();

        $out = $this->deliver(['alert_care_team']);

        $this->assertTrue($out['result']->wasSent());
        $this->assertSame(1, $this->channel->count());
        $this->assertSame(['care@example.org'], $this->channel->last()['recipients']);
        $this->assertSame('sent', $out['result']->actionFields['action_delivery_status']);
    }

    public function testTheLockVersionSeenAtSendTimeIsRecordedOnTheRow(): void
    {
        // An email cannot be recalled. The honest artifact is evidence that the finding was confirmed
        // at version N when the notice went out.
        $this->confirm();
        $this->findings->finding['review_lock_version'] = '3';

        $this->deliver(['alert_pi']);

        $this->assertSame(3, $this->store->last()['lock_version']);
    }

    public function testTheBodyIsHashedNotStored(): void
    {
        $this->confirm();
        $this->deliver(['alert_pi']);

        $row = $this->store->last();
        $this->assertArrayNotHasKey('body', $row);
        $this->assertSame(hash('sha256', $this->channel->last()['body']), $row['body_sha256']);
        $this->assertGreaterThan(0, $row['body_bytes']);
    }

    public function testEachActionGoesToItsOwnRole(): void
    {
        $this->confirm();

        $this->deliver(['alert_care_team', 'alert_pi', 'alert_protocol_lead']);

        $byRecipient = [];
        foreach ($this->channel->sent as $sent) {
            $byRecipient[] = $sent['recipients'][0];
        }

        $this->assertSame(['care@example.org', 'pi@example.org', 'protocol@example.org'], $byRecipient);
    }

    public function testAnUnknownActionTypeIsAProgrammingErrorNotASilentDrop(): void
    {
        $this->confirm();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('alert_the_press');

        $this->deliver(['alert_the_press']);
    }

    public function testSelectingNothingIsASkipNotAnError(): void
    {
        $out = $this->deliver([]);

        $this->assertSame(NotificationResult::SKIPPED, $out['result']->outcome);
        $this->assertSame(0, $this->channel->count());
    }

    public function testADeletedFindingIsRefusedWithAnInstructionToReload(): void
    {
        $this->findings->missing = true;

        $out = $this->deliver(['document_no_action']);

        $this->assertSame(NotificationResult::REFUSED, $out['result']->outcome);
        $this->assertStringContainsString('reload the queue', $out['result']->reason);
    }

    // ================================================================== idempotency

    public function testTheSameActionIsNotDeliveredTwiceAtTheSameVersion(): void
    {
        $this->confirm();
        $service = $this->service();

        $service->deliverActions(self::RECORD, self::EVENT, 1, ['alert_pi'], 'ra_alice');
        $second = $service->deliverActions(self::RECORD, self::EVENT, 1, ['alert_pi'], 'ra_alice');

        $this->assertSame(1, $this->channel->count());
        $this->assertSame(NotificationResult::SKIPPED, $second['per_action']['alert_pi']->outcome);
        $this->assertStringContainsString('already sent', $second['per_action']['alert_pi']->reason);
    }

    public function testACorrectedAndReConfirmedFindingIsDeliveredAgain(): void
    {
        // The reviewer changed their mind about the urgency. The care team should hear the corrected
        // disposition, not have it suppressed as a duplicate.
        $this->confirm();
        $service = $this->service();
        $service->deliverActions(self::RECORD, self::EVENT, 1, ['alert_care_team'], 'ra_alice');

        $this->findings->finding['review_lock_version'] = '1';
        $this->findings->finding['review_corrected_urgency'] = 'critical';
        $service->deliverActions(self::RECORD, self::EVENT, 1, ['alert_care_team'], 'ra_alice');

        $this->assertSame(2, $this->channel->count());
        $this->assertStringContainsString('critical', $this->channel->last()['subject']);
    }

    public function testAFailedAttemptDoesNotBlockARetry(): void
    {
        $this->confirm();
        $this->channel->throwOn = 'SMTP unavailable';
        $service = $this->service();

        $first = $service->deliverActions(self::RECORD, self::EVENT, 1, ['alert_pi'], 'ra_alice');
        $this->assertSame(NotificationResult::FAILED, $first['result']->outcome);

        $this->channel->throwOn = null;
        $second = $service->deliverActions(self::RECORD, self::EVENT, 1, ['alert_pi'], 'ra_alice');

        $this->assertTrue($second['per_action']['alert_pi']->wasSent());
    }

    // ================================================================== failure is recorded

    public function testATransportFailureIsRecordedRatherThanThrown(): void
    {
        // A mail server being down must not take down the path that triggered the notice.
        $this->confirm();
        $this->channel->throwOn = 'connection refused';

        $out = $this->deliver(['alert_pi']);

        $this->assertSame(NotificationResult::FAILED, $out['result']->outcome);
        $this->assertSame('failed', $out['result']->actionFields['action_delivery_status'] ?? null);
        $failed = $this->store->withStatus(NotificationResult::FAILED);
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('connection refused', $failed[0]['error']);
    }

    public function testAConfirmedFindingWithNowhereToGoIsAFailureNotASuccessToZeroPeople(): void
    {
        $this->confirm();
        $this->directory->byRole['care_team'] = [];

        $out = $this->deliver(['alert_care_team']);

        $this->assertSame(NotificationResult::FAILED, $out['result']->outcome);
        $this->assertStringContainsString(
            'silent failure',
            $this->store->withStatus(NotificationResult::FAILED)[0]['error']
        );
    }

    public function testAChannelTheDeploymentCannotDeliverIsRecordedAsAFailure(): void
    {
        $this->confirm();
        $this->channel->supported = ['dashboard'];

        $out = $this->deliver(['alert_pi']);

        $this->assertSame(NotificationResult::FAILED, $out['result']->outcome);
        $this->assertStringContainsString(
            'cannot deliver',
            $this->store->withStatus(NotificationResult::FAILED)[0]['error']
        );
    }

    // ================================================================== reviewers ready

    public function testReviewersAreToldWhenFindingsAreReady(): void
    {
        $result = $this->service()
            ->notifyReviewersReady(11, self::RECORD, self::EVENT, 1, 'baseline', 3, 'high');

        $this->assertTrue($result->wasSent());
        $this->assertStringContainsString('3 SafetyScan finding(s)', $this->channel->last()['subject']);
        $this->assertStringContainsString('high', $this->channel->last()['subject']);
        $this->assertStringContainsString(
            'nothing will be until you confirm',
            $this->channel->last()['body'],
            'The RA-first rule should be stated to the RA.'
        );
    }

    public function testARetriedScanDoesNotSendASecondFindingsReadyEmail(): void
    {
        // Re-entry into ready_for_review is a live path: a transient scan failure retries with
        // backoff. Without the idempotency key that is another email about the same session.
        $service = $this->service();
        $service->notifyReviewersReady(11, self::RECORD, self::EVENT, 1, 'baseline', 3, 'high');
        $second = $service->notifyReviewersReady(11, self::RECORD, self::EVENT, 1, 'baseline', 3, 'high');

        $this->assertSame(1, $this->channel->count());
        $this->assertSame(NotificationResult::SKIPPED, $second->outcome);
        $this->assertStringContainsString('Already sent for job 11', $second->reason);
    }

    public function testADifferentSessionStillGetsItsOwnEmail(): void
    {
        $service = $this->service();
        $service->notifyReviewersReady(11, self::RECORD, self::EVENT, 1, 'baseline', 1, 'high');
        $service->notifyReviewersReady(12, '8', self::EVENT, 1, 'baseline', 1, 'high');

        $this->assertSame(2, $this->channel->count());
    }

    public function testAFailedScanSaysTheSessionWasNotScreenedAtAll(): void
    {
        // "No findings" and "never checked" must not read the same. A failed scan is not an all-clear.
        $this->service()
            ->notifyReviewersReady(11, self::RECORD, self::EVENT, 1, 'baseline', 0, 'none', true);

        $this->assertStringContainsString('could not be screened', $this->channel->last()['subject']);
        $this->assertStringContainsString('not an all-clear', $this->channel->last()['body']);
        $this->assertStringContainsString('unscreened', $this->channel->last()['body']);
    }

    // ================================================================== pre-review

    public function testPreReviewNotificationIsOffByDefaultAndSaysWhy(): void
    {
        $result = $this->service()
            ->notifyPreReview(self::RECORD, self::EVENT, 1, 'f1', 'self_harm', 'critical');

        $this->assertSame(NotificationResult::SKIPPED, $result->outcome);
        $this->assertStringContainsString('before a human has confirmed', $result->reason);
        $this->assertSame(0, $this->channel->count());
    }

    public function testPreReviewRespectsTheEligibleUrgencyList(): void
    {
        $service = $this->service(['pre_review_notifications' => [
            'enabled'            => true,
            'eligible_urgencies' => ['critical'],
            'recipient_roles'    => ['on_call_research_staff'],
        ]]);

        $this->assertSame(
            NotificationResult::SKIPPED,
            $service->notifyPreReview(self::RECORD, self::EVENT, 1, 'f1', 'self_harm', 'high')->outcome
        );
        $this->assertTrue(
            $service->notifyPreReview(self::RECORD, self::EVENT, 1, 'f1', 'self_harm', 'critical')->wasSent()
        );
    }

    public function testNoPreReviewBodyCanBeProducedWithoutTheRequiredLabel(): void
    {
        // The constant is only a guarantee if every path through the builder carries it. The reader
        // here is the person least able to check whether the model was right.
        $service = $this->service(['pre_review_notifications' => [
            'enabled'            => true,
            'eligible_urgencies' => ['high', 'critical'],
            'recipient_roles'    => ['care_team', 'principal_investigator'],
        ]]);

        foreach (['high', 'critical'] as $urgency) {
            foreach (['self_harm', 'violence', 'other'] as $concern) {
                $this->channel->sent = [];
                $this->store->rows = [];

                $service->notifyPreReview(self::RECORD, self::EVENT, 1, "f-$urgency-$concern", $concern, $urgency);

                $body = $this->channel->last()['body'];
                $this->assertStringStartsWith(
                    strtoupper(NotificationPolicy::REQUIRED_PRE_REVIEW_LABEL),
                    $body
                );
                $this->assertStringContainsString('has NOT yet reviewed', $body);
                $this->assertStringContainsString('UNVERIFIED', $this->channel->last()['subject']);
            }
        }
    }

    public function testPreReviewEnabledWithNoRecipientsIsAFailureWithClinicalWeight(): void
    {
        $service = $this->service(['pre_review_notifications' => [
            'enabled'            => true,
            'eligible_urgencies' => ['critical'],
            'recipient_roles'    => ['care_team'],
        ]]);
        $this->directory->byRole['care_team'] = [];

        $result = $service->notifyPreReview(self::RECORD, self::EVENT, 1, 'f1', 'self_harm', 'critical');

        $this->assertSame(NotificationResult::FAILED, $result->outcome);
        $this->assertStringContainsString('no address resolves', $result->reason);
    }

    // ================================================================== minimum necessary

    public function testANotificationBodyCarriesNoParticipantWordsOrReviewerFreeText(): void
    {
        $this->confirm();
        $this->findings->finding['finding_summary'] = 'participant said I want to end it all';
        $this->findings->finding['review_rationale'] = 'she described a plan involving pills';
        $this->findings->finding['review_notes'] = 'called her mother';
        $this->findings->finding['finding_evidence_json'] = '[{"quote":"I want to end it all"}]';

        $this->deliver(['alert_care_team']);

        $body = $this->channel->last()['body'];
        foreach (['end it all', 'pills', 'called her mother', 'participant said'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "\"$leak\" reached a notification body.");
        }

        // What it does carry: enough to act on, and where the rest lives.
        $this->assertStringContainsString('Record: 7', $body);
        $this->assertStringContainsString('self_harm', $body);
        $this->assertStringContainsString('https://redcap.example.org/review', $body);
    }

    public function testASubjectCanOnlyCarryCountsAndEnumMembers(): void
    {
        // A subject travels through mail logs and phone lock screens. Anything unrecognised becomes
        // "unspecified" rather than being interpolated.
        $this->service()->notifyReviewersReady(
            11,
            self::RECORD,
            self::EVENT,
            1,
            'MRN 12345678 / Jane Doe',
            2,
            'she said she wanted to die'
        );

        $subject = $this->channel->last()['subject'];
        $this->assertStringNotContainsString('12345678', $subject);
        $this->assertStringNotContainsString('Jane Doe', $subject);
        $this->assertStringNotContainsString('wanted to die', $subject);
        $this->assertStringContainsString(N::UNSPECIFIED, $subject);
        $this->assertStringContainsString('2 SafetyScan finding(s)', $subject);
    }

    public function testACorrectedUrgencyAndConcernTakePrecedenceInTheNotice(): void
    {
        // The reviewer overruled the model. The care team must hear the reviewer's judgment.
        $this->confirm();
        $this->findings->finding['review_corrected_urgency'] = 'moderate';
        $this->findings->finding['review_corrected_concern_type'] = 'severe_withdrawal';

        $this->deliver(['alert_care_team']);

        $this->assertStringContainsString('moderate', $this->channel->last()['subject']);
        $this->assertStringContainsString('severe_withdrawal', $this->channel->last()['body']);
        $this->assertStringNotContainsString('critical', $this->channel->last()['subject']);
    }

    // ================================================================== digests

    public function testNoEnabledDigestIsASkip(): void
    {
        $results = $this->service()->sendDigests('daily', self::NOW - 86400);

        $this->assertSame(NotificationResult::SKIPPED, $results[0]->outcome);
        $this->assertSame(0, $this->channel->count());
    }

    public function testADigestCarriesCountsAndDropsAnythingElseTheStoreReturns(): void
    {
        // The guarded failure mode is a record id reaching a digest, which goes to a wider audience
        // than the dashboard.
        $this->store->counts = [
            'pending_review'   => 4,
            'urgency_critical' => 1,
            'record_12'        => 1,
            'participant_note' => 'she mentioned pills',
        ];

        $results = $this->service($this->dailyDigest())->sendDigests('daily', self::NOW - 86400);

        $this->assertTrue($results[0]->wasSent());
        $body = $this->channel->last()['body'];
        $this->assertStringContainsString('pending review:', $body);
        $this->assertStringContainsString('4', $body);
        $this->assertStringNotContainsString('record_12', $body);
        $this->assertStringNotContainsString('pills', $body);
        $this->assertStringContainsString('no participant-level detail', $body);
    }

    public function testEveryDigestBucketAppearsEvenWhenZero(): void
    {
        // A missing line reads as "not measured"; a zero reads as "measured, none". On a safety
        // digest those are different claims.
        $this->store->counts = [];

        $this->service($this->dailyDigest())->sendDigests('daily', self::NOW - 86400);

        $body = $this->channel->last()['body'];
        foreach (N::DIGEST_BUCKETS as $bucket) {
            $this->assertStringContainsString(str_replace('_', ' ', $bucket) . ':', $body);
        }
    }

    public function testTheSameDigestWindowIsNotSentTwice(): void
    {
        $service = $this->service($this->dailyDigest());
        $service->sendDigests('daily', self::NOW - 86400, self::NOW);
        $second = $service->sendDigests('daily', self::NOW - 86400, self::NOW);

        $this->assertSame(1, $this->channel->count());
        $this->assertSame(NotificationResult::SKIPPED, $second[0]->outcome);
    }

    /** @return array<string,mixed> */
    private function dailyDigest(): array
    {
        return ['digests' => [[
            'digest_id'                        => 'ra_daily',
            'enabled'                          => true,
            'cadence'                          => 'daily',
            'recipient_roles'                  => ['research_assistant'],
            'delivery_channel'                 => 'secure_email',
            'include_participant_level_detail' => false,
        ]]];
    }

    // ================================================================== acknowledgment monitor

    public function testNoAcknowledgmentTargetMeansNoMonitoringAndSaysSoPlainly(): void
    {
        // The shipped state, and LaunchReadiness's deliberate blocker. Silence here would be the
        // wrong outcome: the gate is what makes the undecided target visible.
        $this->store->overdue = [['record' => '7', 'instance' => 1, 'urgency' => 'critical']];

        $results = $this->service()->notifyOverdueAcknowledgments();

        $this->assertSame(NotificationResult::SKIPPED, $results[0]->outcome);
        $this->assertStringContainsString('launch blocker', $results[0]->reason);
        $this->assertSame(0, $this->channel->count());
    }

    public function testOverdueFindingsAreChasedOnceTheTargetIsSet(): void
    {
        $this->store->overdue = [
            ['record' => '7', 'instance' => 1, 'urgency' => 'critical', 'notified_at' => self::NOW - 7200],
            ['record' => '8', 'instance' => 2, 'urgency' => 'high', 'notified_at' => self::NOW - 9000],
        ];

        $results = $this->service(['ra_review_policy' => ['critical_acknowledgment_minutes' => 60]])
            ->notifyOverdueAcknowledgments();

        $this->assertTrue($results[0]->wasSent());
        $this->assertStringContainsString('2 finding(s) past the 60-minute', $this->channel->last()['subject']);
        $this->assertStringContainsString('record 7', $this->channel->last()['body']);
    }

    public function testNothingOverdueIsASkipNotAnEmptyEmail(): void
    {
        $results = $this->service(['ra_review_policy' => ['critical_acknowledgment_minutes' => 60]])
            ->notifyOverdueAcknowledgments();

        $this->assertSame(NotificationResult::SKIPPED, $results[0]->outcome);
        $this->assertSame(0, $this->channel->count());
    }

    // ================================================================== bookkeeping

    public function testTheActionFieldsRecordTheDecisionForEveryActionChosen(): void
    {
        $this->confirm();

        $fields = $this->deliver(['alert_pi', 'document_no_action'])['result']->actionFields;

        $this->assertSame('1', $fields['action_types___alert_pi']);
        $this->assertSame('1', $fields['action_types___document_no_action']);
        $this->assertSame('ra_alice', $fields['action_initiated_by']);
        $this->assertSame('sent', $fields['action_delivery_status']);
        $this->assertStringContainsString('alert_pi: sent', $fields['action_payload_min']);
        $this->assertStringContainsString('document_no_action: skipped', $fields['action_payload_min']);
    }

    public function testBookkeepingOnlyActionsAreNotApplicableRatherThanSent(): void
    {
        $fields = $this->deliver(['document_no_action'])['result']->actionFields;

        $this->assertSame('not_applicable', $fields['action_delivery_status']);
    }

    public function testDeliveryIsAudited(): void
    {
        $this->confirm();
        $this->deliver(['alert_pi']);

        $events = array_values(array_filter(
            $this->auditStore->events,
            static fn(array $e): bool => $e['event_type'] === AuditLogger::ACTION_SENT
        ));

        $this->assertCount(1, $events);
        $this->assertSame('confirmed', $events[0]['details']['review_status']);
        $this->assertSame(1, $events[0]['details']['recipient_count']);
    }

    public function testIdempotencyKeysAreScopedToTheProject(): void
    {
        // Two projects on one REDCap must not suppress each other's notices.
        $a = $this->service()->idempotencyKey(N::REVIEWERS_READY, '11');

        $other = new N(
            NotificationPolicy::fromJson(null, $this->artifacts, $this->validator),
            $this->channel,
            $this->store,
            $this->directory,
            $this->findings,
            new AuditLogger($this->auditStore, new RoleService([])),
            '999',
            '',
            static fn(): int => self::NOW
        );

        $this->assertNotSame($a, $other->idempotencyKey(N::REVIEWERS_READY, '11'));
    }
}
