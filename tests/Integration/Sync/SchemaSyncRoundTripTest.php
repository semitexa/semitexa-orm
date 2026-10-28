<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Sync;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Application\Service\Schema\SchemaCollector;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

#[FromTable(name: 'sync_rt_orders')]
#[Index(columns: ['customer_email', 'placed_at'], name: 'idx_sync_rt_orders_customer')]
#[Index(columns: ['reference'], unique: true, name: 'uniq_sync_rt_orders_reference')]
final readonly class SyncRoundTripOrderV1
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,
        #[Column(name: 'reference', type: MySqlType::Varchar, length: 32)]
        public string $reference,
        #[Column(name: 'customer_email', type: MySqlType::Varchar, length: 255)]
        public string $customerEmail,
        #[Column(name: 'total', type: MySqlType::Decimal, precision: 12, scale: 2)]
        public string $total,
        #[Column(name: 'paid', type: MySqlType::Boolean, default: false)]
        public bool $paid,
        #[Column(name: 'items', type: MySqlType::Json)]
        public string $items,
        #[Column(name: 'placed_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $placedAt,
        #[Column(name: 'note', type: MySqlType::Text, nullable: true)]
        public ?string $note = null,
        #[Column(name: 'quantity', type: MySqlType::Int, default: 1)]
        public int $quantity = 1,
    ) {}
}

/** The same table one release later: a column and an index more. */
#[FromTable(name: 'sync_rt_orders')]
#[Index(columns: ['customer_email', 'placed_at'], name: 'idx_sync_rt_orders_customer')]
#[Index(columns: ['reference'], unique: true, name: 'uniq_sync_rt_orders_reference')]
#[Index(columns: ['status'], name: 'idx_sync_rt_orders_status')]
final readonly class SyncRoundTripOrderV2
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Binary, length: 16)]
        public string $id,
        #[Column(name: 'reference', type: MySqlType::Varchar, length: 32)]
        public string $reference,
        #[Column(name: 'customer_email', type: MySqlType::Varchar, length: 255)]
        public string $customerEmail,
        #[Column(name: 'total', type: MySqlType::Decimal, precision: 12, scale: 2)]
        public string $total,
        #[Column(name: 'paid', type: MySqlType::Boolean, default: false)]
        public bool $paid,
        #[Column(name: 'items', type: MySqlType::Json)]
        public string $items,
        #[Column(name: 'placed_at', type: MySqlType::Datetime)]
        public \DateTimeImmutable $placedAt,
        #[Column(name: 'status', type: MySqlType::Varchar, length: 16, default: 'new')]
        public string $status = 'new',
        #[Column(name: 'note', type: MySqlType::Text, nullable: true)]
        public ?string $note = null,
        #[Column(name: 'quantity', type: MySqlType::Int, default: 1)]
        public int $quantity = 1,
    ) {}
}

/**
 * Migrations end to end (parity matrix: "no model → apply → empty diff test"):
 * a model is collected, compared with the live database, planned and applied —
 * and compared again, which must find nothing. Then the model grows a column
 * and an index, and the same must hold. A diff that is never empty would
 * re-run DDL on every deploy; one that misses a change would never apply it.
 */
final class SchemaSyncRoundTripTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function applying_the_plan_leaves_nothing_to_apply_and_a_change_is_applied_once(string $driver): void
    {
        $orm = $this->orm($driver);
        try {
            self::assertFalse($this->isEmptyForMine($this->diff($orm, $driver, SyncRoundTripOrderV1::class)), 'a new table is a change');
            $this->apply($orm, $driver, SyncRoundTripOrderV1::class);
            self::assertSame([], $this->describe($this->diff($orm, $driver, SyncRoundTripOrderV1::class)), 'after applying, nothing is left to apply');

            $grown = $this->diff($orm, $driver, SyncRoundTripOrderV2::class);
            self::assertFalse($this->isEmptyForMine($grown), 'a new column and index are a change');
            $this->apply($orm, $driver, SyncRoundTripOrderV2::class);
            self::assertSame([], $this->describe($this->diff($orm, $driver, SyncRoundTripOrderV2::class)), 'after the second apply, nothing is left either');
        } finally {
            $orm->getAdapter()->execute('DROP TABLE IF EXISTS sync_rt_orders');
            $orm->shutdown();
        }
    }

    private function orm(string $driver): OrmManager
    {
        if ($driver === 'sqlite') {
            return new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        }
        try {
            $orm = new OrmManager();
            $why = $orm->getDriver() !== 'mysql' ? 'not MySQL'
                : ($orm->getAdapter()->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sync_rt_orders'")->fetchAll() !== []
                    ? 'sync_rt_orders already exists here; it is not this test\'s to drop' : null);
        } catch (\Throwable $e) {
            $why = 'MySQL unavailable: ' . $e->getMessage();
        }
        if ($why !== null) {
            if (isset($orm)) {
                $orm->shutdown();
            }
            self::markTestSkipped($why);
        }

        return $orm;
    }

    /** @param class-string $model */
    private function diff(OrmManager $orm, string $driver, string $model): \Semitexa\Orm\Domain\Model\SchemaDiff
    {
        return $orm->getSchemaComparator()->compare($this->collector($driver, $model)->collect());
    }

    /** @param class-string $model */
    private function apply(OrmManager $orm, string $driver, string $model): void
    {
        $engine = $orm->getSyncEngine();
        $engine->execute($engine->buildPlan($this->diff($orm, $driver, $model)), allowDestructive: false);
    }

    /** @param class-string $model */
    private function collector(string $driver, string $model): SchemaCollector
    {
        $discovery = $this->createStub(ClassDiscovery::class);
        $discovery->method('findClassesWithAttribute')->willReturn([$model]);

        return new SchemaCollector($discovery, $driver);
    }

    /**
     * What the diff would still do to this test's table, readable when it fails.
     * Other tables are left out: the collector sees only this model, so on a
     * shared test database every other table reads as one to drop.
     *
     * @return list<string>
     */
    private function describe(\Semitexa\Orm\Domain\Model\SchemaDiff $diff): array
    {
        $mine = 'sync_rt_orders';
        $out = [];
        foreach ($diff->getCreateTables() as $table) {
            if ($table->name === $mine) {
                $out[] = 'create table';
            }
        }
        foreach ($diff->getAddColumns()[$mine] ?? [] as $column) {
            $out[] = 'add column ' . $column->name;
        }
        foreach ($diff->getAlterColumns()[$mine] ?? [] as $alter) {
            $out[] = 'alter column ' . $alter['column']->name . ': ' . json_encode($alter['changes']);
        }
        foreach ($diff->getDropColumns()[$mine] ?? [] as $drop) {
            $out[] = 'drop column ' . $drop['name'];
        }
        foreach ($diff->getAddIndexes()[$mine] ?? [] as $name => $index) {
            $out[] = 'add index ' . $name;
        }
        foreach ($diff->getDropIndexes()[$mine] ?? [] as $name) {
            $out[] = 'drop index ' . (is_string($name) ? $name : json_encode($name));
        }

        return $out;
    }

    /** Only this test's table: other tables of a shared database are not this test's business. */
    private function isEmptyForMine(\Semitexa\Orm\Domain\Model\SchemaDiff $diff): bool
    {
        return $this->describe($diff) === [];
    }
}
