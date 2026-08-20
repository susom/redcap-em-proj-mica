<?php

namespace Stanford\MICA;

require_once __DIR__ . "/DispositionService.php";
require_once __DIR__ . "/FindingWriter.php";
require_once __DIR__ . "/ScanJobStateMachine.php";

/**
 * The order and the filters of the RA queue - pure, so the one thing that decides what a reviewer
 * sees first is testable without a database.
 *
 * ## Why the order is the safety feature
 *
 * An RA works down a list. What sits at the top is what gets attention when there is not enough
 * attention to go round, so the ordering is not presentation:
 *
 *   1. **`manual_review_required` is pinned to the top, above `critical`.** A session the scanner
 *      could not screen is a bigger unknown than a finding it *did* screen and rated critical: the
 *      critical one has been read by something, and the failed one has been read by nothing. Sorting
 *      it by urgency would bury it, because a failed scan has no model urgency to sort by.
 *   2. then by urgency, critical first;
 *   3. then **oldest first** within an urgency. Newest-first is the usual habit and is wrong here -
 *      it means a moderate finding from three weeks ago is permanently below today's, and never
 *      gets looked at at all.
 *
 * Reviewed items sort last regardless. They are kept in the queue rather than hidden so a reviewer
 * can see their own recent work, but they never compete with something pending.
 */
class ReviewQueue
{
    /** High number = looked at first. */
    private const URGENCY_RANK = [
        'critical' => 40,
        'high'     => 30,
        'moderate' => 20,
        'quality'  => 10,
    ];

    /** Above every urgency: see the class comment. */
    private const UNSCREENED_RANK = 100;

    public const FILTERS = [
        'urgency',
        'concern_type',
        'review_status',
        'session_type',
        'job_status',
        'reviewer',
        'from',
        'to',
        'unreviewed_only',
    ];

    /**
     * @param list<array<string,mixed>> $rows queue entries, in any order
     * @return list<array<string,mixed>>
     */
    public static function sort(array $rows): array
    {
        // usort is not stable in the way this needs (equal keys could reorder between runs, making
        // the queue shuffle on refresh), so the position is part of the key.
        $indexed = [];
        foreach (array_values($rows) as $i => $row) {
            $indexed[] = ['row' => $row, 'i' => $i];
        }

        usort($indexed, static function (array $a, array $b): int {
            $rankA = self::rank($a['row']);
            $rankB = self::rank($b['row']);

            // Pending before reviewed, always.
            if ($rankA['reviewed'] !== $rankB['reviewed']) {
                return $rankA['reviewed'] <=> $rankB['reviewed'];
            }

            if ($rankA['priority'] !== $rankB['priority']) {
                return $rankB['priority'] <=> $rankA['priority'];
            }

            // Oldest first inside a priority band - see the class comment on why not newest.
            if ($rankA['age'] !== $rankB['age']) {
                return $rankA['age'] <=> $rankB['age'];
            }

            return $a['i'] <=> $b['i'];
        });

        return array_column($indexed, 'row');
    }

    /** @param array<string,mixed> $row */
    private static function rank(array $row): array
    {
        $jobStatus = (string) ($row['job_status'] ?? '');
        $concern = (string) ($row['finding_concern_type'] ?? '');
        $urgency = (string) ($row['finding_urgency'] ?? '');
        $review = (string) ($row['review_status'] ?? DispositionService::PENDING);

        $unscreened = $jobStatus === ScanJobStateMachine::MANUAL_REVIEW_REQUIRED
            || $concern === FindingWriter::SCAN_FAILURE;

        return [
            // `needs_second_review` counts as pending: it is explicitly waiting for somebody.
            'reviewed' => in_array($review, [DispositionService::CONFIRMED, DispositionService::DISMISSED], true)
                ? 1
                : 0,
            'priority' => $unscreened
                ? self::UNSCREENED_RANK
                : (self::URGENCY_RANK[$urgency] ?? 0),
            'age'      => (int) ($row['created'] ?? 0),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>       $filters
     * @return list<array<string,mixed>>
     */
    public static function filter(array $rows, array $filters): array
    {
        $active = [];
        foreach (self::FILTERS as $name) {
            $value = $filters[$name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $active[$name] = $value;
        }

        // Validated before anything is applied, so a malformed filter behaves the same on an empty
        // queue as on a full one. Otherwise `array_filter` never invokes the callback on an empty
        // set, a bad date silently succeeds, and the reviewer only discovers it once there is
        // something to hide - which is exactly when they would trust the empty result.
        foreach (['from', 'to'] as $dateFilter) {
            if (isset($active[$dateFilter])) {
                self::toEpoch((string) $active[$dateFilter]);
            }
        }

        if ($active === []) {
            return array_values($rows);
        }

        return array_values(array_filter($rows, static function (array $row) use ($active): bool {
            foreach ($active as $name => $value) {
                if (!self::matches($row, $name, $value)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @param array<string,mixed> $row */
    private static function matches(array $row, string $filter, $value): bool
    {
        switch ($filter) {
            case 'unreviewed_only':
                // Anything a human has not finished with, which includes needs_second_review - the
                // point of the filter is "what still needs me", not "what has never been opened".
                return !in_array(
                    (string) ($row['review_status'] ?? DispositionService::PENDING),
                    [DispositionService::CONFIRMED, DispositionService::DISMISSED],
                    true
                );

            case 'from':
                return (int) ($row['created'] ?? 0) >= self::toEpoch((string) $value);

            case 'to':
                // Inclusive of the whole day: a reviewer filtering "to yesterday" means the end of
                // yesterday, and an exclusive bound silently drops a day's findings.
                return (int) ($row['created'] ?? 0) <= self::toEpoch((string) $value) + 86399;

            case 'reviewer':
                return strcasecmp((string) ($row['review_reviewer'] ?? ''), (string) $value) === 0;

            case 'urgency':
                return self::in($row['finding_urgency'] ?? '', $value);

            case 'concern_type':
                return self::in($row['finding_concern_type'] ?? '', $value);

            case 'review_status':
                return self::in($row['review_status'] ?? DispositionService::PENDING, $value);

            case 'session_type':
                return self::in($row['session_type'] ?? '', $value);

            case 'job_status':
                return self::in($row['job_status'] ?? '', $value);
        }

        // An unknown filter matches nothing rather than everything: a typo'd filter name that
        // silently widened the result set would be a privacy problem, not a UI annoyance.
        return false;
    }

    /** Multi-select filters arrive as arrays; a single choice as a scalar. */
    private static function in($actual, $wanted): bool
    {
        $actual = (string) $actual;

        return is_array($wanted)
            ? in_array($actual, array_map('strval', $wanted), true)
            : $actual === (string) $wanted;
    }

    private static function toEpoch(string $date): int
    {
        // A date the client could not parse must not silently become 1970 (which matches
        // everything) or today (which matches nothing). Both are wrong in a way nobody would notice.
        $ts = strtotime($date . ' 00:00:00');

        if ($ts === false) {
            throw new \InvalidArgumentException("\"$date\" is not a date this filter can use.");
        }

        return $ts;
    }

    /**
     * Aggregate counts for the queue header, and for the auditor's de-identified view.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,int>
     */
    public static function summarise(array $rows): array
    {
        $counts = [
            'total'            => count($rows),
            'awaiting_review'  => 0,
            'unscreened'       => 0,
            'critical'         => 0,
            'high'             => 0,
            'confirmed'        => 0,
            'dismissed'        => 0,
            'needs_second'     => 0,
        ];

        foreach ($rows as $row) {
            $rank = self::rank($row);
            $review = (string) ($row['review_status'] ?? DispositionService::PENDING);

            if ($rank['reviewed'] === 0) {
                $counts['awaiting_review']++;
            }
            if ($rank['priority'] === self::UNSCREENED_RANK) {
                $counts['unscreened']++;
            }
            if (($row['finding_urgency'] ?? '') === 'critical') {
                $counts['critical']++;
            }
            if (($row['finding_urgency'] ?? '') === 'high') {
                $counts['high']++;
            }
            if ($review === DispositionService::CONFIRMED) {
                $counts['confirmed']++;
            }
            if ($review === DispositionService::DISMISSED) {
                $counts['dismissed']++;
            }
            if ($review === DispositionService::NEEDS_SECOND_REVIEW) {
                $counts['needs_second']++;
            }
        }

        return $counts;
    }
}
