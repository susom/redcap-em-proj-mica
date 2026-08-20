<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\EntityTypes;

/**
 * The entity declarations are data, and this is the only thing that checks them against the rules
 * the vendored redcap_entity framework actually enforces.
 *
 * That matters more than a declaration test usually would, because redcap_entity fails *quietly*.
 * An invalid property type marks the whole type INVALID and it simply never gets a table; a missing
 * `required` key raises a PHP warning from inside CREATE TABLE; a `choices` list instead of a map
 * rejects every value at write time. None of those surface as an error at the point of the mistake.
 *
 * The constants below are transcribed from ../redcap_entity_v9.9.9 rather than imported: the module
 * is a runtime dependency of MICA, not of the test suite, and the suite must run with nothing but
 * PHP. If redcap_entity changes them, these tests are what should fail.
 */
#[CoversClass(EntityTypes::class)]
final class EntityTypesTest extends TestCase
{
    /** EntityFactory::getValidPropertyTypes() */
    private const VALID_TYPES = [
        'user', 'email', 'text', 'record', 'entity_reference', 'price',
        'project', 'date', 'integer', 'boolean', 'json', 'long_text', 'data',
    ];

    /** The only keys EntityFactory documents and reads (README-developer.md, Entity::save()). */
    private const VALID_SPECIAL_KEYS = ['label', 'project', 'author'];

    public static function everyType(): array
    {
        return array_map(static fn(string $name): array => [$name], array_keys(EntityTypes::all()));
    }

    public static function everyProperty(): array
    {
        $cases = [];
        foreach (EntityTypes::all() as $type => $info) {
            foreach ($info['properties'] as $property => $spec) {
                $cases["$type.$property"] = [$type, $property, $spec];
            }
        }

        return $cases;
    }

    public function testDeclaresTheTypesTheDataModelNamesPlusTheNotificationTrail(): void
    {
        $this->assertSame(
            ['mica_scan_job', 'mica_scan_run', 'mica_turn', 'mica_audit_event', 'mica_notification'],
            array_keys(EntityTypes::all()),
            '02-data-model.md §1.1 names the first four; mica_notification is stage-6\'s trail.'
        );
    }

    #[DataProvider('everyType')]
    public function testTypeIdentifierIsAcceptable(string $type): void
    {
        // EntityFactory rejects anything else, and silently: the type is marked INVALID.
        $this->assertSame(0, preg_match('/[^a-zA-Z0-9_]+/', $type), 'alphanumeric and underscore only');
        $this->assertLessThanOrEqual(50, strlen($type), 'EntityFactory caps the identifier at 50');
    }

    #[DataProvider('everyType')]
    public function testTypeHasTheKeysTheFrameworkRequires(string $type): void
    {
        $info = EntityTypes::all()[$type];

        // EntityFactory marks a type INVALID when either is empty.
        $this->assertNotEmpty($info['label'] ?? null, 'a missing label makes the type INVALID');
        $this->assertNotEmpty($info['properties'] ?? null, 'missing properties makes the type INVALID');
        $this->assertIsArray($info['properties']);

        foreach (array_keys($info['special_keys'] ?? []) as $key) {
            $this->assertContains(
                $key,
                self::VALID_SPECIAL_KEYS,
                "special_keys.$key is not one the framework reads, so it would do nothing"
            );
        }

        // A special key must name a property that exists, or getLabel()/save() read a missing index.
        foreach ($info['special_keys'] ?? [] as $key => $property) {
            $this->assertArrayHasKey(
                $property,
                $info['properties'],
                "special_keys.$key points at '$property', which $type does not declare"
            );
        }
    }

    #[DataProvider('everyProperty')]
    public function testPropertyDeclaresRequired(string $type, string $property, array $spec): void
    {
        // EntityDB::buildEntityDBTable() reads $info['required'] with no isset guard, and
        // Entity::validateProperty() reads it again on every write. Both would warn.
        $this->assertArrayHasKey(
            'required',
            $spec,
            "$type.$property omits 'required'; the framework reads that key unguarded"
        );
        $this->assertIsBool($spec['required']);
    }

    #[DataProvider('everyProperty')]
    public function testPropertyTypeIsOneTheFrameworkSupports(string $type, string $property, array $spec): void
    {
        $this->assertArrayHasKey('type', $spec);
        $this->assertContains(
            strtolower($spec['type']),
            self::VALID_TYPES,
            "$type.$property uses '{$spec['type']}', which would mark $type INVALID - no table"
        );
    }

    #[DataProvider('everyProperty')]
    public function testChoicesAreAValueToLabelMap(string $type, string $property, array $spec): void
    {
        if (!isset($spec['choices'])) {
            $this->assertTrue(true);
            return;
        }

        // Entity::validateProperty() ends with `isset($info['choices'][$value])`, so a plain list
        // would be indexed 0..n and reject every real value.
        $this->assertNotSame(
            array_keys($spec['choices']),
            range(0, count($spec['choices']) - 1),
            "$type.$property declares choices as a list; the framework needs value => label"
        );

        foreach ($spec['choices'] as $value => $label) {
            $this->assertIsString($value, "$type.$property choice keys are the stored values");
            $this->assertNotSame('', $label, "$type.$property choice '$value' has no label");
        }
    }

    #[DataProvider('everyProperty')]
    public function testEntityReferenceNamesItsTargetType(string $type, string $property, array $spec): void
    {
        if ($spec['type'] !== 'entity_reference') {
            $this->assertTrue(true);
            return;
        }

        // Without entity_type, validateProperty()'s `!empty($info['entity_type']) && ...` is false,
        // so it errors on every write - the reference is unusable rather than unvalidated.
        $this->assertArrayHasKey('entity_type', $spec, "$type.$property must name its target type");
        $this->assertArrayHasKey(
            $spec['entity_type'],
            EntityTypes::all(),
            "$type.$property references '{$spec['entity_type']}', which this module does not declare"
        );
    }

    public function testTheAuditActorIsNotTheUserType(): void
    {
        // Deliberate deviation from 02-data-model.md §1.1, and the reason is load-bearing: the
        // `user` type validates through RedCapDB::usernameExists(), and the scan worker writes
        // audit events from cron with no logged-in user. Declaring it `user` would make exactly
        // the events that most need recording impossible to write.
        $actor = EntityTypes::all()['mica_audit_event']['properties']['actor'];

        $this->assertSame('text', $actor['type']);
        $this->assertTrue($actor['required'], 'an audit row with no actor is not an audit row');
    }

    public function testTheVerbatimModelOutputIsDataNotJson(): void
    {
        // `json` round-trips through json_decode/json_encode in Entity::setData()/getData(), which
        // reformats it. This column is the authoritative immutable findings record (§1) and the
        // thing finding instances are later checked against, so it has to survive byte-for-byte.
        $this->assertSame(
            'data',
            EntityTypes::all()['mica_scan_run']['properties']['model_output_json']['type']
        );
    }

    public function testNoSupportedConcernIsNotARunStatus(): void
    {
        // A clean scan is `ok` with zero findings. Making "found nothing" its own run status is how
        // a scanner failure becomes indistinguishable from a negative screen, which is the exact
        // thing the handoff forbids ("never treated as a negative screen").
        $this->assertArrayNotHasKey('no_supported_concern', EntityTypes::runStatusChoices());
        $this->assertArrayHasKey('ok', EntityTypes::runStatusChoices());
    }

    public function testTheFailureTaxonomyMatchesTheStagePlan(): void
    {
        $expected = [
            'timeout', 'refusal', 'invalid_json', 'schema_invalid',
            'citation_mismatch', 'content_filter', 'service_error',
        ];

        foreach ($expected as $status) {
            $this->assertArrayHasKey(
                $status,
                EntityTypes::runStatusChoices(),
                "stage-4-safetyscan-runner.md §4.2.5 names '$status'"
            );
        }
    }

    public function testScanJobCarriesTheRepeatInstance(): void
    {
        // 02-data-model.md §3, note of 2026-08-17: the session instruments are repeating, so the
        // idempotency key must include the instance or two sessions in one window collide and one
        // is dropped as a duplicate.
        $this->assertArrayHasKey('instance', EntityTypes::all()['mica_scan_job']['properties']);
        $this->assertArrayHasKey('instance', EntityTypes::all()['mica_turn']['properties']);
    }

    public function testIndexesTargetDeclaredTablesAndColumns(): void
    {
        $types = EntityTypes::all();

        foreach (EntityTypes::indexes() as $name => $spec) {
            $type = preg_replace('/^redcap_entity_/', '', $spec['table']);

            $this->assertArrayHasKey($type, $types, "index $name targets undeclared table {$spec['table']}");
            $this->assertNotEmpty($spec['columns'], "index $name declares no columns");
            $this->assertNotEmpty($spec['why'], "index $name has no stated reason to exist");

            foreach ($spec['columns'] as $column) {
                $this->assertArrayHasKey(
                    $column,
                    $types[$type]['properties'],
                    "index $name is on $type.$column, which is not a declared property"
                );
            }
        }
    }

    public function testTheIdempotencyIndexIsUnique(): void
    {
        // The whole point. Without UNIQUE, a double-submit or retried finalize produces two scans
        // of one transcript, which is two RA queue entries for one session.
        $indexes = EntityTypes::indexes();

        $this->assertTrue($indexes['uq_idem']['unique']);
        $this->assertSame(['idempotency_key'], $indexes['uq_idem']['columns']);
        $this->assertFalse($indexes['idx_due']['unique'], 'many jobs share a status');
    }

    public function testTablesAreDerivedFromTheDeclaredTypes(): void
    {
        $this->assertSame(
            [
                'redcap_entity_mica_scan_job',
                'redcap_entity_mica_scan_run',
                'redcap_entity_mica_turn',
                'redcap_entity_mica_audit_event',
                'redcap_entity_mica_notification',
            ],
            EntityTypes::tables()
        );
    }

    /**
     * The send-once column must be optional, or a failed attempt could not leave it blank.
     *
     * The whole mechanism rests on this: `dedupe_key` carries the key on a sent row and NULL on every
     * other, so a UNIQUE index enforces send-once without a failure blocking its own retry.
     * `required => true` would break it by forcing every row to claim the key, and the symptom would
     * be a retry that silently cannot be written.
     */
    public function testTheNotificationDedupeKeyIsOptionalSoFailedAttemptsCanRepeat(): void
    {
        $properties = EntityTypes::all()['mica_notification']['properties'];

        $this->assertFalse($properties['dedupe_key']['required']);
        $this->assertTrue($properties['idempotency_key']['required'], 'Every row is correlatable.');

        $unique = EntityTypes::indexes()['uq_notif_dedupe'];
        $this->assertTrue($unique['unique']);
        $this->assertSame(['dedupe_key'], $unique['columns']);
    }
}
