<?php

namespace Stanford\MICA;

/**
 * Append-only access to `mica_audit_event`.
 *
 * One method, because that is genuinely all the writer needs. Reading the trail is the dashboard's
 * `auditTrail` endpoint and goes through its own query with its own filters and paging - putting
 * both behind one interface would mean every audit *write* dragged a read API along with it.
 */
interface AuditStoreInterface
{
    /**
     * @param array<string,mixed> $data
     * @return int the new entity row id
     * @throws EntitySchemaException when the row cannot be written
     */
    public function insertAuditEvent(array $data): int;
}
