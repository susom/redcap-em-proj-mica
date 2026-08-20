<?php

namespace Stanford\MICA;

require_once __DIR__ . "/EntitySchemaException.php";
require_once __DIR__ . "/NotificationResult.php";
require_once __DIR__ . "/NotificationService.php";
require_once __DIR__ . "/NotificationStoreInterface.php";
require_once __DIR__ . "/RedcapEntityLoader.php";
require_once __DIR__ . "/ReviewQueryStoreInterface.php";

/**
 * The notification trail in `redcap_entity_mica_notification`, append-only.
 *
 * ## At-least-once, not at-most-once
 *
 * The order is: check `alreadySent()`, send, then record. That is deliberately the weaker guarantee.
 * Claiming the row before sending would give at-most-once, and for a safety notification that is the
 * wrong target - a duplicate email about a confirmed critical finding is an annoyance, a dropped one
 * is a harm. So the read catches the ordinary case, and the UNIQUE index on `dedupe_key` demotes a
 * genuinely concurrent race from a *silent* duplicate to a *recorded* one: the second insert is
 * rejected, and this class writes the row again without claiming the key, flagged as a duplicate. The
 * mail is out either way; what the index buys is knowing about it.
 *
 * ## Why the digest aggregate is computed here
 *
 * `digestCounts()` reads the review queue and reduces it to counts before returning. Deriving it from
 * `queueRows()` reuses the join and the finding-shape handling that the dashboard already exercises,
 * rather than growing a second, less-tested SQL path over the same three tables.
 */
class RedcapNotificationStore implements NotificationStoreInterface
{
    private const ENTITY = 'mica_notification';
    private const TABLE  = 'redcap_entity_mica_notification';

    private MICA $module;
    private ReviewQueryStoreInterface $queue;

    public function __construct(MICA $module, ReviewQueryStoreInterface $queue)
    {
        $this->module = $module;
        $this->queue = $queue;
    }

    public function alreadySent(string $idempotencyKey): bool
    {
        $result = $this->module->query(
            'SELECT id FROM ' . self::TABLE . ' WHERE idempotency_key = ? AND status = ? LIMIT 1',
            [$idempotencyKey, NotificationResult::SENT]
        );

        return (bool) $result->fetch_assoc();
    }

    public function recordNotification(array $row): int
    {
        RedcapEntityLoader::ensureLoaded();

        $factory = new \REDCapEntity\EntityFactory();
        $entity = $factory->create(self::ENTITY, $row);

        if ($entity !== false) {
            return (int) $entity->getId();
        }

        // The likely cause of a rejected insert on a row that claims the dedupe key is that another
        // worker claimed it first. Retry without the claim so the attempt is still on the record -
        // losing the trail row would leave a sent email with nothing saying it was sent.
        if (($row['dedupe_key'] ?? '') !== '') {
            $row['dedupe_key'] = '';
            $row['error'] = trim(
                (string) ($row['error'] ?? '')
                . ' [duplicate: another worker recorded this same notification first, so this attempt '
                . 'was a concurrent double-send. The message did go out twice.]'
            );

            $retry = (new \REDCapEntity\EntityFactory())->create(self::ENTITY, $row);

            if ($retry !== false) {
                return (int) $retry->getId();
            }
        }

        throw new EntitySchemaException(
            'Could not write a notification row: '
            . json_encode($factory->errors ?: 'no error detail from the Entity framework')
        );
    }

    public function digestCounts(string $projectId, int $sinceTs, int $untilTs): array
    {
        $counts = array_fill_keys(NotificationService::DIGEST_BUCKETS, 0);
        $sessions = [];

        foreach ($this->queue->queueRows($projectId) as $row) {
            $created = (int) ($row['created'] ?? 0);

            if ($created < $sinceTs || $created > $untilTs) {
                continue;
            }

            // One job can produce several finding rows; a "session" is the job.
            $sessions[(int) ($row['job_id'] ?? 0)] = true;

            $isScanFailure = ($row['finding_concern_type'] ?? '') === 'scan_failure'
                || ($row['job_status'] ?? '') === ScanJobStateMachine::MANUAL_REVIEW_REQUIRED;

            if ($isScanFailure) {
                $counts['scan_failures']++;
            }

            if (($row['finding_id'] ?? null) === null) {
                continue;
            }

            $counts['findings_total']++;

            $status = (string) ($row['review_status'] ?? DispositionService::PENDING);
            $bucket = match ($status) {
                DispositionService::CONFIRMED           => 'confirmed',
                DispositionService::DISMISSED           => 'dismissed',
                DispositionService::NEEDS_SECOND_REVIEW => 'needs_second_review',
                default                                => 'pending_review',
            };
            $counts[$bucket]++;

            $urgency = (string) ($row['review_corrected_urgency'] ?? '') !== ''
                ? (string) $row['review_corrected_urgency']
                : (string) ($row['finding_urgency'] ?? '');

            if (isset($counts["urgency_$urgency"])) {
                $counts["urgency_$urgency"]++;
            }
        }

        $counts['sessions_scanned'] = count($sessions);
        $counts['overdue_acknowledgment'] = count($this->unacknowledged($projectId, $untilTs));

        return $counts;
    }

    /**
     * Notices that went out and were never acknowledged.
     *
     * A LEFT JOIN would be the obvious shape, but acknowledgment is recorded on the *notification*
     * row rather than on a second table, so this is one scan of one table. `notified_before` is a
     * cutoff rather than a window: something that has been waiting three days is more overdue than
     * something waiting an hour, not less, and a window would drop it.
     */
    public function unacknowledged(string $projectId, int $notifiedBeforeTs): array
    {
        $result = $this->module->query(
            'SELECT record, instance, event_id, subject, sent_at, notification_type '
            . 'FROM ' . self::TABLE . ' '
            . 'WHERE project_id = ? AND status = ? AND sent_at <= ? '
            . 'AND (acknowledged_at IS NULL OR acknowledged_at = 0) '
            . 'AND notification_type IN (?, ?) '
            . 'ORDER BY sent_at',
            [
                (int) $projectId,
                NotificationResult::SENT,
                $notifiedBeforeTs,
                NotificationService::REVIEWERS_READY,
                NotificationService::ACTION_DELIVERY,
            ]
        );

        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'record'      => (string) $row['record'],
                'instance'    => (int) $row['instance'],
                'event_id'    => (int) $row['event_id'],
                // The urgency is not on the notification row - the subject carries it, and the
                // subject is built from enum members only, so reading it back is safe.
                'urgency'     => $this->urgencyFromSubject((string) $row['subject']),
                'notified_at' => (int) $row['sent_at'],
                'type'        => (string) $row['notification_type'],
            ];
        }

        return $rows;
    }

    /** Record that someone acknowledged a notice. The one non-append write, and it only ever sets. */
    public function acknowledge(int $notificationId, string $username, int $now): void
    {
        $this->module->query(
            'UPDATE ' . self::TABLE . ' SET acknowledged_by = ?, acknowledged_at = ?, updated = ? '
            . 'WHERE id = ? AND (acknowledged_at IS NULL OR acknowledged_at = 0)',
            [$username, $now, $now, $notificationId]
        );
    }

    private function urgencyFromSubject(string $subject): string
    {
        foreach (['critical', 'high', 'moderate', 'quality'] as $urgency) {
            if (str_contains($subject, $urgency)) {
                return $urgency;
            }
        }

        return NotificationService::UNSPECIFIED;
    }
}
