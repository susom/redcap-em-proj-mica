<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ArtifactRegistry;
use Stanford\MICA\AuditLogger;
use Stanford\MICA\DispositionService;
use Stanford\MICA\LaunchReadiness;
use Stanford\MICA\NotificationPolicy;
use Stanford\MICA\NotificationService;
use Stanford\MICA\ReviewEndpoints;
use Stanford\MICA\RoleService;
use Stanford\MICA\SchemaValidator;
use Stanford\MICA\Tests\Support\FakeActionFieldWriter;
use Stanford\MICA\Tests\Support\FakeAuditStore;
use Stanford\MICA\Tests\Support\FakeFindingReviewStore;
use Stanford\MICA\Tests\Support\FakeLaunchEnvironment;
use Stanford\MICA\Tests\Support\FakeNotificationChannel;
use Stanford\MICA\Tests\Support\FakeNotificationStore;
use Stanford\MICA\Tests\Support\FakeRecipientDirectory;
use Stanford\MICA\Tests\Support\FakeReviewQueryStore;

#[CoversClass(ReviewEndpoints::class)]
final class ReviewEndpointsTest extends TestCase
{
    private FakeReviewQueryStore $store;
    private FakeAuditStore $audit;
    private FakeFindingReviewStore $findings;
    private FakeNotificationChannel $channel;
    private FakeActionFieldWriter $actionWriter;
    private ?NotificationService $notifications = null;

    protected function setUp(): void
    {
        $this->store = new FakeReviewQueryStore();
        $this->audit = new FakeAuditStore();
        $this->findings = new FakeFindingReviewStore();
        $this->channel = new FakeNotificationChannel();
        $this->actionWriter = new FakeActionFieldWriter();
    }

    private function endpoints(): ReviewEndpoints
    {
        // REDCap role ids, plus the project's roster - see RoleService: access follows the user's
        // REDCap role, not a list of usernames in a module setting.
        $roles = new RoleService(
            [RoleService::RA => ['660'], RoleService::PI => ['662'], RoleService::AUDITOR => ['663']],
            ['ra_alice' => '660', 'pi_bob' => '662', 'auditor_carol' => '663']
        );
        $audit = new AuditLogger($this->audit, $roles, '257');

        return new ReviewEndpoints(
            $this->store,
            new DispositionService($this->findings, $roles, $audit),
            $roles,
            $audit,
            '257'
        );
    }

    // ------------------------------------------------------------------ access control

    #[DataProvider('deniedCombinations')]
    public function testEveryDeniedCombinationReturns403(string $user, string $action): void
    {
        $response = $this->endpoints()->handle($action, $user, []);

        $this->assertFalse($response['ok']);
        $this->assertSame(403, $response['status']);
    }

    public static function deniedCombinations(): array
    {
        $cases = [];
        $users = [RoleService::RA => 'ra_alice', RoleService::PI => 'pi_bob', RoleService::AUDITOR => 'auditor_carol'];

        foreach (RoleService::MATRIX as $action => $allowed) {
            foreach ($users as $role => $user) {
                if (!in_array($role, $allowed, true)) {
                    $cases["$role cannot $action"] = [$user, $action];
                }
            }
        }
        $cases['stranger cannot reviewQueue'] = ['stranger', 'reviewQueue'];

        return $cases;
    }

    public function testADeniedRequestIsAudited(): void
    {
        // An access-control system that records only what it allowed answers the wrong question:
        // "who tried" is what an auditor actually needs.
        $this->endpoints()->handle('submitDisposition', 'auditor_carol', []);

        $event = $this->audit->last();

        $this->assertSame(AuditLogger::ACCESS_DENIED, $event['event_type']);
        $this->assertSame('auditor_carol', $event['actor']);
        $this->assertSame('submitDisposition', $event['details']['denied_action']);
    }

    public function testADeniedRequestDoesNoWork(): void
    {
        $this->store->queue = [['finding_id' => 'a']];

        $response = $this->endpoints()->handle('reviewQueue', 'stranger', []);

        $this->assertArrayNotHasKey('queue', $response, 'no data may leak with the refusal');
    }

    public function testAnUnknownActionIsRefusedNotExecuted(): void
    {
        $response = $this->endpoints()->handle('dropTables', 'pi_bob', []);

        $this->assertFalse($response['ok']);
        $this->assertSame(403, $response['status'], 'unlisted actions are denied by the matrix');
    }

    // ------------------------------------------------------------------ queue

    public function testTheQueueIsSortedAndSummarised(): void
    {
        $this->store->queue = [
            ['finding_id' => 'q', 'finding_urgency' => 'quality', 'created' => 1],
            ['finding_id' => 'c', 'finding_urgency' => 'critical', 'created' => 2],
        ];

        $response = $this->endpoints()->handle('reviewQueue', 'ra_alice', []);

        $this->assertSame(['c', 'q'], array_column($response['queue'], 'finding_id'));
        $this->assertSame(2, $response['summary']['total']);
        $this->assertSame(1, $response['summary']['critical']);
    }

    public function testQueueFiltersAreApplied(): void
    {
        $this->store->queue = [
            ['finding_id' => 'a', 'finding_urgency' => 'critical', 'created' => 1],
            ['finding_id' => 'b', 'finding_urgency' => 'quality', 'created' => 2],
        ];

        $response = $this->endpoints()->handle('reviewQueue', 'ra_alice', [
            'filters' => ['urgency' => 'critical'],
        ]);

        $this->assertSame(['a'], array_column($response['queue'], 'finding_id'));
    }

    public function testAQueueViewIsAuditedWithACountNotWithContent(): void
    {
        $this->store->queue = [['finding_id' => 'a', 'created' => 1]];

        $this->endpoints()->handle('reviewQueue', 'ra_alice', ['filters' => ['urgency' => 'critical']]);

        $details = $this->audit->last()['details'];

        $this->assertSame(AuditLogger::QUEUE_VIEW, $this->audit->last()['event_type']);
        $this->assertSame(0, $details['result_count'], 'the filtered count');
        $this->assertSame(['urgency'], $details['filters'], 'which filters, not what they matched');
    }

    public function testAnUnparseableFilterDateIsA400NotA500(): void
    {
        $response = $this->endpoints()->handle('reviewQueue', 'ra_alice', [
            'filters' => ['from' => 'whenever'],
        ]);

        $this->assertFalse($response['ok']);
        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('not a date', $response['error']);
    }

    // ------------------------------------------------------------------ session

    public function testASessionCarriesTheJobStatusBesideTheRunStatus(): void
    {
        // The one place run_status alone misleads: an `ok` run under a job in
        // manual_review_required is a scan that worked and a release that did not.
        $this->store->session = [
            'transcript_ref' => 'T900',
            'run_status'     => 'ok',
            'job_status'     => 'manual_review_required',
            'job_last_error' => 'the review instrument does not exist',
            'findings'       => [],
            'messages'       => [],
        ];

        $session = $this->endpoints()->handle('reviewSession', 'ra_alice', [
            'record'   => '2',
            'event_id' => 1008,
        ])['session'];

        $this->assertSame('ok', $session['run_status']);
        $this->assertSame('manual_review_required', $session['job_status']);
        $this->assertNotSame('', $session['job_last_error'], 'and why');
    }

    public function testASessionViewIsAuditedByTranscriptRef(): void
    {
        // By ref, so the audit row says which VERSION of the transcript was read.
        $this->store->session = ['transcript_ref' => 'T900', 'findings' => [['finding_id' => 'a']]];

        $this->endpoints()->handle('reviewSession', 'ra_alice', ['record' => '2', 'event_id' => 1008]);

        $details = $this->audit->last()['details'];

        $this->assertSame(AuditLogger::SESSION_VIEW, $this->audit->last()['event_type']);
        $this->assertSame('T900', $details['transcript_ref']);
        $this->assertSame(1, $details['finding_count']);
    }

    public function testASessionRequestNeedsARecordAndAnEvent(): void
    {
        foreach ([[], ['record' => '2'], ['event_id' => 1008]] as $payload) {
            $response = $this->endpoints()->handle('reviewSession', 'ra_alice', $payload);
            $this->assertSame(400, $response['status']);
        }
    }

    public function testAMissingSessionIsA400WithAnExplanation(): void
    {
        $this->store->session = null;

        $response = $this->endpoints()->handle('reviewSession', 'ra_alice', [
            'record'   => '2',
            'event_id' => 1008,
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('no MICA session', $response['error']);
    }

    // ------------------------------------------------------------------ disposition

    public function testADispositionIsAccepted(): void
    {
        $response = $this->endpoints()->handle('submitDisposition', 'ra_alice', [
            'record'   => '2',
            'event_id' => 1008,
            'instance' => 1,
            'review'   => [
                'review_status'       => DispositionService::CONFIRMED,
                'review_rationale'    => 'verified',
                'review_lock_version' => '0',
            ],
        ]);

        $this->assertTrue($response['ok']);
        $this->assertSame('confirmed', $response['disposition']['review_status']);
        $this->assertSame(1, $response['disposition']['lock_version']);
    }

    public function testAStaleDispositionIsA409CarryingTheCurrentVersion(): void
    {
        // So the SPA can reload to the right thing without a second round trip at the exact moment
        // the reviewer is already annoyed.
        $this->findings->finding['review_lock_version'] = '4';

        $response = $this->endpoints()->handle('submitDisposition', 'ra_alice', [
            'record'   => '2',
            'event_id' => 1008,
            'instance' => 1,
            'review'   => [
                'review_status'       => DispositionService::CONFIRMED,
                'review_rationale'    => 'mine',
                'review_lock_version' => '0',
            ],
        ]);

        $this->assertSame(409, $response['status']);
        $this->assertSame(4, $response['current_version']);
    }

    public function testAMissingRationaleIsA400(): void
    {
        $response = $this->endpoints()->handle('submitDisposition', 'ra_alice', [
            'record'   => '2',
            'event_id' => 1008,
            'instance' => 1,
            'review'   => [
                'review_status'       => DispositionService::CONFIRMED,
                'review_lock_version' => '0',
            ],
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('rationale is required', $response['error']);
    }

    // ------------------------------------------------------------------ history / audit

    public function testAnAuditorGetsAggregatesAndNoRows(): void
    {
        // Enforced server-side. If the client chose, de-identification would be a request parameter
        // rather than a control.
        $this->store->history = [
            ['finding_id' => 'a', 'finding_urgency' => 'critical', 'record' => '104729', 'created' => 1],
        ];

        $response = $this->endpoints()->handle('reviewHistory', 'auditor_carol', []);

        $this->assertTrue($response['deidentified']);
        $this->assertSame([], $response['history'], 'no rows at all');
        $this->assertSame(1, $response['summary']['total'], 'counts only');
        $this->assertStringNotContainsString('104729', json_encode($response));
    }

    public function testAReviewerGetsTheRowsThemselves(): void
    {
        $this->store->history = [['finding_id' => 'a', 'finding_urgency' => 'critical', 'created' => 1]];

        $response = $this->endpoints()->handle('reviewHistory', 'ra_alice', []);

        $this->assertFalse($response['deidentified']);
        $this->assertCount(1, $response['history']);
    }

    public function testAnAuditorWhoIsAlsoAReviewerIsNotDeidentified(): void
    {
        // One REDCap role mapped as BOTH reviewer and auditor.
        $roles = new RoleService(
            [RoleService::RA => ['663'], RoleService::AUDITOR => ['663']],
            ['ra_alice' => '660', 'pi_bob' => '662', 'auditor_carol' => '663']
        );
        $audit = new AuditLogger($this->audit, $roles, '257');
        $endpoints = new ReviewEndpoints(
            $this->store,
            new DispositionService($this->findings, $roles, $audit),
            $roles,
            $audit,
            '257'
        );

        $this->store->history = [['finding_id' => 'a', 'created' => 1]];

        $this->assertFalse($endpoints->handle('reviewHistory', 'auditor_carol', [])['deidentified']);
    }

    public function testReadingTheAuditTrailIsItselfAudited(): void
    {
        // Otherwise the one action with no oversight is the oversight.
        $this->store->audit = [['event_type' => 'disposition'], ['event_type' => 'queue_view']];

        $response = $this->endpoints()->handle('auditTrail', 'pi_bob', []);

        $this->assertCount(2, $response['events']);
        $this->assertSame(AuditLogger::AUDIT_VIEW, $this->audit->last()['event_type']);
    }

    public function testTheAuditTrailLimitIsClamped(): void
    {
        $this->store->audit = array_fill(0, 900, ['event_type' => 'queue_view']);

        $this->assertCount(
            500,
            $this->endpoints()->handle('auditTrail', 'pi_bob', ['limit' => 100000])['events'],
            'an unbounded read of the audit table is a denial of service on itself'
        );
        $this->assertCount(1, $this->endpoints()->handle('auditTrail', 'pi_bob', ['limit' => 0])['events']);
    }

    // ------------------------------------------------------------------ not yet built

    public function testStage6ActionsSayTheyAreNotBuiltRatherThanFailingVaguely(): void
    {
        // A generic error here would read as a permissions problem, which is the wrong thing to
        // debug. Needs a super user, since these are the sysadmin's actions.
        $roles = new RoleService([], [], true);
        $audit = new AuditLogger($this->audit, $roles, '257');
        $endpoints = new ReviewEndpoints(
            $this->store,
            new DispositionService($this->findings, $roles, $audit),
            $roles,
            $audit,
            '257'
        );

        foreach (['getPolicy', 'savePolicy'] as $action) {
            try {
                $endpoints->handle($action, 'root', []);
                $this->fail("$action should say it is unimplemented");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not implemented yet', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ submitAction

    /** Wired with the notification stack, as the module wires it in production. */
    private function wiredEndpoints(): ReviewEndpoints
    {
        $roles = new RoleService(
            [RoleService::RA => ['660'], RoleService::PI => ['662']],
            ['ra_alice' => '660', 'pi_bob' => '662']
        );
        $audit = new AuditLogger($this->audit, $roles, '257');
        $artifacts = new ArtifactRegistry();

        $this->notifications = new NotificationService(
            NotificationPolicy::fromJson(null, $artifacts, new SchemaValidator($artifacts)),
            $this->channel,
            new FakeNotificationStore(),
            new FakeRecipientDirectory(),
            $this->findings,
            $audit,
            '257',
            'https://redcap.example.org/review',
            static fn(): int => 1_700_000_000
        );

        return new ReviewEndpoints(
            $this->store,
            new DispositionService($this->findings, $roles, $audit),
            $roles,
            $audit,
            '257',
            $this->notifications,
            $this->actionWriter,
            new LaunchReadiness(new FakeLaunchEnvironment())
        );
    }

    public function testSubmitActionOnAnUnconfirmedFindingIs409NotAnActionTaken(): void
    {
        // 409, not 403: the RA is entitled to act, the finding is not in a state that permits it. A
        // 403 would send them to ask for permissions they already have.
        $response = $this->wiredEndpoints()->handle('submitAction', 'ra_alice', [
            'record'       => '7',
            'event_id'     => 42,
            'instance'     => 1,
            'action_types' => ['alert_care_team'],
        ]);

        $this->assertTrue($response['refused']);
        $this->assertSame(409, $response['status']);
        $this->assertStringContainsString('until a human has confirmed', $response['reason']);
        $this->assertSame(0, $this->channel->count());
        $this->assertSame([], $this->actionWriter->writes, 'A refusal must not document an action.');
    }

    public function testSubmitActionOnAConfirmedFindingDeliversThenDocuments(): void
    {
        $this->findings->finding['review_status'] = 'confirmed';

        $response = $this->wiredEndpoints()->handle('submitAction', 'ra_alice', [
            'record'       => '7',
            'event_id'     => 42,
            'instance'     => 1,
            'action_types' => ['alert_care_team'],
        ]);

        $this->assertTrue($response['delivered']);
        $this->assertSame(1, $this->channel->count());
        $this->assertSame('sent', $this->actionWriter->lastFields()['action_delivery_status']);
        $this->assertSame('1', $this->actionWriter->lastFields()['action_types___alert_care_team']);
    }

    public function testSubmitActionNeverWritesAReviewFieldThroughTheActionWriter(): void
    {
        // The writer's allowlist is the guard; this asserts nothing in the delivery path tries. A
        // delivery path that could write review_status could confirm a finding on its way to
        // notifying about it, which is the loop the RA-first rule exists to prevent.
        $this->findings->finding['review_status'] = 'confirmed';

        $this->wiredEndpoints()->handle('submitAction', 'ra_alice', [
            'record'       => '7',
            'event_id'     => 42,
            'instance'     => 1,
            'action_types' => ['alert_pi', 'document_no_action'],
        ]);

        foreach (array_keys($this->actionWriter->lastFields()) as $field) {
            $this->assertStringStartsWith('action_', $field);
        }
    }

    public function testSubmitActionNeedsARecordAndAnEvent(): void
    {
        $response = $this->wiredEndpoints()->handle('submitAction', 'ra_alice', [
            'action_types' => ['alert_pi'],
        ]);

        $this->assertFalse($response['ok']);
        $this->assertSame(400, $response['status']);
    }

    public function testSubmitActionNeedsAtLeastOneAction(): void
    {
        $response = $this->wiredEndpoints()->handle('submitAction', 'ra_alice', [
            'record'   => '7',
            'event_id' => 42,
        ]);

        $this->assertFalse($response['ok']);
        $this->assertSame(400, $response['status']);
        $this->assertStringContainsString('at least one action', $response['error']);
    }

    public function testSubmitActionSaysSoPlainlyWhenNotificationsAreNotConfigured(): void
    {
        // The unwired constructor - a project that has not set the notification stack up. Better a
        // clear sentence than a null-dereference the reviewer reads as a permissions problem.
        try {
            $this->endpoints()->handle('submitAction', 'ra_alice', [
                'record'       => '7',
                'event_id'     => 42,
                'action_types' => ['alert_pi'],
            ]);
            $this->fail('An unconfigured project should refuse to send actions.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('notification service is not configured', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ launchReadiness

    public function testLaunchReadinessReturnsEveryGateNotJustTheFailingOnes(): void
    {
        $roles = new RoleService([], [], true);
        $audit = new AuditLogger($this->audit, $roles, '257');
        $endpoints = new ReviewEndpoints(
            $this->store,
            new DispositionService($this->findings, $roles, $audit),
            $roles,
            $audit,
            '257',
            null,
            null,
            new LaunchReadiness(new FakeLaunchEnvironment())
        );

        $response = $endpoints->handle('launchReadiness', 'root', []);

        $this->assertTrue($response['ok']);
        $this->assertTrue($response['ready']);
        $this->assertCount(7, $response['gates']);
        $this->assertSame('All launch gates pass.', $response['explanation']);
        // The deliberate blocker is flagged as such so the UI can say "waiting on a decision".
        $byId = array_column($response['gates'], null, 'id');
        $this->assertTrue($byId['critical_acknowledgment_minutes']['deliberate']);
        $this->assertFalse($byId['scan_mock_mode']['deliberate']);
    }
}
