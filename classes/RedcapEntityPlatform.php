<?php

namespace Stanford\MICA;

require_once __DIR__ . "/EntityPlatformInterface.php";
require_once __DIR__ . "/EntitySchemaException.php";
require_once __DIR__ . "/RedcapEntityLoader.php";

/**
 * The real EntityPlatformInterface: REDCap and redcap_entity, with no decisions of its own.
 *
 * Every method here either answers a question or executes one statement. All the branching lives in
 * EntitySchemaManager, where the suite can reach it.
 */
class RedcapEntityPlatform implements EntityPlatformInterface
{
    /**
     * MySQL identifiers cannot be bound as parameters, so the two statements that name a table or
     * index have to interpolate. Every such name in this module comes from EntityTypes - module
     * source, not user input - but the check is here anyway: it is one regex that turns "trust the
     * caller" into "prove it", and it makes this file's security review a five-second read.
     */
    private const IDENTIFIER = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    private const SCHEMA_VERSION_SETTING = 'schema-version';

    private MICA $module;

    public function __construct(MICA $module)
    {
        $this->module = $module;
    }

    public function entityModuleVersion(): ?string
    {
        $version = \ExternalModules\ExternalModules::getEnabledVersion(EntitySchemaManager::DEPENDENCY);

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function buildSchema(): void
    {
        RedcapEntityLoader::ensureLoaded();

        \REDCapEntity\EntityDB::buildSchema($this->module->PREFIX);
    }

    public function tableExists(string $table): bool
    {
        $result = $this->module->query(
            'SELECT 1 FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [$table]
        );

        return (bool) $result->fetch_assoc();
    }

    /**
     * The `AS idx` alias is load-bearing, not tidiness.
     *
     * MySQL returns information_schema column names UPPERCASED regardless of how the query spells
     * them, so `$row['index_name']` is undefined and `(string) null` is `''`. This method therefore
     * reported a list of empty strings for a fully indexed table, applyIndexes() concluded that
     * every index was missing, and the ALTER died on "Duplicate key name" - which would have made
     * every re-enable of the module after the first one fail. Found by running the migration twice
     * against a live database; no unit test could have caught it, because the fake answers in PHP.
     */
    public function indexNames(string $table): array
    {
        $result = $this->module->query(
            'SELECT DISTINCT index_name AS idx FROM information_schema.STATISTICS '
            . 'WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );

        $names = [];
        while ($row = $result->fetch_assoc()) {
            $names[] = (string) $row['idx'];
        }

        return $names;
    }

    public function addIndex(string $table, string $name, array $columns, bool $unique): void
    {
        $this->assertIdentifier($table, 'table');
        $this->assertIdentifier($name, 'index');

        if ($columns === []) {
            throw new EntitySchemaException("Index $name on $table declares no columns.");
        }

        $quoted = [];
        foreach ($columns as $column) {
            $this->assertIdentifier((string) $column, 'column');
            $quoted[] = '`' . $column . '`';
        }

        $sql = sprintf(
            'ALTER TABLE `%s` ADD %sINDEX `%s` (%s)',
            $table,
            $unique ? 'UNIQUE ' : '',
            $name,
            implode(', ', $quoted)
        );

        try {
            $this->module->query($sql, []);
        } catch (\Throwable $e) {
            // The framework's query() throws on a failed statement. Rethrown as our own type so
            // EntitySchemaManager can add the duplicate-rows explanation without catching
            // \Throwable and swallowing unrelated faults.
            throw new EntitySchemaException($e->getMessage(), 0, $e);
        }
    }

    public function schemaVersion(): ?string
    {
        $version = $this->module->getSystemSetting(self::SCHEMA_VERSION_SETTING);

        return is_scalar($version) && (string) $version !== '' ? (string) $version : null;
    }

    public function recordSchemaVersion(string $version): void
    {
        $this->module->setSystemSetting(self::SCHEMA_VERSION_SETTING, $version);
    }

    public function log(string $message): void
    {
        $this->module->emDebug($message);
    }

    private function assertIdentifier(string $value, string $kind): void
    {
        if (preg_match(self::IDENTIFIER, $value) !== 1) {
            throw new EntitySchemaException("Refusing to build SQL with an unsafe $kind name: $value");
        }
    }
}
