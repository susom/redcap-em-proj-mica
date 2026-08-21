<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\ReviewRowAssembler;

/**
 * The defect: the queue keyed findings by `record|event_id` and attached them to every job for that
 * record and event, so the row count was `jobs x findings`. Observed live on PID 257 - 11 rows for
 * 4 findings, with all four shown a second time under a job that produced none of them.
 *
 * The scenario in `testTheLiveDefectScenario...` is that exact data.
 */
#[CoversClass(ReviewRowAssembler::class)]
final class ReviewRowAssemblerTest extends TestCase
{
    /** @return array<string,mixed> */
    private function job(int $id, string $record, int $eventId, ?int $runId, int $expected = 0): array
    {
        return [
            'job_id'            => $id,
            'record'            => $record,
            'event_id'          => $eventId,
            'instance'          => 1,
            'scan_run_id'       => $runId,
            'expected_findings' => $expected,
        ];
    }

    /** @return array<string,string> */
    private function finding(string $id, string $runId): array
    {
        return ['finding_id' => $id, 'finding_scan_run' => $runId, '__instance' => '1'];
    }

    /**
     * PID 257 as it actually was: record 1 scanned twice at event 1008 (jobs 307 then 310), with all
     * four finding instances stamped run 243, which belongs to job 310. The old query produced eight
     * rows for four findings.
     */
    public function testTheLiveDefectScenarioProducesOneRowPerFinding(): void
    {
        $jobs = [
            $this->job(310, '1', 1008, 243, 4),
            $this->job(307, '1', 1008, 240, 2),
        ];
        $findingsByRun = ['243' => [
            $this->finding('f1', '243'), $this->finding('f2', '243'),
            $this->finding('f3', '243'), $this->finding('f4', '243'),
        ]];

        $plans = ReviewRowAssembler::plan($jobs, $findingsByRun, true);

        $this->assertCount(4, $plans, 'four findings, four rows - not eight');
        foreach ($plans as $plan) {
            $this->assertSame(310, $plan['job']['job_id'], 'every row belongs to the job that produced it');
        }
        $this->assertSame(
            ['f1', 'f2', 'f3', 'f4'],
            array_column(array_column($plans, 'finding'), 'finding_id')
        );
    }

    public function testAFindingIsNeverAttachedToAJobThatDidNotProduceIt(): void
    {
        $jobs = [$this->job(310, '1', 1008, 243, 1), $this->job(307, '1', 1008, 240, 0)];
        $plans = ReviewRowAssembler::plan($jobs, ['243' => [$this->finding('f1', '243')]], false);

        $byJob = [];
        foreach ($plans as $plan) {
            $byJob[$plan['job']['job_id']][] = $plan['finding']['finding_id'] ?? null;
        }

        $this->assertSame(['f1'], $byJob[310]);
        $this->assertSame([null], $byJob[307], 'the superseded job shows no findings, not borrowed ones');
    }

    // ------------------------------------------------------------ queue vs history

    public function testTheQueueDropsSupersededScans(): void
    {
        $jobs = [$this->job(310, '1', 1008, 243, 1), $this->job(307, '1', 1008, 240, 0)];
        $plans = ReviewRowAssembler::plan($jobs, ['243' => [$this->finding('f1', '243')]], true);

        $this->assertCount(1, $plans);
        $this->assertSame(310, $plans[0]['job']['job_id']);
        $this->assertFalse($plans[0]['superseded']);
    }

    public function testHistoryKeepsSupersededScansAndFlagsThem(): void
    {
        $jobs = [$this->job(310, '1', 1008, 243, 1), $this->job(307, '1', 1008, 240, 0)];
        $plans = ReviewRowAssembler::plan($jobs, ['243' => [$this->finding('f1', '243')]], false);

        $this->assertCount(2, $plans);
        $flags = [];
        foreach ($plans as $plan) {
            $flags[$plan['job']['job_id']] = $plan['superseded'];
        }
        $this->assertSame([310 => false, 307 => true], $flags);
    }

    /** Latest is the highest job id, not the input order - the caller's ORDER BY must not matter. */
    public function testLatestIsByJobIdNotInputOrder(): void
    {
        $jobs = [$this->job(307, '1', 1008, 240, 0), $this->job(310, '1', 1008, 243, 1)];
        $plans = ReviewRowAssembler::plan($jobs, ['243' => [$this->finding('f1', '243')]], true);

        $this->assertCount(1, $plans);
        $this->assertSame(310, $plans[0]['job']['job_id']);
    }

    /**
     * Different sessions are not each other's supersessions. A record has a baseline and a booster,
     * and a repeating instrument can hold two instances of one - collapsing those would hide a whole
     * session's findings.
     */
    public function testDifferentSessionsOfTheSameRecordBothSurvive(): void
    {
        $baseline = $this->job(400, '1', 1008, 300, 1);
        $booster  = $this->job(401, '1', 1009, 301, 1);
        $second   = ['instance' => 2] + $this->job(402, '1', 1008, 302, 1);

        $plans = ReviewRowAssembler::plan([$second, $booster, $baseline], [
            '300' => [$this->finding('f-base', '300')],
            '301' => [$this->finding('f-boost', '301')],
            '302' => [$this->finding('f-second', '302')],
        ], true);

        $this->assertCount(3, $plans);
        $this->assertEqualsCanonicalizing(
            ['f-base', 'f-boost', 'f-second'],
            array_column(array_column($plans, 'finding'), 'finding_id')
        );
    }

    // ------------------------------------------------------------ clean vs missing

    public function testAGenuineCleanScreenIsOneRowAndIsNotFlagged(): void
    {
        $plans = ReviewRowAssembler::plan([$this->job(308, 'X', 1008, 241, 0)], [], true);

        $this->assertCount(1, $plans);
        $this->assertSame([], $plans[0]['finding']);
        $this->assertFalse($plans[0]['findings_missing'], 'the run found nothing; that is a clean screen');
    }

    /**
     * The failure mode the pipeline exists to prevent. Job 306 on PID 257: its run reported three
     * findings, then "Erase all data" cleared `redcap_data` while the entity tables survived. It
     * must not read as "screened, nothing found".
     */
    public function testARunThatFoundSomethingButHasNoFindingsIsNotACleanScreen(): void
    {
        $plans = ReviewRowAssembler::plan([$this->job(306, '3', 1012, 239, 3)], [], true);

        $this->assertCount(1, $plans);
        $this->assertTrue($plans[0]['findings_missing']);
    }

    public function testAJobWithNoRunYetHasNoFindingsAndNoFalseFlag(): void
    {
        $plans = ReviewRowAssembler::plan([$this->job(500, 'Y', 1008, null, 0)], [], true);

        $this->assertCount(1, $plans);
        $this->assertSame([], $plans[0]['finding']);
        $this->assertFalse($plans[0]['findings_missing']);
    }

    public function testNoJobsIsNoRows(): void
    {
        $this->assertSame([], ReviewRowAssembler::plan([], [], true));
    }
}
