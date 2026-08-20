<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ScanJobStateMachine as SM;
use Stanford\MICA\ScanQueue;
use Stanford\MICA\Tests\Support\FakeScanQueueStore;
use Stanford\MICA\TranscriptException;

#[CoversClass(ScanQueue::class)]
final class ScanQueueTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private FakeScanQueueStore $store;
    private int $now = self::NOW;

    protected function setUp(): void
    {
        $this->store = new FakeScanQueueStore();
        $this->now = self::NOW;
    }

    private function queue(int $maxAttempts = 3): ScanQueue
    {
        return new ScanQueue(
            $this->store,
            new SM($maxAttempts),
            fn(): int => $this->now
        );
    }

    private function enqueue(ScanQueue $queue, string $sha = self::SHA, int $version = 1): array
    {
        return $queue->enqueue('257', '2', 1, 'baseline', 900, $sha, $version);
    }

    public function testEnqueueCreatesAQueuedJobThatIsDueImmediately(): void
    {
        $result = $this->enqueue($this->queue());

        $this->assertTrue($result['created']);

        $job = $this->store->findJob($result['jobId']);
        $this->assertSame(SM::QUEUED, $job['status']);
        $this->assertSame(0, $job['attempts']);
        $this->assertSame(
            self::NOW,
            $job['next_attempt_at'],
            'a null or future next_attempt_at would mean a new job is never picked up'
        );
        $this->assertSame(900, $job['transcript_ref']);
        $this->assertSame(1, $job['instance']);
    }

    public function testEnqueuingTheSameTranscriptTwiceCreatesOneJob(): void
    {
        // The behaviour completeSession depends on: a double-click must not produce two RA queue
        // entries for one session.
        $queue = $this->queue();

        $first = $this->enqueue($queue);
        $second = $this->enqueue($queue);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['jobId'], $second['jobId']);
        $this->assertCount(1, $this->store->jobs);
    }

    public function testADifferentTranscriptHashIsADifferentJob(): void
    {
        $queue = $this->queue();

        $this->enqueue($queue, str_repeat('a', 64));
        $this->enqueue($queue, str_repeat('b', 64));

        $this->assertCount(2, $this->store->jobs);
    }

    public function testARefinalizeWithIdenticalContentStillQueuesANewScan(): void
    {
        // The reason `version` is in the key. A correction that happens to produce byte-identical
        // content has an identical transcript hash, so without the version the "duplicate is
        // success" rule would silently enqueue nothing - the admin sees the correction accepted
        // and no rescan ever happens.
        $queue = $this->queue();

        $v1 = $this->enqueue($queue, self::SHA, 1);
        $v2 = $this->enqueue($queue, self::SHA, 2);

        $this->assertTrue($v2['created'], 'a refinalize must produce a new job');
        $this->assertNotSame($v1['jobId'], $v2['jobId']);
    }

    public function testTwoSessionsInOneWindowDoNotCollide(): void
    {
        // The reason `instance` is in the key. Without it the second session in a window is dropped
        // as a duplicate and never scanned.
        $queue = $this->queue();

        $queue->enqueue('257', '2', 1, 'baseline', 900, self::SHA);
        $second = $queue->enqueue('257', '2', 2, 'baseline', 950, self::SHA);

        $this->assertTrue($second['created']);
        $this->assertCount(2, $this->store->jobs);
    }

    public function testIdempotencyKeyIsSensitiveToEveryPart(): void
    {
        $base = ScanQueue::idempotencyKey('257', '2', 'baseline', 1, 1, self::SHA);

        $this->assertNotSame($base, ScanQueue::idempotencyKey('258', '2', 'baseline', 1, 1, self::SHA));
        $this->assertNotSame($base, ScanQueue::idempotencyKey('257', '3', 'baseline', 1, 1, self::SHA));
        $this->assertNotSame($base, ScanQueue::idempotencyKey('257', '2', 'booster', 1, 1, self::SHA));
        $this->assertNotSame($base, ScanQueue::idempotencyKey('257', '2', 'baseline', 2, 1, self::SHA));
        $this->assertNotSame($base, ScanQueue::idempotencyKey('257', '2', 'baseline', 1, 2, self::SHA));
        $this->assertNotSame(
            $base,
            ScanQueue::idempotencyKey('257', '2', 'baseline', 1, 1, str_repeat('b', 64))
        );
    }

    public function testALostRaceReturnsTheWinningJobRatherThanFailing(): void
    {
        $queue = $this->queue();

        // Simulate: our lookup found nothing, then another request inserted before we did.
        $this->store->raceInsertsRow = [
            'idempotency_key' => ScanQueue::idempotencyKey('257', '2', 'baseline', 1, 1, self::SHA),
            'status'          => SM::QUEUED,
            'attempts'        => 0,
            'next_attempt_at' => self::NOW,
        ];

        $result = $this->enqueue($queue);

        $this->assertFalse($result['created']);
        $this->assertNotNull($this->store->findJob($result['jobId']));
        $this->assertCount(1, $this->store->jobs, 'exactly one scan for one transcript');
    }

    public function testAnInsertFailureWithNoMatchingRowIsNotReportedAsSuccess(): void
    {
        // The dangerous case: if this returned quietly, the session would look finalized with
        // nothing queued, and nobody would ever scan the transcript.
        $this->store->failNextInsertWithNoRow = true;

        try {
            $this->enqueue($this->queue());
            $this->fail('a failed enqueue must not look like an idempotent no-op');
        } catch (TranscriptException $e) {
            $this->assertStringContainsString('finalized but unscanned', $e->getMessage());
            $this->assertStringContainsString('staff attention', $e->getMessage());
        }
    }

    public function testClaimTakesTheOldestDueJobAndMarksIt(): void
    {
        $queue = $this->queue();
        $first = $this->enqueue($queue, str_repeat('a', 64));
        $this->enqueue($queue, str_repeat('b', 64));

        $claimed = $queue->claimNext('token-1');

        $this->assertSame($first['jobId'], (int) $claimed['id'], 'FIFO by id');
        $this->assertSame(SM::SCANNING, $claimed['status']);
        $this->assertSame('token-1', $claimed['claimed_by']);
    }

    public function testASecondWorkerCannotTakeTheSameJob(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue);

        $a = $queue->claimNext('worker-a');
        $b = $queue->claimNext('worker-b');

        $this->assertNotNull($a);
        $this->assertNull($b, 'one queued job, two workers, exactly one claim');
    }

    public function testNothingDueMeansNoClaim(): void
    {
        $this->assertNull($this->queue()->claimNext('token'));
    }

    public function testAJobInBackoffIsNotClaimedEarly(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue);

        $job = $queue->claimNext('t1');
        $queue->finishAttempt($job, 'timeout');

        $this->assertNull($queue->claimNext('t2'), 'still inside the 60 s backoff');

        $this->now += 61;
        $this->assertNotNull($queue->claimNext('t3'), 'claimable once the backoff has elapsed');
    }

    public function testASuccessfulAttemptGoesToReviewAndReleasesTheClaim(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue);
        $job = $queue->claimNext('t1');

        $outcome = $queue->finishAttempt($job, 'ok');

        $this->assertSame(SM::READY_FOR_REVIEW, $outcome['status']);

        $stored = $this->store->findJob((int) $job['id']);
        $this->assertSame(1, $stored['attempts']);
        $this->assertNull($stored['claimed_by'], 'a finished job must not look like work in progress');
        $this->assertNull($stored['claimed_at']);
    }

    public function testRetriesExhaustIntoManualReviewNotSilence(): void
    {
        $queue = $this->queue(3);
        $this->enqueue($queue);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->now += 10_000;   // past any backoff
            $job = $queue->claimNext("t$attempt");
            $this->assertNotNull($job, "attempt $attempt should have been claimable");
            $outcome = $queue->finishAttempt($job, 'service_error', "boom $attempt");
        }

        $this->assertSame(SM::MANUAL_REVIEW_REQUIRED, $outcome['status']);

        $stored = $this->store->findJob(1);
        $this->assertSame(3, $stored['attempts']);
        $this->assertStringContainsString('boom 3', $stored['last_error'], 'keep the last cause');
        $this->assertNull($queue->claimNext('t4'), 'an exhausted job is not re-claimed');
    }

    public function testAStaleClaimIsReturnedToTheQueue(): void
    {
        // Without reaping, a killed worker leaves the job in `scanning` forever: no retry, no
        // failure, no review task - and the dashboard shows it as work in progress.
        $queue = $this->queue();
        $this->enqueue($queue);
        $queue->claimNext('doomed-worker');

        $this->now += SM::STALE_CLAIM_SECONDS + 1;
        $reaped = $queue->reapStaleClaims();

        $this->assertCount(1, $reaped);
        $this->assertSame(SM::QUEUED, $reaped[0]['status']);

        $stored = $this->store->findJob(1);
        $this->assertSame(1, $stored['attempts'], 'reaping counts as an attempt');
        $this->assertNull($stored['claimed_by']);
    }

    public function testAFreshClaimIsNotReaped(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue);
        $queue->claimNext('busy-worker');

        $this->now += 60;

        $this->assertSame([], $queue->reapStaleClaims(), 'a worker mid-scan must be left alone');
        $this->assertSame(SM::SCANNING, $this->store->findJob(1)['status']);
    }

    public function testAJobThatKillsItsWorkerEveryTimeEventuallyStops(): void
    {
        // Otherwise a poisoned job is reclaimed forever, and "forever" is not a state anyone
        // notices - it never fails and never completes.
        $queue = $this->queue(3);
        $this->enqueue($queue);

        for ($i = 0; $i < 3; $i++) {
            $this->now += SM::STALE_CLAIM_SECONDS + 10_000;
            $job = $queue->claimNext("worker-$i");
            $this->assertNotNull($job, "round $i should have been claimable");
            $this->now += SM::STALE_CLAIM_SECONDS + 1;
            $queue->reapStaleClaims();
        }

        $this->assertSame(SM::MANUAL_REVIEW_REQUIRED, $this->store->findJob(1)['status']);
    }

    public function testLoggingNamesTheJobAndTheOutcome(): void
    {
        $lines = [];
        $queue = new ScanQueue(
            $this->store,
            new SM(3),
            fn(): int => $this->now,
            static function (string $m) use (&$lines): void {
                $lines[] = $m;
            }
        );

        $this->enqueue($queue);
        $queue->finishAttempt($queue->claimNext('t1'), 'timeout', 'provider timed out');

        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('queued scan job 1', $lines[0]);
        $this->assertStringContainsString('transient', implode("\n", $lines));
    }

    public function testNoParticipantTextIsEverLogged(): void
    {
        // Queue log lines land in the module log. They may name ids, statuses and reasons - never
        // anything a participant said.
        $lines = [];
        $queue = new ScanQueue(
            $this->store,
            new SM(3),
            fn(): int => $this->now,
            static function (string $m) use (&$lines): void {
                $lines[] = $m;
            }
        );

        $this->enqueue($queue);
        $queue->finishAttempt($queue->claimNext('t1'), 'ok');

        foreach ($lines as $line) {
            $this->assertStringNotContainsString('baseline|', $line);
            $this->assertDoesNotMatchRegularExpression('/\bcontent\b/', $line);
        }
    }
}
