<?php

namespace Stanford\MICA;

require_once __DIR__ . "/TranscriptException.php";

/**
 * The project facts REDCap only exposes through request globals, resolved from a project id instead.
 *
 * Two of them, both hit for the same reason and both found the same way - by running the scan worker
 * from cron for real:
 *
 * `\REDCap::getRecordIdField()` takes no project id: it calls `checkProjectContext()` and then
 * returns the `$table_pk` **global**. So it works on a page request and throws
 * *"can only be used in a project context"* anywhere `PROJECT_ID` is undefined - which is every cron
 * run.
 *
 * That was not theoretical. The scan worker runs from cron, and it is the thing that writes findings:
 * on PID 257 a scan completed, produced schema-valid and quote-verified findings, and then died with
 * that exact message - so the job went to `manual_review_required` for a reason that had nothing to
 * do with the session. Every verification script missed it because they all set `$_GET['pid']` before
 * `redcap_connect`, which defines PROJECT_ID and makes the global path work.
 *
 * `\REDCap::getEventNames()` is the same story: `checkProjectContext()`, then the `$Proj` global.
 *
 * `Project::$table_pk` and `Project::getUniqueEventNames()` are the same values read the same way
 * REDCap reads them, but keyed on a project id that is passed in rather than inherited from the
 * request. Memoised because a `Project` construction is not free and one scan pass resolves both
 * several times.
 */
class ProjectContext
{
    /** @var array<int,string> project id => record-id field name */
    private static array $cache = [];

    /** @var array<int,\Project> memoised projects */
    private static array $projects = [];

    /**
     * @throws TranscriptException when the project has no primary key, which would otherwise become
     *                             a saveData payload keyed on an empty string
     */
    public static function for(string|int $projectId): string
    {
        $pid = (int) $projectId;

        if (isset(self::$cache[$pid])) {
            return self::$cache[$pid];
        }

        $field = null;

        try {
            $project = self::project($pid);
            $field = is_string($project->table_pk) && $project->table_pk !== ''
                ? $project->table_pk
                : null;
        } catch (\Throwable $e) {
            $field = null;
        }

        if ($field === null) {
            throw new TranscriptException(
                "Could not determine the record-id field for project $pid, so nothing was written. "
                . 'This is a project-configuration problem rather than anything about the session.'
            );
        }

        return self::$cache[$pid] = $field;
    }

    /**
     * The unique event name for an event id, without needing PROJECT_ID.
     *
     * Returns '' for event 0 rather than throwing: a scan job written before `event_id` existed on
     * the queue carries 0, and REDCap treats an empty `redcap_event_name` as "the only event", which
     * is the correct behaviour for a classic project.
     */
    public static function uniqueEventName(string|int $projectId, int $eventId): string
    {
        if ($eventId <= 0) {
            return '';
        }

        try {
            $name = self::project((int) $projectId)->getUniqueEventNames($eventId);
        } catch (\Throwable $e) {
            $name = null;
        }

        if (!is_string($name) || $name === '') {
            throw new TranscriptException(sprintf(
                'Event %d is not an event on project %s, so nothing was written.',
                $eventId,
                (string) $projectId
            ));
        }

        return $name;
    }

    private static function project(int $pid): \Project
    {
        return self::$projects[$pid] ??= new \Project($pid);
    }

    /** Test seam. Never called in production. */
    public static function reset(): void
    {
        self::$cache = [];
        self::$projects = [];
    }
}
