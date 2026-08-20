<?php

namespace Stanford\MICA;

require_once __DIR__ . "/EntitySchemaException.php";
require_once __DIR__ . "/EntityPlatformInterface.php";
require_once __DIR__ . "/EntityTypes.php";

/**
 * Owns the lifecycle of this module's four entity tables: the dependency check, the build call,
 * and the index migration that redcap_entity does not do for us.
 *
 * The module ships no hand-written CREATE TABLE (02-data-model.md §1) - redcap_entity owns the
 * tables. But it creates *no secondary indexes and no UNIQUE constraints at all*, and two of those
 * are load-bearing rather than performance nice-to-haves:
 *
 *   uq_idem   makes enqueueing a scan idempotent. Without it a double-submit or a retried
 *             finalize silently produces two scans of one transcript, which is two RA queue
 *             entries for one session.
 *   idx_due   is the pair the cron claim filters on, every run, on a table that only grows.
 *
 * So the migration is part of the contract, not tuning. It runs after buildSchema(), is guarded by
 * a `schema-version` system setting, and each ALTER is preceded by a SHOW INDEX check so a second
 * run is a no-op rather than a duplicate-key error.
 */
class EntitySchemaManager
{
    public const DEPENDENCY = 'redcap_entity';

    private EntityPlatformInterface $platform;

    public function __construct(EntityPlatformInterface $platform)
    {
        $this->platform = $platform;
    }

    /**
     * @throws EntitySchemaException when redcap_entity is absent or disabled
     */
    public function assertEntityModuleAvailable(): void
    {
        if ($this->platform->entityModuleVersion() === null) {
            throw new EntitySchemaException(
                'MICA requires the REDCap Entity module (' . self::DEPENDENCY . '), which is not '
                . 'enabled on this system. Enable it in the Control Center, then re-enable MICA. '
                . 'MICA stores its scan queue, scan runs, counselor-turn telemetry and audit trail '
                . 'in Entity tables and cannot run a session without them.'
            );
        }
    }

    /**
     * Read-only check that the schema is current. This - not ensureSchema() - is what a request
     * serving a participant calls.
     *
     * The distinction is not stylistic. ensureSchema() ends in setSystemSetting(), which routes
     * through ExternalModules::setProjectSetting() and hits a user-based permission check that is
     * waived only under CRON or for a user with design rights. A participant on a survey page has
     * neither, so a version bump whose first post-deploy request happened to be a participant's
     * would turn a migration into a mid-session exception. Migration is an administrative act:
     * it happens at module enable or from cron, and everything else asserts.
     *
     * @throws EntitySchemaException when the schema has not been built or is behind
     */
    public function assertSchemaCurrent(): void
    {
        $recorded = $this->platform->schemaVersion();

        if ($recorded === EntityTypes::SCHEMA_VERSION) {
            return;
        }

        throw new EntitySchemaException(sprintf(
            'MICA\'s entity schema is at %s but this code expects %s. An administrator needs to '
            . 'run the migration by disabling and re-enabling MICA in the Control Center (or '
            . 'waiting for the next cron run). Sessions are refused until then rather than writing '
            . 'into a schema this version does not understand.',
            $recorded ?? 'unbuilt',
            EntityTypes::SCHEMA_VERSION
        ));
    }

    /**
     * Build any missing tables and apply any missing indexes.
     *
     * No-op on the fast path: when the recorded schema version already matches
     * EntityTypes::SCHEMA_VERSION nothing is queried at all, which is what makes this safe to
     * call defensively from a cron entry point on every run.
     *
     * Call this from module enable or from cron only - see assertSchemaCurrent() for why a
     * participant request must not.
     *
     * Known limitation, stated rather than papered over: the version guard tracks *this module's
     * declarations*, not the database. An admin who drops a table by hand in the Entity DB Manager
     * leaves the version matching and the table gone, and the next write fails with a SQL error.
     * Recovery is to re-enable the module (or call ensureSchema(force: true)), which rebuilds.
     *
     * @throws EntitySchemaException if the dependency is missing, a table is still absent after
     *                               buildSchema(), or an index cannot be created
     */
    public function ensureSchema(bool $force = false): void
    {
        $this->assertEntityModuleAvailable();

        $recorded = $this->platform->schemaVersion();
        if (!$force && $recorded === EntityTypes::SCHEMA_VERSION) {
            return;
        }

        $this->platform->buildSchema();

        // buildSchema() returns void and swallows its own failures (it early-returns when the
        // module prefix is not enabled and returns false per-table on a bad property type,
        // discarding both). Verifying is the only way to know it worked.
        $missing = array_values(array_filter(
            EntityTypes::tables(),
            fn(string $table): bool => !$this->platform->tableExists($table)
        ));

        if ($missing !== []) {
            throw new EntitySchemaException(
                'The REDCap Entity framework did not create ' . implode(', ', $missing) . '. '
                . 'This usually means MICA is enabled for a project but not enabled system-wide - '
                . '\\REDCapEntity\\EntityDB::buildSchema() checks the system-level enabled version '
                . 'and does nothing without it. Check Control Center > External Modules, then '
                . 'rebuild from Control Center > Entity DB Manager.'
            );
        }

        $added = $this->applyIndexes();

        $this->platform->recordSchemaVersion(EntityTypes::SCHEMA_VERSION);
        $this->platform->log(sprintf(
            'Entity schema at version %s (was %s); %d table(s) verified, %d index(es) added: %s',
            EntityTypes::SCHEMA_VERSION,
            $recorded ?? 'unbuilt',
            count(EntityTypes::tables()),
            count($added),
            $added === [] ? 'none needed' : implode(', ', $added)
        ));
    }

    /**
     * @return string[] names of the indexes actually created by this call
     * @throws EntitySchemaException
     */
    private function applyIndexes(): array
    {
        $added = [];

        // One SHOW INDEX per table rather than per index: six indexes over four tables, and the
        // per-index answer is a substring of the same result.
        $existing = [];

        foreach (EntityTypes::indexes() as $name => $spec) {
            $table = $spec['table'];
            $existing[$table] ??= $this->platform->indexNames($table);

            if (in_array($name, $existing[$table], true)) {
                continue;
            }

            try {
                $this->platform->addIndex($table, $name, $spec['columns'], $spec['unique']);
            } catch (EntitySchemaException $e) {
                // A UNIQUE index is the one that can fail on existing data rather than on syntax,
                // and the reason matters enough to say out loud: duplicates already in the table
                // mean two scans exist for one transcript and a human has to decide which is real.
                $hint = $spec['unique']
                    ? ' A UNIQUE index fails when the column already holds duplicates, which here '
                    . 'would mean more than one scan job exists for the same finalized transcript. '
                    . 'Resolve the duplicates before retrying; do not drop the constraint.'
                    : '';

                throw new EntitySchemaException(
                    "Could not create index $name on $table (" . $spec['why'] . ').' . $hint
                    . ' Underlying error: ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            $existing[$table][] = $name;
            $added[] = "$table.$name";
        }

        return $added;
    }
}
