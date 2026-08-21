<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\AuditLogger;
use Stanford\MICA\RoleService;
use Stanford\MICA\Tests\Support\FakeAuditStore;

#[CoversClass(AuditLogger::class)]
final class AuditLoggerTest extends TestCase
{
    private FakeAuditStore $store;
    /** @var string[] */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->store = new FakeAuditStore();
        $this->logs = [];
    }

    private function logger(): AuditLogger
    {
        return new AuditLogger(
            $this->store,
            new RoleService(
                [RoleService::RA => ['660'], RoleService::PI => ['662']],
                ['ra_alice' => '660', 'pi_bob' => '662', 'auditor_carol' => '663']
            ),
            '257',
            function (string $m): void {
                $this->logs[] = $m;
            }
        );
    }

    public function testItRecordsWhoDidWhatToWhich(): void
    {
        $this->logger()->record('ra_alice', AuditLogger::SESSION_VIEW, 'session', 'T900', [
            'record'       => '2',
            'session_type' => 'baseline',
        ]);

        $event = $this->store->last();

        $this->assertSame('ra_alice', $event['actor']);
        $this->assertSame('ra', $event['actor_role'], 'acting as what');
        $this->assertSame('session_view', $event['event_type']);
        $this->assertSame('session', $event['target_kind']);
        $this->assertSame('T900', $event['target_id']);
        $this->assertSame(['record' => '2', 'session_type' => 'baseline'], $event['details']);
    }

    public function testTheRoleRecordedIsTheMostPrivilegedHeld(): void
    {
        // An audit row should not understate what the actor was entitled to do.
        $this->logger()->record('pi_bob', AuditLogger::QUEUE_VIEW);

        $this->assertSame('pi', $this->store->last()['actor_role']);
    }

    public function testACronGetsAnExplicitSentinelNotAnEmptyActor(): void
    {
        // So "nobody was logged in" and "we forgot to pass the actor" stay distinguishable.
        foreach ([null, '', '   '] as $actor) {
            $this->logger()->record($actor, AuditLogger::SCAN_GAVE_UP, 'job', '7');
            $this->assertSame(AuditLogger::SYSTEM_ACTOR, $this->store->last()['actor']);
        }
    }

    public function testAnUnknownActorStillGetsARowWithNoRole(): void
    {
        // An access-denied event is about somebody with no role; refusing to record it would lose
        // exactly the events an auditor most wants.
        $this->logger()->record('stranger', AuditLogger::ACCESS_DENIED, 'action', 'submitDisposition', [
            'denied_action' => 'submitDisposition',
        ]);

        $this->assertSame('stranger', $this->store->last()['actor']);
        $this->assertNull($this->store->last()['actor_role']);
    }

    // ------------------------------------------------------- the allowlist

    public function testADisallowedDetailKeyIsRejectedLoudly(): void
    {
        // The whole point. `details` must not be able to accumulate participant speech - that would
        // be a second uncontrolled copy of the most sensitive data in the study, in a table with no
        // user-rights model of its own.
        try {
            $this->logger()->strict('ra_alice', AuditLogger::SESSION_VIEW, 'session', 'T900', [
                'transcript' => 'I have been thinking about not waking up',
            ]);
            $this->fail('an unlisted key must not be accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not on the allowlist', $e->getMessage());
            $this->assertStringContainsString('cannot accumulate participant text', $e->getMessage());
        }

        $this->assertSame([], $this->store->events, 'and nothing is written');
    }

    public function testTheTemptingKeysAreDeliberatelyAbsent(): void
    {
        // `reason` and `note` are exactly where a well-meaning caller would paste a quote. The
        // rationale an RA types lives on the finding instance, under REDCap's own user rights and
        // change history.
        foreach (['reason', 'note', 'notes', 'summary', 'quote', 'content', 'message', 'rationale'] as $key) {
            $this->assertNotContains(
                $key,
                AuditLogger::ALLOWED_DETAIL_KEYS,
                "\"$key\" invites free text into the audit table"
            );
        }
    }

    public function testRationaleIsRecordedAsALengthNotAsText(): void
    {
        // That a rationale was given is auditable; what it said is not this table's business.
        $this->logger()->record('ra_alice', AuditLogger::DISPOSITION, 'finding', 'abc', [
            'rationale_length' => 142,
        ]);

        $this->assertSame(142, $this->store->last()['details']['rationale_length']);
    }

    public function testAnAllowlistedKeyCannotCarryAParagraph(): void
    {
        // Second line of defence: the allowlist stops transcript text, this stops an allowed key
        // being abused.
        $this->logger()->record('ra_alice', AuditLogger::DISPOSITION, 'finding', 'abc', [
            'concern_type' => str_repeat('x', 5000),
        ]);

        $value = $this->store->last()['details']['concern_type'];

        $this->assertSame(200, mb_strlen($value));
        $this->assertStringEndsWith('...', $value);
    }

    public function testNestedStructuresAreFlattenedToScalars(): void
    {
        // A nested structure is where free text hides.
        $this->logger()->record('ra_alice', AuditLogger::ACTION_SENT, 'finding', 'abc', [
            'action_types' => ['alert_care_team', 'second_reviewer'],
        ]);

        $this->assertSame(
            ['alert_care_team', 'second_reviewer'],
            $this->store->last()['details']['action_types']
        );
    }

    public function testDeeplyNestedValuesBecomeAPlaceholder(): void
    {
        $this->logger()->record('ra_alice', AuditLogger::QUEUE_VIEW, '', '', [
            'filters' => ['urgency' => ['critical', 'high']],
        ]);

        $this->assertSame(['?'], $this->store->last()['details']['filters']);
    }

    public function testIntsAndBoolsKeepTheirTypes(): void
    {
        $this->logger()->record('auditor_carol', AuditLogger::HISTORY_VIEW, '', '', [
            'result_count' => 42,
            'deidentified' => true,
        ]);

        $this->assertSame(42, $this->store->last()['details']['result_count']);
        $this->assertTrue($this->store->last()['details']['deidentified']);
    }

    // ------------------------------------------------------- failure handling

    public function testAFailedAuditWriteDoesNotBreakTheReadItIsAuditing(): void
    {
        // Deliberately not fail-closed. Refusing to SHOW an RA a critical finding because the audit
        // row could not be written would trade a real safety risk for a bookkeeping one.
        $this->store->failWrites = true;

        $this->logger()->record('ra_alice', AuditLogger::SESSION_VIEW, 'session', 'T900');

        $this->assertSame([], $this->store->events);
        $this->assertStringContainsString('AUDIT WRITE FAILED', $this->logs[0], 'but loudly');
        $this->assertStringContainsString('session_view', $this->logs[0]);
    }

    public function testAFailedAuditWriteOnAMutationDoesThrow(): void
    {
        // The other side of that trade: an undocumented change to a clinical decision is not an
        // acceptable outcome, so the change is refused rather than made unrecorded.
        $this->store->failWrites = true;

        $this->expectException(\Throwable::class);

        $this->logger()->strict('ra_alice', AuditLogger::DISPOSITION, 'finding', 'abc');
    }

    public function testEveryDeclaredEventTypeIsDistinct(): void
    {
        $types = [
            AuditLogger::QUEUE_VIEW, AuditLogger::SESSION_VIEW, AuditLogger::DISPOSITION,
            AuditLogger::ACTION_SENT, AuditLogger::POLICY_CHANGE, AuditLogger::HISTORY_VIEW,
            AuditLogger::AUDIT_VIEW, AuditLogger::ACCESS_DENIED, AuditLogger::SCAN_GAVE_UP,
        ];

        $this->assertSame($types, array_unique($types));
    }

    public function testEveryRowCarriesItsProjectSoTheTrailCanBeReadPerProject(): void
    {
        /**
         * The audit read used to be module-wide, gated only by the endpoint's role check - which gates
         * WHO may read a trail, not WHICH project's. A PI on one study could read another study's
         * rows. The scoped read excludes anything without a project, so a row written without one is
         * invisible rather than merely unattributed: that is why the constructor argument is required
         * and positional rather than optional and trailing.
         */
        $this->logger()->record('ra_alice', AuditLogger::QUEUE_VIEW, 'project', '257', ['result_count' => 3]);

        $this->assertSame(257, $this->store->last()['project_id']);
    }
}
