<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\DispositionService as D;
use Stanford\MICA\FindingWriter;
use Stanford\MICA\ReviewQueue as Q;
use Stanford\MICA\ScanJobStateMachine as SM;

/**
 * The queue order is a safety feature, not presentation: an RA works down the list, so what sits at
 * the top is what gets attention when there is not enough attention to go round.
 */
#[CoversClass(Q::class)]
final class ReviewQueueTest extends TestCase
{
    private const DAY = 86400;

    private function row(array $overrides = []): array
    {
        return $overrides + [
            'finding_id'           => 'f' . random_int(1000, 9999),
            'finding_urgency'      => 'moderate',
            'finding_concern_type' => 'dangerous_alcohol_use',
            'review_status'        => D::PENDING,
            'review_reviewer'      => '',
            'session_type'         => 'baseline',
            'job_status'           => SM::READY_FOR_REVIEW,
            'created'              => 1_700_000_000,
        ];
    }

    /** @return string[] the finding ids in queue order */
    private function order(array $rows): array
    {
        return array_column(Q::sort($rows), 'finding_id');
    }

    public function testUrgencySortsCriticalFirst(): void
    {
        $rows = [
            $this->row(['finding_id' => 'quality', 'finding_urgency' => 'quality']),
            $this->row(['finding_id' => 'critical', 'finding_urgency' => 'critical']),
            $this->row(['finding_id' => 'moderate', 'finding_urgency' => 'moderate']),
            $this->row(['finding_id' => 'high', 'finding_urgency' => 'high']),
        ];

        $this->assertSame(['critical', 'high', 'moderate', 'quality'], $this->order($rows));
    }

    public function testAnUnscreenedSessionOutranksEvenACriticalFinding(): void
    {
        // The critical one has been read by something. The failed one has been read by nothing -
        // and it has no model urgency to sort by, so sorting it by urgency would bury it.
        $rows = [
            $this->row(['finding_id' => 'critical', 'finding_urgency' => 'critical']),
            $this->row([
                'finding_id'           => 'unscreened',
                'finding_urgency'      => 'high',
                'finding_concern_type' => FindingWriter::SCAN_FAILURE,
                'job_status'           => SM::MANUAL_REVIEW_REQUIRED,
            ]),
        ];

        $this->assertSame(['unscreened', 'critical'], $this->order($rows));
    }

    public function testAJobInManualReviewIsPinnedEvenWithoutAPlaceholder(): void
    {
        // The placeholder write can fail independently (a missing instrument, a refused save), and
        // the job status alone still has to pin it.
        $rows = [
            $this->row(['finding_id' => 'critical', 'finding_urgency' => 'critical']),
            $this->row(['finding_id' => 'failed_job', 'job_status' => SM::MANUAL_REVIEW_REQUIRED]),
        ];

        $this->assertSame(['failed_job', 'critical'], $this->order($rows));
    }

    public function testOldestFirstWithinAnUrgency(): void
    {
        // Newest-first is the usual habit and is wrong here: it means a moderate finding from three
        // weeks ago sits permanently below today's and never gets looked at at all.
        $rows = [
            $this->row(['finding_id' => 'today', 'created' => 1_700_000_000]),
            $this->row(['finding_id' => 'three_weeks_ago', 'created' => 1_700_000_000 - 21 * self::DAY]),
            $this->row(['finding_id' => 'yesterday', 'created' => 1_700_000_000 - self::DAY]),
        ];

        $this->assertSame(['three_weeks_ago', 'yesterday', 'today'], $this->order($rows));
    }

    public function testUrgencyBeatsAge(): void
    {
        $rows = [
            $this->row([
                'finding_id'      => 'old_quality',
                'finding_urgency' => 'quality',
                'created'         => 1_700_000_000 - 90 * self::DAY,
            ]),
            $this->row(['finding_id' => 'new_critical', 'finding_urgency' => 'critical']),
        ];

        $this->assertSame(['new_critical', 'old_quality'], $this->order($rows));
    }

    public function testReviewedItemsSortLastRegardlessOfUrgency(): void
    {
        // Kept in the queue so a reviewer can see their own recent work, but they never compete
        // with something pending.
        $rows = [
            $this->row([
                'finding_id'      => 'confirmed_critical',
                'finding_urgency' => 'critical',
                'review_status'   => D::CONFIRMED,
            ]),
            $this->row(['finding_id' => 'pending_quality', 'finding_urgency' => 'quality']),
        ];

        $this->assertSame(['pending_quality', 'confirmed_critical'], $this->order($rows));
    }

    public function testNeedsSecondReviewCountsAsPending(): void
    {
        // It is explicitly waiting for somebody, so burying it with the finished work would lose it.
        $rows = [
            $this->row([
                'finding_id'      => 'dismissed',
                'finding_urgency' => 'critical',
                'review_status'   => D::DISMISSED,
            ]),
            $this->row([
                'finding_id'      => 'second_review',
                'finding_urgency' => 'quality',
                'review_status'   => D::NEEDS_SECOND_REVIEW,
            ]),
        ];

        $this->assertSame(['second_review', 'dismissed'], $this->order($rows));
    }

    public function testAnUnknownUrgencySortsLastRatherThanFirst(): void
    {
        $rows = [
            $this->row(['finding_id' => 'weird', 'finding_urgency' => 'catastrophic']),
            $this->row(['finding_id' => 'quality', 'finding_urgency' => 'quality']),
        ];

        $this->assertSame(['quality', 'weird'], $this->order($rows));
    }

    public function testTheOrderIsStableAcrossRepeatedSorts(): void
    {
        // Otherwise the queue shuffles on every refresh, and a reviewer loses their place.
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = $this->row(['finding_id' => "f$i", 'created' => 1_700_000_000]);
        }

        $first = $this->order($rows);
        $this->assertSame($first, $this->order(Q::sort($rows)));
        $this->assertSame($first, $this->order($rows));
    }

    public function testSortingAnEmptyQueueIsFine(): void
    {
        $this->assertSame([], Q::sort([]));
    }

    // ------------------------------------------------------------------ filters

    public function testNoFiltersReturnsEverything(): void
    {
        $rows = [$this->row(), $this->row()];

        $this->assertCount(2, Q::filter($rows, []));
        $this->assertCount(2, Q::filter($rows, ['urgency' => '', 'concern_type' => []]));
    }

    public function testASingleValueFilter(): void
    {
        $rows = [
            $this->row(['finding_id' => 'a', 'finding_urgency' => 'critical']),
            $this->row(['finding_id' => 'b', 'finding_urgency' => 'moderate']),
        ];

        $this->assertSame(['a'], array_column(Q::filter($rows, ['urgency' => 'critical']), 'finding_id'));
    }

    public function testAMultiSelectFilter(): void
    {
        $rows = [
            $this->row(['finding_id' => 'a', 'finding_urgency' => 'critical']),
            $this->row(['finding_id' => 'b', 'finding_urgency' => 'moderate']),
            $this->row(['finding_id' => 'c', 'finding_urgency' => 'high']),
        ];

        $this->assertSame(
            ['a', 'c'],
            array_column(Q::filter($rows, ['urgency' => ['critical', 'high']]), 'finding_id')
        );
    }

    public function testFiltersCombineWithAnd(): void
    {
        $rows = [
            $this->row(['finding_id' => 'a', 'finding_urgency' => 'critical', 'session_type' => 'baseline']),
            $this->row(['finding_id' => 'b', 'finding_urgency' => 'critical', 'session_type' => 'booster']),
        ];

        $this->assertSame(
            ['b'],
            array_column(
                Q::filter($rows, ['urgency' => 'critical', 'session_type' => 'booster']),
                'finding_id'
            )
        );
    }

    public function testUnreviewedOnlyKeepsNeedsSecondReview(): void
    {
        // The filter means "what still needs me", not "what has never been opened".
        $rows = [
            $this->row(['finding_id' => 'pending', 'review_status' => D::PENDING]),
            $this->row(['finding_id' => 'second', 'review_status' => D::NEEDS_SECOND_REVIEW]),
            $this->row(['finding_id' => 'confirmed', 'review_status' => D::CONFIRMED]),
            $this->row(['finding_id' => 'dismissed', 'review_status' => D::DISMISSED]),
        ];

        $this->assertSame(
            ['pending', 'second'],
            array_column(Q::filter($rows, ['unreviewed_only' => true]), 'finding_id')
        );
    }

    public function testTheReviewerFilterIsCaseInsensitive(): void
    {
        $rows = [$this->row(['finding_id' => 'a', 'review_reviewer' => 'RA_Alice'])];

        $this->assertCount(1, Q::filter($rows, ['reviewer' => 'ra_alice']));
    }

    public function testTheDateRangeIncludesTheWholeEndDay(): void
    {
        // An exclusive upper bound silently drops a whole day's findings, which a reviewer filtering
        // "to yesterday" would never notice.
        $endOfDay = strtotime('2026-08-19 23:59:00');

        $rows = [$this->row(['finding_id' => 'late', 'created' => $endOfDay])];

        $this->assertCount(1, Q::filter($rows, ['to' => '2026-08-19']));
        $this->assertCount(0, Q::filter($rows, ['to' => '2026-08-18']));
    }

    public function testTheDateRangeLowerBoundIsInclusive(): void
    {
        $rows = [$this->row(['finding_id' => 'a', 'created' => strtotime('2026-08-19 00:00:00')])];

        $this->assertCount(1, Q::filter($rows, ['from' => '2026-08-19']));
        $this->assertCount(0, Q::filter($rows, ['from' => '2026-08-20']));
    }

    public function testAnUnparseableDateIsRefusedNotSilentlyIgnored(): void
    {
        // 1970 would match everything and today would match nothing - both wrong in a way nobody
        // would notice.
        $this->expectException(\InvalidArgumentException::class);
        Q::filter([$this->row()], ['from' => 'last tuesday-ish']);
    }

    public function testAnUnparseableDateIsRefusedOnAnEmptyQueueToo(): void
    {
        // array_filter never invokes its callback on an empty set, so validating inside the
        // predicate meant a bad date silently succeeded when there was nothing to filter - and the
        // reviewer would see an empty result and trust it. Validated up front now.
        $this->expectException(\InvalidArgumentException::class);
        Q::filter([], ['to' => 'not a date']);
    }

    public function testAnUnknownFilterMatchesNothing(): void
    {
        // A typo'd filter name that silently widened the result set would be a privacy problem, not
        // a UI annoyance. FILTERS is the allowlist, so an unknown name is simply not applied - but
        // if one ever reaches matches(), it must not pass everything.
        $rows = [$this->row()];

        $this->assertCount(1, Q::filter($rows, ['not_a_filter' => 'x']), 'unlisted names are ignored');
        $this->assertNotContains('not_a_filter', Q::FILTERS);
    }

    // ------------------------------------------------------------------ summary

    public function testTheSummaryCountsWhatAQueueHeaderNeeds(): void
    {
        $rows = [
            $this->row(['finding_urgency' => 'critical']),
            $this->row(['finding_urgency' => 'high', 'review_status' => D::CONFIRMED]),
            $this->row(['finding_urgency' => 'moderate', 'review_status' => D::DISMISSED]),
            $this->row(['finding_urgency' => 'quality', 'review_status' => D::NEEDS_SECOND_REVIEW]),
            $this->row(['job_status' => SM::MANUAL_REVIEW_REQUIRED]),
        ];

        $counts = Q::summarise($rows);

        $this->assertSame(5, $counts['total']);
        $this->assertSame(3, $counts['awaiting_review'], 'pending + needs_second + unscreened');
        $this->assertSame(1, $counts['unscreened']);
        $this->assertSame(1, $counts['critical']);
        $this->assertSame(1, $counts['high']);
        $this->assertSame(1, $counts['confirmed']);
        $this->assertSame(1, $counts['dismissed']);
        $this->assertSame(1, $counts['needs_second']);
    }

    public function testTheSummaryOfAnEmptyQueueIsAllZeroes(): void
    {
        foreach (Q::summarise([]) as $key => $value) {
            $this->assertSame(0, $value, "$key should be 0");
        }
    }

    public function testTheSummaryCarriesNoParticipantData(): void
    {
        // It is what the auditor's de-identified view is built from, so it must be counts only.
        $counts = Q::summarise([$this->row(['record' => '104729'])]);

        foreach ($counts as $value) {
            $this->assertIsInt($value);
        }
        $this->assertStringNotContainsString('104729', json_encode($counts));
    }
}
