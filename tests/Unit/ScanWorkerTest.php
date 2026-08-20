<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ScanJobStateMachine as SM;
use Stanford\MICA\ScanQueue;
use Stanford\MICA\ScanWorker;
use Stanford\MICA\Tests\Support\FakeScanQueueStore;

#[CoversClass(ScanWorker::class)]
final class ScanWorkerTest extends TestCase
{
    private const NOW = 1_700_000_000;

    private FakeScanQueueStore $store;
    private int $now = self::NOW;
    private int $token = 0;
    /** @var string[] */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->store = new FakeScanQueueStore();
        $this->now = self::NOW;
        $this->token = 0;
        $this->logs = [];
    }

    private function queue(int $maxAttempts = 3): ScanQueue
    {
        return new ScanQueue($this->store, new SM($maxAttempts), fn(): int => $this->now);
    }

    private function worker(?callable $runner = null, ?ScanQueue $queue = null): ScanWorker
    {
        return new ScanWorker(
            $queue ?? $this->queue(),
            $runner,
            fn(): int => $this->now,
            function (string $m): void {
                $this->logs[] = $m;
            },
            fn(): string => 'token-' . (++$this->token)
        );
    }

    private function enqueue(ScanQueue $queue, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $queue->enqueue('257', '2', 1, 'baseline', 900 + $i, str_repeat((string) $i, 64));
        }
    }

    public function testAnEmptyQueueIsANoOpPass(): void
    {
        $result = $this->worker()->runPass();

        $this->assertSame(['reaped' => 0, 'claimed' => 0, 'outcomes' => [], 'stoppedEarly' => false], $result);
        $this->assertSame([], $this->logs, 'a quiet cron must be quiet');
    }

    public function testItProcessesUpToTheBatchLimit(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue, 8);

        $result = $this->worker(static fn(): array => ['runStatus' => 'ok', 'error' => null], $queue)
            ->runPass(3);

        $this->assertSame(3, $result['claimed'], 'a cron that overruns its interval stacks workers');
        $this->assertSame(['ok' => 3], $result['outcomes']);

        $counts = $this->store->countByStatus();
        $this->assertSame(3, $counts[SM::READY_FOR_REVIEW]);
        $this->assertSame(5, $counts[SM::QUEUED], 'the rest wait for the next pass');
    }

    public function testEachJobGetsItsOwnClaimToken(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue, 2);

        $this->worker(static fn(): array => ['runStatus' => 'ok', 'error' => null], $queue)->runPass(2);

        $this->assertSame(2, $this->token, 'a reused token would let one read-back return another job');
    }

    public function testTheDefaultRunnerReportsAFailureNotACleanScreen(): void
    {
        // The most important test in this file. With no scanner configured, a job must NOT end up
        // looking scanned - "no runner" and "nothing found" have to be different outcomes.
        $queue = $this->queue();
        $this->enqueue($queue, 1);

        $result = $this->worker(null, $queue)->runPass();

        $this->assertSame(['service_error' => 1], $result['outcomes']);
        $this->assertNotSame(SM::READY_FOR_REVIEW, $this->store->findJob(1)['status']);
        $this->assertStringContainsString('NOT a clean screen', $this->store->findJob(1)['last_error']);
    }

    public function testARunnerThatThrowsDoesNotLeaveTheJobClaimed(): void
    {
        // Otherwise every unexpected fault manufactures a stale claim, and the job sits in
        // `scanning` until the reaper notices - fifteen minutes of looking like work in progress.
        $queue = $this->queue();
        $this->enqueue($queue, 1);

        $result = $this->worker(
            static function (array $job): array {
                throw new \RuntimeException('provider exploded');
            },
            $queue
        )->runPass();

        $this->assertSame(['service_error' => 1], $result['outcomes']);

        $job = $this->store->findJob(1);
        $this->assertSame(SM::QUEUED, $job['status'], 'requeued, not abandoned');
        $this->assertNull($job['claimed_by']);
        $this->assertStringContainsString('provider exploded', $job['last_error'], 'keep the cause');
    }

    public function testOneThrowingJobDoesNotStopTheRestOfTheBatch(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue, 3);
        $seen = 0;

        $result = $this->worker(
            static function (array $job) use (&$seen): array {
                $seen++;
                if ($seen === 1) {
                    throw new \RuntimeException('first one exploded');
                }
                return ['runStatus' => 'ok', 'error' => null];
            },
            $queue
        )->runPass(3);

        $this->assertSame(3, $result['claimed']);
        $this->assertSame(['service_error' => 1, 'ok' => 2], $result['outcomes']);
    }

    public function testItReapsStaleClaimsBeforeClaimingAnything(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue, 1);
        $queue->claimNext('a-worker-that-died');

        $this->now += SM::STALE_CLAIM_SECONDS + 1;

        $worker = $this->worker(static fn(): array => ['runStatus' => 'ok', 'error' => null], $queue);
        $result = $worker->runPass();

        $this->assertSame(1, $result['reaped']);

        // NOT re-claimed in the same pass, and that is correct: afterStaleClaim() gives the job a
        // backoff, so a job that just killed a worker is not immediately handed to another one.
        // Reaping still has to come first - it is what stops the job sitting in `scanning` forever,
        // where the dashboard reads it as work in progress and nobody ever looks.
        $this->assertSame(0, $result['claimed']);

        $job = $this->store->findJob(1);
        $this->assertSame(SM::QUEUED, $job['status']);
        $this->assertSame(1, $job['attempts'], 'reaping counts as an attempt');
        $this->assertSame(self::NOW + SM::STALE_CLAIM_SECONDS + 1 + 60, (int) $job['next_attempt_at']);

        // Once the backoff has elapsed, the next pass picks it up.
        $this->now += 61;
        $this->assertSame(1, $worker->runPass()['claimed']);
        $this->assertSame(SM::READY_FOR_REVIEW, $this->store->findJob(1)['status']);
    }

    public function testItStopsClaimingWhenTheTimeBudgetIsGone(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue, 5);

        // Each scan burns 100 s of a 250 s budget.
        $result = $this->worker(
            function (array $job): array {
                $this->now += 100;
                return ['runStatus' => 'ok', 'error' => null];
            },
            $queue
        )->runPass(5, 250);

        $this->assertTrue($result['stoppedEarly']);
        $this->assertSame(3, $result['claimed'], 'checked before each claim, so 0/100/200 s pass');
        $this->assertSame(2, $this->store->countByStatus()[SM::QUEUED]);
    }

    public function testTheBudgetIsCheckedBeforeClaimingNotAfter(): void
    {
        // Claiming and then running out of budget abandons a claimed job, manufacturing the exact
        // stale claim the reaper exists to clean up.
        $queue = $this->queue();
        $this->enqueue($queue, 3);

        $this->worker(
            function (array $job): array {
                $this->now += 1000;
                return ['runStatus' => 'ok', 'error' => null];
            },
            $queue
        )->runPass(3, 100);

        foreach ($this->store->jobs as $job) {
            $this->assertNotSame(
                SM::SCANNING,
                $job['status'],
                'no job may be left claimed when a pass ends'
            );
        }
    }

    public function testAnAbsurdBatchSizeStillProcessesOne(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue, 1);

        $result = $this->worker(static fn(): array => ['runStatus' => 'ok', 'error' => null], $queue)
            ->runPass(0);

        $this->assertSame(1, $result['claimed'], '0 would mean the queue never drains');
    }

    public function testATransientFailureBacksOffRatherThanSpinning(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue, 1);

        $worker = $this->worker(static fn(): array => ['runStatus' => 'timeout', 'error' => 'slow'], $queue);

        $first = $worker->runPass(5);
        $this->assertSame(1, $first['claimed']);

        // Same pass, same second: the job is in backoff and must not be re-claimed immediately.
        $second = $worker->runPass(5);
        $this->assertSame(0, $second['claimed'], 'a hot retry loop would hammer the provider');
    }

    public function testRepeatedFailuresEndInManualReviewNotSilence(): void
    {
        $queue = $this->queue(3);
        $this->enqueue($queue, 1);
        $worker = $this->worker(static fn(): array => ['runStatus' => 'timeout', 'error' => 'slow'], $queue);

        for ($i = 0; $i < 3; $i++) {
            $worker->runPass(1);
            $this->now += 10_000;
        }

        $this->assertSame(SM::MANUAL_REVIEW_REQUIRED, $this->store->findJob(1)['status']);
    }

    public function testNoParticipantTextInTheCronLog(): void
    {
        $queue = $this->queue();
        $this->enqueue($queue, 1);

        $this->worker(
            static fn(): array => ['runStatus' => 'ok', 'error' => null],
            $queue
        )->runPass();

        foreach ($this->logs as $line) {
            $this->assertDoesNotMatchRegularExpression('/content|message|transcript text/i', $line);
        }
    }
}
