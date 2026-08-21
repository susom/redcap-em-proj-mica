<?php

namespace Stanford\MICA;

/**
 * Where a scan attempt's record and its findings go.
 *
 * Two stores behind one seam, because they are written in the same breath and their failure modes
 * are coupled: the `mica_scan_run` row is the *authoritative immutable* copy of the model output
 * (02-data-model.md §1), and the REDCap finding instances are the review working copy derived from
 * it. If the working copy cannot be written, the authoritative copy still exists - which is what
 * makes it safe to fail the attempt to manual review rather than lose the scan.
 */
interface ScanResultStoreInterface
{
    /**
     * Insert one `mica_scan_run` row. Insert-only: a retry is a new row, never an edit, so the
     * attempt history is the audit trail.
     *
     * @param array<string,mixed> $data
     * @return int the entity row id
     */
    public function insertRun(array $data): int;

    /**
     * Whether the repeating `mica_safety_finding` instrument exists on this project.
     *
     * Asked before writing rather than discovered from a saveData error, because the two outcomes
     * are completely different: a missing instrument is a setup step (audit G5 - it does not exist
     * on PID 257, and no repeating instruments are configured there at all), while a rejected save
     * is a data problem.
     */
    public function findingInstrumentExists(string $projectId): bool;

    /**
     * The next free `redcap_repeat_instance` for the finding instrument on this record/event.
     */
    public function nextFindingInstance(string $projectId, string $record, int $eventId): int;

    /**
     * @param list<array<string,string>> $instances field=>value per instance, in order
     * @throws TranscriptException when saveData reports any error - partial writes are not accepted
     */
    public function writeFindingInstances(
        string $projectId,
        string $record,
        int $eventId,
        int $firstInstance,
        array $instances
    ): void;

    /** Existing finding_id values on this project, so a UUID collision is caught before writing. */
    public function existingFindingIds(string $projectId, string $record): array;

    /**
     * Has this job already released findings?
     *
     * Asked of the data rather than of the job's status, because the status is exactly what an
     * operator changes when they re-queue a finished job by hand. The cron never re-runs a settled
     * job - it only claims `queued` - so this can only be reached deliberately, and somebody
     * recovering from a failure plausibly does it. Without the check they silently double their
     * queue: every finding released twice, with no dedupe anywhere in the write path.
     */
    public function findingsReleasedForJob(string $projectId, string $record, int $jobId): bool;
}
