<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ScanQueueStoreInterface.php";
require_once __DIR__ . "/ScanJobStateMachine.php";
require_once __DIR__ . "/RedcapEntityLoader.php";

/**
 * mica_scan_job access, in direct parameterized SQL rather than through the Entity framework.
 *
 * That is a deliberate departure from "no module-owned SQL", and it is narrow: **inserts** still go
 * through \REDCapEntity\EntityFactory so property validation and the framework's created/updated
 * bookkeeping apply, but the **claim** cannot. Entity's save path is read-modify-write, so two
 * workers would both read `status='queued'` and both write `status='scanning'`, and the same
 * transcript would be scanned twice - producing two sets of findings for one session. The claim
 * therefore has to be one atomic UPDATE, which the framework has no way to express
 * (02-data-model.md §1 makes the same call).
 *
 * The table name is a compile-time constant in this file, never a parameter, and every value is
 * bound. There is no user-controlled input in any statement here.
 */
class RedcapScanQueueStore implements ScanQueueStoreInterface
{
    private const TABLE = 'redcap_entity_mica_scan_job';
    private const ENTITY = 'mica_scan_job';

    /** Every column the queue reads. Named explicitly so a schema change surfaces here. */
    private const COLUMNS = 'id, project_id, record, instance, session_type, transcript_ref, '
                          . 'idempotency_key, status, attempts, next_attempt_at, claimed_by, '
                          . 'claimed_at, last_error, created, updated';

    private MICA $module;

    public function __construct(MICA $module)
    {
        $this->module = $module;
    }

    public function insertJob(array $data): ?int
    {
        // Not optional: on a survey page - which is where completeSession runs - nothing has
        // loaded redcap_entity, and naming its classes directly is a bare "Class not found" at the
        // worst possible moment (transcript written, scan job not).
        RedcapEntityLoader::ensureLoaded();

        $factory = new \REDCapEntity\EntityFactory();
        $entity = $factory->create(self::ENTITY, $data);

        if ($entity === false) {
            // Two very different causes land here, and telling them apart matters: a duplicate
            // idempotency_key is *success* (the scan is already queued), while a validation failure
            // or a missing table is a fault that must not be reported as success. The framework
            // gives no error code, so ask the database which it was.
            if ($this->findByIdempotencyKey((string) ($data['idempotency_key'] ?? '')) !== null) {
                return null;
            }

            throw new EntitySchemaException(sprintf(
                'Could not insert a scan job: %s. This is not a duplicate - no job exists with '
                . 'that idempotency key - so the transcript would be finalized and never scanned.',
                json_encode($factory->errors ?: 'no error detail from the Entity framework')
            ));
        }

        return (int) $entity->getId();
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        $result = $this->module->query(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE idempotency_key = ? LIMIT 1',
            [$key]
        );

        return $result->fetch_assoc() ?: null;
    }

    public function findJob(int $id): ?array
    {
        $result = $this->module->query(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . ' WHERE id = ?',
            [$id]
        );

        return $result->fetch_assoc() ?: null;
    }

    /**
     * One UPDATE that marks and selects at once, then a read back by claim token.
     *
     * The token is what makes the second statement safe: it is unique to this call, so the SELECT
     * can only return the row this UPDATE just took. Ordering by id makes the queue FIFO, so one
     * poisoned job cannot starve everything behind it forever - it fails, backs off, and the next
     * job goes ahead of it.
     */
    public function claim(string $claimToken, int $now): ?array
    {
        $this->module->query(
            'UPDATE ' . self::TABLE . ' SET status = ?, claimed_by = ?, claimed_at = ?, updated = ? '
            . 'WHERE status = ? AND next_attempt_at <= ? ORDER BY id LIMIT 1',
            [
                ScanJobStateMachine::SCANNING,
                $claimToken,
                $now,
                $now,
                ScanJobStateMachine::QUEUED,
                $now,
            ]
        );

        // Read back by token rather than trusting an affected-rows count: it is the same question
        // asked of the data rather than of the driver, and it returns the row we need anyway.
        $result = $this->module->query(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE
            . ' WHERE claimed_by = ? AND status = ? LIMIT 1',
            [$claimToken, ScanJobStateMachine::SCANNING]
        );

        return $result->fetch_assoc() ?: null;
    }

    public function updateJob(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        // Allowlisted, so a future caller cannot reach project_id, record or idempotency_key -
        // those identify which transcript a job belongs to and must never be edited in place.
        $writable = [
            'status', 'attempts', 'next_attempt_at', 'claimed_by', 'claimed_at', 'last_error',
        ];

        $sets = [];
        $values = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, $writable, true)) {
                throw new EntitySchemaException(
                    "Refusing to update $column on a scan job: only " . implode(', ', $writable)
                    . ' may change after a job is created.'
                );
            }
            $sets[] = "$column = ?";
            $values[] = $value;
        }

        $sets[] = 'updated = ?';
        $values[] = time();
        $values[] = $id;

        $this->module->query(
            'UPDATE ' . self::TABLE . ' SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $values
        );
    }

    public function findStaleClaims(int $cutoff, int $limit): array
    {
        $result = $this->module->query(
            'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE
            . ' WHERE status = ? AND (claimed_at IS NULL OR claimed_at <= ?) ORDER BY id LIMIT '
            . max(1, min(500, $limit)),
            [ScanJobStateMachine::SCANNING, $cutoff]
        );

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function countByStatus(): array
    {
        $result = $this->module->query(
            'SELECT status AS s, COUNT(*) AS n FROM ' . self::TABLE . ' GROUP BY status',
            []
        );

        $counts = [];
        while ($row = $result->fetch_assoc()) {
            $counts[(string) $row['s']] = (int) $row['n'];
        }

        return $counts;
    }
}
