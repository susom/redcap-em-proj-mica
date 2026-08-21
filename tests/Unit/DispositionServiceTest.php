<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\AuditLogger;
use Stanford\MICA\DispositionService as D;
use Stanford\MICA\ReviewAccessException;
use Stanford\MICA\ReviewConflictException;
use Stanford\MICA\RoleService;
use Stanford\MICA\Tests\Support\FakeAuditStore;
use Stanford\MICA\Tests\Support\FakeFindingReviewStore;
use Stanford\MICA\TranscriptException;

/**
 * The point the whole handoff turns on: nothing reaches a care team, PI or protocol lead until a
 * human confirms a finding here.
 */
#[CoversClass(D::class)]
final class DispositionServiceTest extends TestCase
{
    private const NOW = 1_700_000_000;

    private FakeFindingReviewStore $store;
    private FakeAuditStore $audit;

    protected function setUp(): void
    {
        $this->store = new FakeFindingReviewStore();
        $this->audit = new FakeAuditStore();
    }

    private function service(array $mapping = []): D
    {
        // REDCap role ids and the project roster - access follows the user's REDCap role.
        $roles = new RoleService(
            $mapping + [RoleService::RA => ['660'], RoleService::PI => ['662'], RoleService::AUDITOR => ['663']],
            ['ra_alice' => '660', 'pi_bob' => '662', 'auditor_carol' => '663']
        );

        return new D(
            $this->store,
            $roles,
            new AuditLogger($this->audit, $roles, '257'),
            static fn(): int => self::NOW
        );
    }

    private function submit(array $input, ?string $user = 'ra_alice'): array
    {
        return $this->service()->submit('257', '2', 1008, 1, $user, $input + ['review_lock_version' => '0']);
    }

    // ------------------------------------------------------------------ happy path

    public function testConfirmingWritesTheDecisionAndBumpsTheLock(): void
    {
        $result = $this->submit([
            'review_status'    => D::CONFIRMED,
            'review_rationale' => 'Participant states passive ideation; escalating per protocol.',
        ]);

        $this->assertSame(D::CONFIRMED, $result['review_status']);
        $this->assertSame(1, $result['lock_version']);

        $written = $this->store->lastWrite();
        $this->assertSame(D::CONFIRMED, $written['review_status']);
        $this->assertSame('ra_alice', $written['review_reviewer'], 'server-derived');
        $this->assertSame('1', $written['review_lock_version']);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW), $written['review_reviewed_at']);
    }

    public function testTheReviewerIsServerDerivedNotClientSupplied(): void
    {
        // A client-supplied reviewer would make the audit trail worthless.
        $this->submit([
            'review_status'    => D::CONFIRMED,
            'review_rationale' => 'because',
            'review_reviewer'  => 'somebody_else',
        ]);

        $this->assertSame('ra_alice', $this->store->lastWrite()['review_reviewer']);
    }

    public function testDismissingIsRecordedAsADecisionNotAsNothing(): void
    {
        $this->submit(['review_status' => D::DISMISSED, 'review_rationale' => 'Quote is MICA reflecting.']);

        $this->assertSame(D::DISMISSED, $this->store->lastWrite()['review_status']);
        $this->assertSame(AuditLogger::DISPOSITION, $this->audit->last()['event_type']);
    }

    public function testNeedsSecondReviewNeedsNoRationale(): void
    {
        // It defers rather than decides, so demanding a justification would just produce "not sure".
        $result = $this->submit(['review_status' => D::NEEDS_SECOND_REVIEW]);

        $this->assertSame(D::NEEDS_SECOND_REVIEW, $result['review_status']);
    }

    public function testCorrectionsAreStoredSeparatelyFromTheModelsClassification(): void
    {
        // So the original stays intact and the disagreement itself is the auditable fact.
        $this->submit([
            'review_status'                 => D::CONFIRMED,
            'review_rationale'              => 'Reclassifying.',
            'review_corrected_concern_type' => 'dangerous_alcohol_use',
            'review_corrected_urgency'      => 'high',
        ]);

        $written = $this->store->lastWrite();

        $this->assertSame('dangerous_alcohol_use', $written['review_corrected_concern_type']);
        $this->assertSame('high', $written['review_corrected_urgency']);
        $this->assertArrayNotHasKey('finding_concern_type', $written, 'the model field is untouched');
        $this->assertSame('self_harm', $this->store->finding['finding_concern_type']);
        $this->assertSame('critical', $this->store->finding['finding_urgency']);
    }

    public function testAWithdrawnCorrectionIsActuallyCleared(): void
    {
        // The bug this guards: saveData's `normal` mode skips empty values, so a correction a
        // reviewer set and then withdrew would survive on a confirmed finding. The store writes
        // with `overwrite` for exactly this - but only if the field is actually SENT, which means
        // "absent" and "present but empty" have to mean different things.
        $service = $this->service();

        $service->submit('257', '2', 1008, 1, 'ra_alice', [
            'review_status'            => D::CONFIRMED,
            'review_rationale'         => 'Urgency looks overstated.',
            'review_corrected_urgency' => 'moderate',
            'review_lock_version'      => '0',
        ]);
        $this->assertSame('moderate', $this->store->finding['review_corrected_urgency']);

        // On reflection, the model was right.
        $service->submit('257', '2', 1008, 1, 'ra_alice', [
            'review_status'            => D::CONFIRMED,
            'review_rationale'         => 'On reflection the model was right; withdrawing.',
            'review_corrected_urgency' => '',
            'review_lock_version'      => '1',
        ]);

        $this->assertSame(
            '',
            $this->store->finding['review_corrected_urgency'],
            'a withdrawn correction must not survive on a confirmed finding'
        );
        $this->assertArrayHasKey(
            'review_corrected_urgency',
            $this->store->lastWrite(),
            'the field has to be SENT to be cleared - an omitted key cannot blank anything'
        );
    }

    public function testAnAbsentFieldIsLeftAloneRatherThanCleared(): void
    {
        $service = $this->service();

        $service->submit('257', '2', 1008, 1, 'ra_alice', [
            'review_status'            => D::CONFIRMED,
            'review_rationale'         => 'first pass',
            'review_corrected_urgency' => 'moderate',
            'review_lock_version'      => '0',
        ]);

        // A later submit that says nothing about the correction must not blank it.
        $service->submit('257', '2', 1008, 1, 'pi_bob', [
            'review_status'       => D::CONFIRMED,
            'review_rationale'    => 'second pass, correction unchanged',
            'review_lock_version' => '1',
        ]);

        $this->assertSame('moderate', $this->store->finding['review_corrected_urgency']);
        $this->assertArrayNotHasKey('review_corrected_urgency', $this->store->lastWrite());
    }

    // ------------------------------------------------------------------ the guarantees

    public function testModelFieldsCanNeverBeWritten(): void
    {
        // Not filtered - thrown. A silently filtered finding_urgency would mean a reviewer's
        // correction did nothing, which is worse than an error.
        foreach (['finding_urgency', 'finding_concern_type', 'finding_summary', 'finding_id'] as $field) {
            $this->assertNotContains($field, D::WRITABLE, "$field must not be writable here");
        }
    }

    public function testTheWritableListIsReviewFieldsOnly(): void
    {
        foreach (D::WRITABLE as $field) {
            $this->assertStringStartsWith('review_', $field);
        }
    }

    #[DataProvider('endingDispositions')]
    public function testAnEndingDispositionRequiresARationale(string $status): void
    {
        // A confirmed critical finding with no stated reason is not reviewable by the next person -
        // including the same reviewer in three months.
        try {
            $this->submit(['review_status' => $status]);
            $this->fail("$status must require a rationale");
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('rationale is required', $e->getMessage());
            $this->assertStringContainsString('three months', $e->getMessage());
        }

        $this->assertSame([], $this->store->writes, 'and nothing is written');
        $this->assertSame([], $this->audit->events, 'nor audited as a decision');
    }

    public static function endingDispositions(): array
    {
        return array_map(static fn(string $s): array => [$s], D::REQUIRES_RATIONALE);
    }

    public function testAWhitespaceOnlyRationaleIsNoRationale(): void
    {
        $this->expectException(TranscriptException::class);
        $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => "  \n\t "]);
    }

    public function testAnUnknownDispositionIsRefused(): void
    {
        foreach (['', 'pending', 'approved', 'escalated'] as $status) {
            try {
                $this->submit(['review_status' => $status]);
                $this->fail("\"$status\" must not be accepted");
            } catch (TranscriptException $e) {
                $this->assertStringContainsString('is not a disposition', $e->getMessage());
            }
        }
    }

    public function testPendingCannotBeSubmittedAsADecision(): void
    {
        // Otherwise a reviewer could "un-review" a finding with no rationale and no trace of why.
        $this->expectException(TranscriptException::class);
        $this->submit(['review_status' => D::PENDING]);
    }

    // ------------------------------------------------------------------ locking

    public function testAStaleLockVersionIsRefusedWithTheCurrentOne(): void
    {
        // Two RAs on the same queue is the normal case. Silently overwriting a colleague's
        // disposition is the one failure nobody notices - the finding still looks reviewed.
        $this->store->finding['review_lock_version'] = '3';

        try {
            $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => 'mine']);
            $this->fail('a stale write must be refused');
        } catch (ReviewConflictException $e) {
            $this->assertSame(3, $e->currentVersion, 'the SPA needs to know what to reload to');
            $this->assertStringContainsString('Somebody else saved', $e->getMessage());
            $this->assertStringContainsString('not been discarded', $e->getMessage());
        }

        $this->assertSame([], $this->store->writes);
    }

    public function testAMissingLockVersionIsRefused(): void
    {
        // A client that omits the version is not "unversioned" - it is one that has not read the
        // record it is trying to overwrite.
        foreach ([[], ['review_lock_version' => '']] as $input) {
            try {
                $this->service()->submit('257', '2', 1008, 1, 'ra_alice', $input + [
                    'review_status'    => D::CONFIRMED,
                    'review_rationale' => 'x',
                ]);
                $this->fail('an unversioned write must be refused');
            } catch (ReviewConflictException $e) {
                $this->assertStringContainsString('did not carry a lock version', $e->getMessage());
            }
        }
    }

    public function testTheSecondOfTwoRacingWritesLoses(): void
    {
        $service = $this->service();

        // Both reviewers loaded version 0.
        $service->submit('257', '2', 1008, 1, 'ra_alice', [
            'review_status'       => D::CONFIRMED,
            'review_rationale'    => 'alice confirms',
            'review_lock_version' => '0',
        ]);

        $this->expectException(ReviewConflictException::class);

        $service->submit('257', '2', 1008, 1, 'pi_bob', [
            'review_status'       => D::DISMISSED,
            'review_rationale'    => 'bob dismisses',
            'review_lock_version' => '0',
        ]);
    }

    public function testAReReadAfterAConflictSucceeds(): void
    {
        $service = $this->service();

        $service->submit('257', '2', 1008, 1, 'ra_alice', [
            'review_status'       => D::CONFIRMED,
            'review_rationale'    => 'alice',
            'review_lock_version' => '0',
        ]);

        // Bob reloads, sees version 1, disagrees, re-applies.
        $result = $service->submit('257', '2', 1008, 1, 'pi_bob', [
            'review_status'       => D::DISMISSED,
            'review_rationale'    => 'bob, after reading alice',
            'review_lock_version' => '1',
        ]);

        $this->assertSame(2, $result['lock_version']);
        $this->assertSame('pi_bob', $this->store->finding['review_reviewer']);
    }

    // ------------------------------------------------------------------ access

    public function testAnAuditorCannotDisposition(): void
    {
        $this->expectException(ReviewAccessException::class);
        $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => 'x'], 'auditor_carol');
    }

    public function testAStrangerCannotDisposition(): void
    {
        $this->expectException(ReviewAccessException::class);
        $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => 'x'], 'stranger');
    }

    public function testAnonymousCannotDisposition(): void
    {
        $this->expectException(ReviewAccessException::class);
        $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => 'x'], null);
    }

    public function testAccessIsCheckedBeforeAnythingIsRead(): void
    {
        try {
            $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => 'x'], 'stranger');
        } catch (ReviewAccessException) {
            // expected
        }

        $this->assertSame([], $this->store->writes);
        $this->assertSame([], $this->audit->events);
    }

    // ------------------------------------------------------------------ audit

    public function testTheDecisionIsAuditedBeforeItIsWritten(): void
    {
        // If the record of the decision cannot be made, the decision is not made.
        $this->store->writeError = 'saveData refused';

        try {
            $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => 'x']);
            $this->fail('expected the write to fail');
        } catch (TranscriptException) {
            // expected
        }

        $this->assertCount(1, $this->audit->events, 'the attempt is on the record either way');
    }

    public function testAFailedAuditWritePreventsTheDecision(): void
    {
        $this->audit->failWrites = true;

        $this->expectException(\Throwable::class);
        $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => 'x']);

        $this->assertSame([], $this->store->writes);
    }

    public function testTheAuditRowRecordsTheTransitionNotTheText(): void
    {
        $rationale = 'The participant said something I should not paste into an audit table.';

        $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => $rationale]);

        $details = $this->audit->last()['details'];

        $this->assertSame('pending', $details['previous_status'], 'what it was');
        $this->assertSame('confirmed', $details['review_status'], 'what it became');
        $this->assertSame(mb_strlen($rationale), $details['rationale_length'], 'that a reason was given');
        $this->assertStringNotContainsString('should not paste', json_encode($details), 'never what it said');
    }

    public function testTheAuditRowNamesTheFinding(): void
    {
        $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => 'x']);

        $this->assertSame('finding', $this->audit->last()['target_kind']);
        $this->assertSame($this->store->finding['finding_id'], $this->audit->last()['target_id']);
    }

    // ------------------------------------------------------------------ missing instance

    public function testAMissingFindingIsRefusedWithAReloadHint(): void
    {
        $this->store->missing = true;

        try {
            $this->submit(['review_status' => D::CONFIRMED, 'review_rationale' => 'x']);
            $this->fail('expected a refusal');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('nothing to disposition', $e->getMessage());
            $this->assertStringContainsString('reload the queue', $e->getMessage());
        }
    }
}
