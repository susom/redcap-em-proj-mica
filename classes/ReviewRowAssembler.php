<?php

namespace Stanford\MICA;

/**
 * Which findings belong to which scan job, and which jobs a reviewer should be shown.
 *
 * Pure, because it is the part that was wrong and the store around it needs a database to run
 * (cross-cutting decision 1: thin hooks, testable core).
 *
 * ## The defect this replaces
 *
 * The queue used to key findings by `record|event_id` and attach them to **every** job for that
 * record and event, so the row count was `jobs x findings` rather than `findings`. A session scanned
 * twice showed each of its findings twice; scanned three times, three times. Worse than
 * duplication, the copies were **misattributed** - a job was shown findings produced by a different
 * scan run, so the technical detail on those rows was a false audit trail.
 *
 * Every finding instance carries `finding_scan_run`, which says exactly which run produced it. That
 * is the join key, and it always was; the query simply never read the field.
 *
 * ## Two rules
 *
 * 1. **A finding belongs to the run that wrote it.** Nothing else attaches.
 * 2. **The queue shows the latest scan of a session; history shows them all.** Re-finalizing a
 *    session creates a new transcript version and a new job by design, so several jobs per session
 *    is normal, not corruption. The queue is an RA work list and a superseded scan is not work - it
 *    stays visible in history, flagged, so the record of what was scanned when is not lost.
 *
 * A session is `record|event_id|instance`, and "latest" is the highest job id - monotonic, and
 * unlike `created` it cannot tie.
 *
 * ## The lie this refuses to tell
 *
 * A job whose run reported findings, but whose finding instances are not in the data, is **not a
 * clean screen** - and rendering it as one is the single failure mode this whole pipeline exists to
 * prevent. It happens when the REDCap data is destroyed while the module's entity tables survive:
 * "Erase all data" and record deletion both clear `redcap_data` and neither touches
 * `redcap_entity_mica_scan_job`, so the job outlives the findings it produced. Such a row is marked
 * `findings_missing` rather than being silently downgraded to "screened, nothing found".
 */
class ReviewRowAssembler
{
    /**
     * @param list<array<string,mixed>> $jobs each needs job_id, record, event_id, instance,
     *        scan_run_id, expected_findings (how many the run reported, 0 if it reported none)
     * @param array<string,list<array<string,string>>> $findingsByRun finding instances keyed by the
     *        `finding_scan_run` value that produced them
     * @param bool $queueOnly true drops superseded scans (the queue); false keeps them (history)
     * @return list<array{job:array<string,mixed>,finding:array<string,string>,superseded:bool,findings_missing:bool}>
     */
    public static function plan(array $jobs, array $findingsByRun, bool $queueOnly): array
    {
        $latest = self::latestJobPerSession($jobs);
        $plans = [];

        foreach ($jobs as $job) {
            $jobId = (int) ($job['job_id'] ?? 0);
            $superseded = ($latest[self::sessionKey($job)] ?? $jobId) !== $jobId;

            if ($superseded && $queueOnly) {
                continue;
            }

            // Rule 1. A null run id means the job never produced one (queued, or claimed and never
            // finished), and null is not a key - such a job has no findings by definition.
            $runId = $job['scan_run_id'] ?? null;
            $findings = $runId === null ? [] : ($findingsByRun[(string) $runId] ?? []);

            if ($findings !== []) {
                foreach ($findings as $finding) {
                    $plans[] = [
                        'job'              => $job,
                        'finding'          => $finding,
                        'superseded'       => $superseded,
                        'findings_missing' => false,
                    ];
                }
                continue;
            }

            $plans[] = [
                'job'        => $job,
                'finding'    => [],
                'superseded' => $superseded,
                // Expected some, found none. See the class comment: not a clean screen.
                'findings_missing' => ((int) ($job['expected_findings'] ?? 0)) > 0,
            ];
        }

        return $plans;
    }

    /**
     * @param list<array<string,mixed>> $jobs
     * @return array<string,int> session key => highest job id
     */
    private static function latestJobPerSession(array $jobs): array
    {
        $latest = [];

        foreach ($jobs as $job) {
            $key = self::sessionKey($job);
            $jobId = (int) ($job['job_id'] ?? 0);

            if (!isset($latest[$key]) || $jobId > $latest[$key]) {
                $latest[$key] = $jobId;
            }
        }

        return $latest;
    }

    /** @param array<string,mixed> $job */
    private static function sessionKey(array $job): string
    {
        return ($job['record'] ?? '') . '|' . (int) ($job['event_id'] ?? 0)
            . '|' . (int) ($job['instance'] ?? 1);
    }
}
