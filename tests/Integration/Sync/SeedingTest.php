<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Application\Service\Sync\SeedRunner;
use Semitexa\Orm\Application\Service\Sync\SmartUpsert;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Trait\Seedable;

/**
 * REGRESSION. `orm:seed` runs `defaults()` and `Seedable::create()` builds the
 * rows — and create() could not build a single valid model: it called the
 * constructor with no arguments, and a resource model is a final readonly
 * class whose constructor takes every column (ArgumentCountError). Nothing in
 * the workspace seeded through it, so nothing noticed (tk-parity-proof-gaps).
 *
 * The create() cases need no database. The end-to-end case runs the real
 * SeedRunner against MySQL and is skipped without one: the upsert is
 * `INSERT … ON DUPLICATE KEY UPDATE`, MySQL only.
 */
final class SeedingTest extends TestCase
{
    #[Test]
    public function create_builds_a_readonly_model_through_its_constructor(): void
    {
        $model = SeedingRoleModel::create(id: 'r-admin', name: 'Administrator', rank: 1);

        self::assertSame(['r-admin', 'Administrator', 1], [$model->id, $model->name, $model->rank]);
    }

    #[Test]
    public function create_names_a_column_the_model_does_not_have(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'title'");

        SeedingRoleModel::create(id: 'r-admin', name: 'Administrator', rank: 1, title: 'x');
    }

    #[Test]
    public function create_names_a_required_column_that_was_left_out(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'rank'");

        SeedingRoleModel::create(id: 'r-admin', name: 'Administrator');
    }

    #[Test]
    public function create_fills_the_constructor_then_the_writable_properties(): void
    {
        // review: create() went constructor-only and refused `name` here, a
        // shape that seeded before — optional constructor + a writable property.
        $model = SeedingMutableModel::create(id: 'x', name: 'y');

        self::assertSame(['x', 'y'], [$model->id, $model->name]);
    }

    #[Test]
    public function create_names_a_property_it_cannot_write(): void
    {
        foreach (['stamp' => 'readonly', 'secret' => 'private', 'counter' => 'static', 'title' => 'missing'] as $name => $why) {
            try {
                SeedingMutableModel::create(...['id' => 'x', $name => 'z']);
                self::fail("create() accepted the {$why} property '{$name}'");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("'{$name}'", $e->getMessage(), $why);
            }
        }
    }

    #[Test]
    public function seeding_a_batch_that_repeats_a_key_counts_one_insert(): void
    {
        [$orm, $db] = $this->ownTheRolesTable();
        try {
            // MySQL inserts the first row and updates it with the second
            // (rowCount 3): one row in the table, so one insert and one update.
            $stats = (new SmartUpsert($db))->upsert([
                new SeedingRoleModel(id: 'e', name: 'E', rank: 1),
                new SeedingRoleModel(id: 'e', name: 'E2', rank: 1),
            ]);

            self::assertSame(1, (int) $db->query('SELECT COUNT(*) AS n FROM seeding_test_roles')->fetchAll()[0]['n']);
            self::assertSame(['inserted' => 1, 'updated' => 1, 'unchanged' => 0], $stats);
        } finally {
            $db->execute('DROP TABLE IF EXISTS seeding_test_roles');
            $orm->shutdown();
        }
    }

    #[Test]
    public function seeding_inserts_the_defaults_once_and_updates_a_changed_row(): void
    {
        [$orm, $db] = $this->ownTheRolesTable();
        $adminName = SeedingDefaults::$adminName;
        try {
            $runner = new SeedRunner($db, new SeedingOneClassDiscovery());

            $first = $runner->run();
            $again = $runner->run();
            SeedingDefaults::$adminName = 'Admin';
            $changed = $runner->run();

            self::assertSame(2, $first['seeding_test_roles']['inserted'] ?? null);
            self::assertSame(0, $again['seeding_test_roles']['inserted'] ?? null, 'seeding twice must not duplicate');
            self::assertSame(2, (int) $db->query('SELECT COUNT(*) AS n FROM seeding_test_roles')->fetchAll()[0]['n']);
            self::assertSame(1, $changed['seeding_test_roles']['updated'] ?? null);
            self::assertSame('Admin', $db->query("SELECT name FROM seeding_test_roles WHERE id = 'r-admin'")->fetchAll()[0]['name']);
        } finally {
            SeedingDefaults::$adminName = $adminName;
            $db->execute('DROP TABLE IF EXISTS seeding_test_roles');
            $orm->shutdown();
        }
    }

    /**
     * Creates seeding_test_roles on MySQL, or skips: never a table this test
     * did not create.
     *
     * @return array{0: OrmManager, 1: DatabaseAdapterInterface}
     */
    private function ownTheRolesTable(): array
    {
        // verify:accept-test-change the two skips are the existing MySQL-unavailable and table-not-ours skips moved unchanged out of seeding_inserts_the_defaults_once_and_updates_a_changed_row so the duplicate-key test shares them
        try {
            $orm = new OrmManager();
            if ($orm->getDriver() !== 'mysql') {
                throw new \RuntimeException('the upsert is MySQL-only');
            }
            $db = $orm->getAdapter();
            $db->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('MySQL unavailable: ' . $e->getMessage());
        }

        // Never drop a table this test did not create: a database that already
        // has one by this name keeps it, and the test says why it did not run.
        // verify:accept-test-change review (orm#89): the old setup dropped any existing seeding_test_roles in the configured database; a table it does not own now skips instead
        // information_schema, not SHOW TABLES LIKE: there `_` is a wildcard.
        if ($db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seeding_test_roles'")->fetchAll() !== []) {
            $orm->shutdown();
            self::markTestSkipped('seeding_test_roles already exists in this database; it is not this test\'s to drop.');
        }
        $db->execute('CREATE TABLE seeding_test_roles (id VARCHAR(36) PRIMARY KEY, name VARCHAR(64) NOT NULL, `rank` INT NOT NULL)');

        return [$orm, $db];
    }
}

#[FromTable(name: 'seeding_test_roles')]
final readonly class SeedingRoleModel
{
    use Seedable;

    public function __construct(
        #[PrimaryKey(strategy: 'manual')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $name,
        #[Column(type: MySqlType::Int)]
        public int $rank,
    ) {}

    /** @return list<self> */
    public static function defaults(): array
    {
        return [
            self::create(id: 'r-admin', name: SeedingDefaults::$adminName, rank: 1),
            self::create(id: 'r-editor', name: 'Editor', rank: 2),
        ];
    }
}

/** Not readonly: an optional constructor plus properties set after it. */
final class SeedingMutableModel
{
    use Seedable;

    public string $name = '';
    public readonly string $stamp;
    private string $secret = '';
    public static string $counter = '';

    public function __construct(public ?string $id = null)
    {
        $this->stamp = 's';
    }

    public function secret(): string
    {
        return $this->secret;
    }
}

/** What the second seeding run changes: a readonly model class cannot hold it. */
final class SeedingDefaults
{
    public static string $adminName = 'Administrator';
}

final class SeedingOneClassDiscovery extends ClassDiscovery
{
    public function __construct()
    {
    }

    public function findClassesWithAttribute(string $attributeClass): array
    {
        return $attributeClass === FromTable::class ? [SeedingRoleModel::class] : [];
    }
}
