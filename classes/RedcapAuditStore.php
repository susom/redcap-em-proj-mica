<?php

namespace Stanford\MICA;

require_once __DIR__ . "/AuditStoreInterface.php";
require_once __DIR__ . "/RedcapEntityLoader.php";

/**
 * Append-only writes to `redcap_entity_mica_audit_event`.
 *
 * Insert-only by discipline, as the data model requires: there is no update path in this class and
 * none is wanted. An audit trail that can be edited is not one.
 */
class RedcapAuditStore implements AuditStoreInterface
{
    private const ENTITY = 'mica_audit_event';

    private MICA $module;

    public function __construct(MICA $module)
    {
        $this->module = $module;
    }

    public function insertAuditEvent(array $data): int
    {
        RedcapEntityLoader::ensureLoaded();

        $factory = new \REDCapEntity\EntityFactory();

        // `details` is declared as the `json` entity type, which Entity::setData() encodes for us -
        // but only when the value is not already a JSON string. Passing the array is correct.
        $entity = $factory->create(self::ENTITY, $data);

        if ($entity === false) {
            throw new EntitySchemaException(
                'Could not write an audit event: '
                . json_encode($factory->errors ?: 'no error detail from the Entity framework')
            );
        }

        return (int) $entity->getId();
    }
}
