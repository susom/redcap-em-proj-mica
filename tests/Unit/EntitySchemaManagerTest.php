<?php

namespace Stanford\MICA\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stanford\MICA\EntitySchemaException;
use Stanford\MICA\EntitySchemaManager;
use Stanford\MICA\EntityTypes;
use Stanford\MICA\Tests\Support\FakeEntityPlatform;

/**
 * The migration is one of the few places in this module where getting it *almost* right is worse
 * than not doing it: a schema that builds tables but skips uq_idem passes every other test in the
 * suite and then lets one transcript be scanned twice in production.
 *
 * So the fake platform records calls rather than just answering them, and the assertions are about
 * ordering and idempotency, not only outcomes.
 */
#[CoversClass(EntitySchemaManager::class)]
final class EntitySchemaManagerTest extends TestCase
{
    public function testMissingDependencyFailsClosedWithAnActionableMessage(): void
    {
        $platform = new FakeEntityPlatform(entityModuleVersion: null);
        $manager = new EntitySchemaManager($platform);

        try {
            $manager->ensureSchema();
            $this->fail('a missing redcap_entity must not be survivable');
        } catch (EntitySchemaException $e) {
            $this->assertStringContainsString('redcap_entity', $e->getMessage());
            $this->assertStringContainsString('Control Center', $e->getMessage(), 'tell them where to go');
        }

        $this->assertSame([], $platform->calls, 'nothing may be built before the dependency check');
    }

    public function testAssertAvailableIsSeparatelyCallable(): void
    {
        $this->expectException(EntitySchemaException::class);
        (new EntitySchemaManager(new FakeEntityPlatform(entityModuleVersion: null)))
            ->assertEntityModuleAvailable();
    }

    public function testAssertSchemaCurrentWritesNothing(): void
    {
        // This is the whole reason the method exists. ensureSchema() ends in setSystemSetting(),
        // whose user-based permission check is waived only under CRON or for a user with design
        // rights - so a participant request that tried to migrate would throw mid-session. The
        // participant path asserts instead, and must not touch a setting to do it.
        $platform = new FakeEntityPlatform();
        $platform->version = EntityTypes::SCHEMA_VERSION;

        (new EntitySchemaManager($platform))->assertSchemaCurrent();

        $this->assertSame([], $platform->calls, 'the assertion may not build, query or write');
    }

    public function testAssertSchemaCurrentRefusesAnUnbuiltSchema(): void
    {
        try {
            (new EntitySchemaManager(new FakeEntityPlatform()))->assertSchemaCurrent();
            $this->fail('an unbuilt schema must not be treated as usable');
        } catch (EntitySchemaException $e) {
            $this->assertStringContainsString('unbuilt', $e->getMessage());
            $this->assertStringContainsString('re-enabling MICA', $e->getMessage(), 'say how to fix it');
        }
    }

    public function testAssertSchemaCurrentRefusesAStaleSchema(): void
    {
        $platform = new FakeEntityPlatform();
        $platform->version = '0';

        $this->expectException(EntitySchemaException::class);
        $this->expectExceptionMessageMatches('/at 0 but this code expects/');

        (new EntitySchemaManager($platform))->assertSchemaCurrent();
    }

    public function testFirstRunBuildsTablesThenAddsEveryIndex(): void
    {
        $platform = new FakeEntityPlatform();
        (new EntitySchemaManager($platform))->ensureSchema();

        $this->assertSame('buildSchema', $platform->calls[0], 'tables before indexes, always');

        foreach (EntityTypes::indexes() as $name => $spec) {
            $this->assertContains(
                $spec['table'] . '.' . $name,
                $platform->added,
                "$name was never created; redcap_entity does not create it for us"
            );
        }

        $this->assertSame(EntityTypes::SCHEMA_VERSION, $platform->version);
    }

    public function testTheUniqueIdempotencyIndexIsActuallyCreatedAsUnique(): void
    {
        $platform = new FakeEntityPlatform();
        (new EntitySchemaManager($platform))->ensureSchema();

        $this->assertTrue(
            $platform->uniqueFlag['redcap_entity_mica_scan_job.uq_idem'],
            'a non-unique uq_idem is a silently broken idempotency guarantee'
        );
        $this->assertFalse($platform->uniqueFlag['redcap_entity_mica_scan_job.idx_due']);
    }

    public function testSecondRunIsAFullNoOp(): void
    {
        $platform = new FakeEntityPlatform();
        $manager = new EntitySchemaManager($platform);

        $manager->ensureSchema();
        $platform->calls = [];
        $platform->added = [];

        $manager->ensureSchema();

        // Not merely "no error": the point of the version guard is that a cron entry point can
        // call this every run without touching the database at all.
        $this->assertSame([], $platform->calls, 'the version guard must short-circuit before any query');
    }

    public function testForceRebuildsWithoutDuplicatingIndexes(): void
    {
        $platform = new FakeEntityPlatform();
        $manager = new EntitySchemaManager($platform);

        $manager->ensureSchema();
        $platform->added = [];

        $manager->ensureSchema(true);

        $this->assertContains('buildSchema', $platform->calls, 'force must reach the framework');
        $this->assertSame([], $platform->added, 'SHOW INDEX first, so a re-run adds nothing');
    }

    public function testAVersionBumpReRunsTheMigration(): void
    {
        $platform = new FakeEntityPlatform();
        $platform->version = '0';   // an older build

        (new EntitySchemaManager($platform))->ensureSchema();

        $this->assertContains('buildSchema', $platform->calls);
        $this->assertSame(EntityTypes::SCHEMA_VERSION, $platform->version);
    }

    public function testAMissingTableAfterBuildFailsClosed(): void
    {
        // buildSchema() returns void and discards its own failures - it early-returns when the
        // module is not enabled system-wide, which is the single most likely deployment mistake
        // here (MICA enabled per-project only). Verifying afterwards is the only way to catch it.
        $platform = new FakeEntityPlatform();
        $platform->buildCreatesTables = false;

        try {
            (new EntitySchemaManager($platform))->ensureSchema();
            $this->fail('a schema that did not build must not be recorded as built');
        } catch (EntitySchemaException $e) {
            $this->assertStringContainsString('redcap_entity_mica_scan_job', $e->getMessage());
            $this->assertStringContainsString('enabled system-wide', $e->getMessage());
        }

        $this->assertNull($platform->version, 'the version must not advance past a failed build');
        $this->assertSame([], $platform->added, 'no index may be attempted on a missing table');
    }

    public function testAFailedUniqueIndexExplainsDuplicateRows(): void
    {
        $platform = new FakeEntityPlatform();
        $platform->failIndex = 'uq_idem';

        try {
            (new EntitySchemaManager($platform))->ensureSchema();
            $this->fail('an unenforceable idempotency key must not be silently skipped');
        } catch (EntitySchemaException $e) {
            $this->assertStringContainsString('uq_idem', $e->getMessage());
            $this->assertStringContainsString('duplicates', $e->getMessage());
            $this->assertStringContainsString('do not drop the constraint', $e->getMessage());
            $this->assertStringContainsString('Duplicate entry', $e->getMessage(), 'keep the cause');
        }

        $this->assertNull($platform->version, 'a partial migration is not a migration');
    }

    public function testAFailedNonUniqueIndexDoesNotClaimDuplicates(): void
    {
        $platform = new FakeEntityPlatform();
        $platform->failIndex = 'idx_due';

        try {
            (new EntitySchemaManager($platform))->ensureSchema();
            $this->fail('expected the migration to fail');
        } catch (EntitySchemaException $e) {
            $this->assertStringContainsString('idx_due', $e->getMessage());
            $this->assertStringNotContainsString(
                'duplicates',
                $e->getMessage(),
                'a non-unique index cannot fail on duplicate rows; do not send staff chasing that'
            );
        }
    }

    public function testOnlyMissingIndexesAreAdded(): void
    {
        $platform = new FakeEntityPlatform();
        $platform->preExisting = ['redcap_entity_mica_scan_job' => ['uq_idem', 'PRIMARY']];

        (new EntitySchemaManager($platform))->ensureSchema();

        $this->assertNotContains('redcap_entity_mica_scan_job.uq_idem', $platform->added);
        $this->assertContains('redcap_entity_mica_scan_job.idx_due', $platform->added);
    }

    public function testOneShowIndexPerTableNotPerIndex(): void
    {
        // Six indexes over four tables. Reading the same result twice is not wrong, just wasteful
        // on a path that may run defensively before every entity write.
        $platform = new FakeEntityPlatform();
        (new EntitySchemaManager($platform))->ensureSchema();

        $lookups = array_count_values($platform->indexLookups);
        foreach ($lookups as $table => $count) {
            $this->assertSame(1, $count, "indexNames() was called $count times for $table");
        }
    }
}
