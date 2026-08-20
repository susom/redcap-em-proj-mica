<?php

namespace Stanford\MICA;

/**
 * Everything EntitySchemaManager needs from REDCap, and nothing else.
 *
 * This exists so the migration logic - the part with branches worth testing - runs under PHPUnit
 * with no database, no session and no project (06-implementation-plan/README.md, cross-cutting
 * decision 1). RedcapEntityPlatform is the real implementation; the suite uses a fake.
 *
 * The split is drawn at "statements about the world" rather than at "SQL": the manager decides
 * *whether* an index is missing and *which* to add, the platform only answers questions and
 * executes. That keeps the untestable half free of conditionals.
 */
interface EntityPlatformInterface
{
    /** Version string of the redcap_entity module, or null when it is absent or disabled. */
    public function entityModuleVersion(): ?string;

    /**
     * Create any not-yet-existing tables for this module's declared entity types.
     *
     * Wraps \REDCapEntity\EntityDB::buildSchema(), which is CREATE TABLE IF NOT EXISTS per type
     * and therefore safe to call repeatedly. It reports nothing on failure, which is why
     * EntitySchemaManager verifies the tables afterwards rather than trusting the call.
     */
    public function buildSchema(): void;

    public function tableExists(string $table): bool;

    /** @return string[] names of the indexes currently on $table (empty when it has none) */
    public function indexNames(string $table): array;

    /**
     * @param string[] $columns
     * @throws EntitySchemaException when the ALTER TABLE fails
     */
    public function addIndex(string $table, string $name, array $columns, bool $unique): void;

    /** The recorded schema version, or null when the schema has never been built. */
    public function schemaVersion(): ?string;

    public function recordSchemaVersion(string $version): void;

    /** Structured log line; the real implementation routes to emLogger. */
    public function log(string $message): void;
}
