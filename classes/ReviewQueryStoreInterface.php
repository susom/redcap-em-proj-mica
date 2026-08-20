<?php

namespace Stanford\MICA;

/**
 * The dashboard's reads.
 *
 * Separate from FindingReviewStoreInterface on purpose: that one is the disposition's single-row
 * read/write and stays small enough to fake on a screen, whereas these are the wide joins the queue
 * and session views need. Keeping them apart is what let the locking logic be tested without a
 * queue-shaped fake attached.
 */
interface ReviewQueryStoreInterface
{
    /**
     * One row per finding, plus one per job that has no findings (a clean screen or a failed scan) -
     * because a session that produced nothing still has to be visible. That is the difference
     * between "nothing was found" and "nobody looked".
     *
     * @return list<array<string,mixed>>
     */
    public function queueRows(string $projectId): array;

    /**
     * Everything the session view needs: the transcript, its findings, and the scan-run metadata.
     *
     * The scan run's `run_status` is carried alongside the JOB's status and `last_error`, because
     * those answer different questions - an `ok` run under a job in manual_review_required is a scan
     * that worked and a release that did not, and showing the run row alone would read as a
     * completed review (see ScanRunner's class comment).
     *
     * @return array<string,mixed>|null
     */
    public function session(string $projectId, string $record, int $eventId, int $instance): ?array;

    /**
     * Every session, including zero-finding and failed ones. Same shape as the queue.
     *
     * @return list<array<string,mixed>>
     */
    public function historyRows(string $projectId): array;

    /** @return list<array<string,mixed>> most recent first */
    public function auditEvents(string $projectId, int $limit): array;
}
