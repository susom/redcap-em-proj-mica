<?php

namespace Stanford\MICA\Tests\Support;

use Stanford\MICA\EntityPlatformInterface;
use Stanford\MICA\EntitySchemaException;
use Stanford\MICA\EntityTypes;

/**
 * Records what it was asked to do. Deliberately not a mock: the assertions here are about call
 * order and repeat-call behaviour, which reads better against a small recorder than against
 * expectation chains.
 *
 * It is a *stateful* fake on purpose. A recorder that logs writes without applying them cannot
 * model a re-run, and the first version of this file got that wrong: addIndex() appended to
 * $added while indexNames() kept returning the original $preExisting, so a forced rebuild looked
 * like it duplicated every index when the real platform - which reads information_schema - would
 * have reported them and skipped. The failure was in the fake, not the manager. So writes here
 * land where the corresponding read will see them, and $added stays a separate call log.
 */
final class FakeEntityPlatform implements EntityPlatformInterface
{
    /** @var string[] */
    public array $calls = [];
    /** @var string[] "table.index" for each index actually created */
    public array $added = [];
    /** @var array<string,bool> */
    public array $uniqueFlag = [];
    /** @var string[] */
    public array $indexLookups = [];
    /** @var string[] */
    public array $logs = [];
    /** @var array<string,string[]> indexes present before the migration runs */
    public array $preExisting = [];
    /** @var array<string,string[]> columns present before the migration runs */
    public array $columns = [];
    /** @var array<string,string> "table.column" => definition, for each column created */
    public array $addedColumns = [];
    public ?string $failColumn = null;

    public bool $buildCreatesTables = true;
    public ?string $failIndex = null;
    public ?string $version = null;

    /** @var array<string,bool> */
    private array $tables = [];

    public function __construct(private ?string $entityModuleVersion = 'v9.9.9')
    {
    }

    public function entityModuleVersion(): ?string
    {
        return $this->entityModuleVersion;
    }

    public function buildSchema(): void
    {
        $this->calls[] = 'buildSchema';

        if (!$this->buildCreatesTables) {
            return;
        }

        foreach (EntityTypes::all() as $type => $info) {
            $table = 'redcap_entity_' . $type;
            $this->tables[$table] = true;
            // CREATE TABLE includes every declared column, which is why an ALTER is only needed for
            // a property added to a table that ALREADY existed.
            $this->columns[$table] ??= array_merge(
                ['id', 'created', 'updated'],
                array_keys($info['properties'])
            );
        }
    }

    public function tableExists(string $table): bool
    {
        return $this->tables[$table] ?? false;
    }

    public function columnNames(string $table): array
    {
        return $this->columns[$table] ?? [];
    }

    public function addColumn(string $table, string $column, string $definition): void
    {
        $this->calls[] = "addColumn:$table.$column";

        if ($column === $this->failColumn) {
            throw new EntitySchemaException("Unknown column type for $column");
        }

        $this->addedColumns["$table.$column"] = $definition;
        // Applied, so the next columnNames() sees it - same reasoning as addIndex().
        $this->columns[$table][] = $column;
    }

    public function indexNames(string $table): array
    {
        $this->calls[] = "indexNames:$table";
        $this->indexLookups[] = $table;

        return $this->preExisting[$table] ?? [];
    }

    public function addIndex(string $table, string $name, array $columns, bool $unique): void
    {
        $this->calls[] = "addIndex:$table.$name";

        if ($name === $this->failIndex) {
            // The message MySQL actually gives, so the "keep the cause" assertion is meaningful.
            throw new EntitySchemaException(
                $unique ? "Duplicate entry 'abc' for key '$name'" : "Something failed on $name"
            );
        }

        $this->added[] = "$table.$name";
        $this->uniqueFlag["$table.$name"] = $unique;

        // Apply the write, so the next indexNames() reports it - see the class comment.
        $this->preExisting[$table][] = $name;
    }

    public function schemaVersion(): ?string
    {
        return $this->version;
    }

    public function recordSchemaVersion(string $version): void
    {
        $this->calls[] = "recordSchemaVersion:$version";
        $this->version = $version;
    }

    public function log(string $message): void
    {
        $this->logs[] = $message;
    }
}
