<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\EntityTypes;
use Stanford\MICA\ScanJobStateMachine as SM;

/**
 * The rule these tests exist to defend: a scan that did not happen must never end up
 * indistinguishable from a scan that found nothing.
 *
 * Everything else here is bookkeeping. The three tests that matter are
 * testNoFailurePathEverReachesReadyForReview, testNothingCanReachScanFailed and
 * testEveryDeclaredFailureClassIsClassified - the last because an *unclassified* failure is how a
 * new run status would quietly acquire a default behaviour nobody chose.
 */
#[CoversClass(SM::class)]
final class ScanJobStateMachineTest extends TestCase
{
    public function testSuccessGoesToReview(): void
    {
        $result = (new SM())->afterAttempt(SM::SCANNING, 'ok', 1);

        $this->assertSame(SM::READY_FOR_REVIEW, $result['status']);
        $this->assertNull($result['retryInSeconds']);
    }

    /**
     * The load-bearing test. Every failure class, at every attempt count from first to well past
     * exhaustion, must land somewhere a human sees - never in ready_for_review.
     */
    public function testNoFailurePathEverReachesReadyForReview(): void
    {
        $sm = new SM(3);
        $failures = array_diff(array_keys(EntityTypes::runStatusChoices()), ['ok']);

        foreach ($failures as $runStatus) {
            for ($attempts = 1; $attempts <= 6; $attempts++) {
                $result = $sm->afterAttempt(SM::SCANNING, $runStatus, $attempts);

                $this->assertNotSame(
                    SM::READY_FOR_REVIEW,
                    $result['status'],
                    "$runStatus at attempt $attempts reached review - that is a negative screen"
                );
                $this->assertContains(
                    $result['status'],
                    [SM::QUEUED, SM::MANUAL_REVIEW_REQUIRED],
                    "$runStatus at attempt $attempts went somewhere unexpected"
                );
            }
        }
    }

    public function testNothingCanReachScanFailed(): void
    {
        // A terminal status meaning "the scan did not happen" that raises no review task is the
        // silent negative screen the handoff forbids. It stays in the enum for data-model parity;
        // no code path may create one.
        $sm = new SM();

        foreach (array_keys(EntityTypes::runStatusChoices()) as $runStatus) {
            for ($attempts = 1; $attempts <= 6; $attempts++) {
                $this->assertNotSame(
                    SM::UNREACHABLE,
                    $sm->afterAttempt(SM::SCANNING, $runStatus, $attempts)['status']
                );
            }
        }

        foreach (SM::statuses() as $from) {
            $this->assertFalse(
                $sm->canTransition($from, SM::UNREACHABLE),
                "$from must not be able to transition into " . SM::UNREACHABLE
            );
        }
    }

    public function testEveryDeclaredFailureClassIsClassified(): void
    {
        // If Stage 4 adds a run status and nobody classifies it, afterAttempt() sends it to manual
        // review - safe, but by accident. This makes the omission visible instead.
        $classified = array_merge(SM::TRANSIENT, SM::TERMINAL, ['ok']);
        $declared = array_keys(EntityTypes::runStatusChoices());

        $this->assertSame(
            [],
            array_diff($declared, $classified),
            'these run statuses are declared but not classified as transient or terminal'
        );
        $this->assertSame(
            [],
            array_diff($classified, $declared),
            'these are classified but are not declared run statuses'
        );
    }

    #[DataProvider('terminalFailures')]
    public function testTerminalFailuresSkipRetriesEntirely(string $runStatus): void
    {
        $result = (new SM(3))->afterAttempt(SM::SCANNING, $runStatus, 1);

        $this->assertSame(SM::MANUAL_REVIEW_REQUIRED, $result['status'], 'on the FIRST attempt');
        $this->assertNull($result['retryInSeconds']);
        $this->assertStringContainsString('not fixed by retrying', $result['reason']);
    }

    public static function terminalFailures(): array
    {
        return array_map(static fn(string $s): array => [$s], SM::TERMINAL);
    }

    #[DataProvider('transientFailures')]
    public function testTransientFailuresRetryUntilExhausted(string $runStatus): void
    {
        $sm = new SM(3);

        $this->assertSame(SM::QUEUED, $sm->afterAttempt(SM::SCANNING, $runStatus, 1)['status']);
        $this->assertSame(SM::QUEUED, $sm->afterAttempt(SM::SCANNING, $runStatus, 2)['status']);

        $exhausted = $sm->afterAttempt(SM::SCANNING, $runStatus, 3);
        $this->assertSame(SM::MANUAL_REVIEW_REQUIRED, $exhausted['status'], 'never silently dropped');
        $this->assertStringContainsString('gave up after 3', $exhausted['reason']);
    }

    public static function transientFailures(): array
    {
        return array_map(static fn(string $s): array => [$s], SM::TRANSIENT);
    }

    public function testAnUnrecognisedRunStatusIsNotAssumedTransient(): void
    {
        // Guessing "retry" for an unknown failure risks a loop; guessing "fine" would be a negative
        // screen. Neither is acceptable, so it goes to a human.
        $result = (new SM())->afterAttempt(SM::SCANNING, 'something_new', 1);

        $this->assertSame(SM::MANUAL_REVIEW_REQUIRED, $result['status']);
        $this->assertStringContainsString('not assumed transient', $result['reason']);
    }

    public function testBackoffIsExponentialAndCapped(): void
    {
        $sm = new SM(10);

        $this->assertSame(60, $sm->backoffSeconds(1));
        $this->assertSame(240, $sm->backoffSeconds(2));
        $this->assertSame(960, $sm->backoffSeconds(3));
        $this->assertSame(SM::BACKOFF_CAP_SECONDS, $sm->backoffSeconds(9), 'must not grow forever');
    }

    public function testARetryCarriesItsBackoff(): void
    {
        $result = (new SM(3))->afterAttempt(SM::SCANNING, 'timeout', 1);

        $this->assertSame(60, $result['retryInSeconds'], 'a retry with no delay is a hot loop');
    }

    public function testMaxAttemptsOfOneMeansNoRetries(): void
    {
        $result = (new SM(1))->afterAttempt(SM::SCANNING, 'timeout', 1);

        $this->assertSame(SM::MANUAL_REVIEW_REQUIRED, $result['status']);
    }

    public function testAnAbsurdMaxAttemptsIsClampedNotHonoured(): void
    {
        // 0 would mean "never scan anything", which is not a setting anyone means to type.
        $this->assertSame(1, (new SM(0))->maxAttempts());
        $this->assertSame(1, (new SM(-5))->maxAttempts());
    }

    public function testFinishingAnAttemptForAJobThatIsNotScanningIsABug(): void
    {
        // Reaching here means a claim was lost or a job ran twice. Silently accepting it would let
        // two workers write conflicting outcomes for one job.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/lost-claim or double-run/');

        (new SM())->afterAttempt(SM::QUEUED, 'ok', 1);
    }

    public function testOnlyQueuedJobsAreClaimable(): void
    {
        $sm = new SM();

        $this->assertTrue($sm->canClaim(SM::QUEUED));
        foreach (array_diff(SM::statuses(), [SM::QUEUED]) as $status) {
            $this->assertFalse($sm->canClaim($status), "$status must not be claimable");
        }
    }

    public function testStaleClaimDetection(): void
    {
        $sm = new SM();
        $now = 1_000_000;

        $this->assertFalse($sm->isClaimStale(SM::SCANNING, $now - 10, $now), 'fresh claim');
        $this->assertTrue($sm->isClaimStale(SM::SCANNING, $now - SM::STALE_CLAIM_SECONDS, $now));
        $this->assertFalse(
            $sm->isClaimStale(SM::QUEUED, $now - 99999, $now),
            'only a scanning job holds a claim'
        );
    }

    public function testAScanningJobWithNoClaimTimestampIsStale(): void
    {
        // The claim write did not complete. Leaving it in `scanning` forever is the one outcome
        // with no recovery path at all.
        $this->assertTrue((new SM())->isClaimStale(SM::SCANNING, null, 1_000_000));
    }

    public function testReapingCountsAsAnAttempt(): void
    {
        $sm = new SM(3);

        $requeued = $sm->afterStaleClaim(1);
        $this->assertSame(SM::QUEUED, $requeued['status']);
        $this->assertSame(60, $requeued['retryInSeconds']);

        // A job that kills its worker every time must exhaust, not be reclaimed forever.
        $this->assertSame(SM::MANUAL_REVIEW_REQUIRED, $sm->afterStaleClaim(3)['status']);
    }

    public function testReviewSideTransitions(): void
    {
        $sm = new SM();

        $this->assertTrue($sm->canTransition(SM::READY_FOR_REVIEW, SM::UNDER_REVIEW));
        $this->assertTrue($sm->canTransition(SM::UNDER_REVIEW, SM::REVIEW_COMPLETE));
        $this->assertTrue(
            $sm->canTransition(SM::UNDER_REVIEW, SM::READY_FOR_REVIEW),
            'an RA may put a job back down without deciding'
        );
        $this->assertTrue(
            $sm->canTransition(SM::MANUAL_REVIEW_REQUIRED, SM::UNDER_REVIEW),
            'a failed scan still needs a disposition'
        );
    }

    public function testReviewCompleteIsTerminal(): void
    {
        $sm = new SM();

        foreach (SM::statuses() as $to) {
            $this->assertFalse(
                $sm->canTransition(SM::REVIEW_COMPLETE, $to),
                "a completed review must not reopen into $to"
            );
        }
    }

    public function testAFailedScanCannotBeTransitionedStraightToReview(): void
    {
        $sm = new SM();

        $this->assertFalse($sm->canTransition(SM::MANUAL_REVIEW_REQUIRED, SM::READY_FOR_REVIEW));
        $this->assertFalse($sm->canTransition(SM::QUEUED, SM::READY_FOR_REVIEW));
    }
}
