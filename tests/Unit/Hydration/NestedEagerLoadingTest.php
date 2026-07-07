<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Domain\Model\RelationState;
use Semitexa\Orm\Metadata\RelationRef;
use Semitexa\Orm\OrmManager;

/**
 * Nested (dot-path) eager loading: `['items.product']` loads `items` on the
 * order roots, then batch-loads `product` on the LOADED items — one IN(...)
 * query per relation per level, never one per row. Exercised on real SQLite.
 */
final class NestedEagerLoadingTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $adapter = $this->orm->getAdapter();
        $adapter->execute('CREATE TABLE nel_orders (id TEXT PRIMARY KEY, label TEXT)');
        $adapter->execute('CREATE TABLE nel_items (id TEXT PRIMARY KEY, orderId TEXT, productId TEXT)');
        $adapter->execute('CREATE TABLE nel_products (id TEXT PRIMARY KEY, title TEXT)');

        $adapter->execute("INSERT INTO nel_orders VALUES ('o1', 'first'), ('o2', 'second')");
        $adapter->execute("INSERT INTO nel_items VALUES ('i1', 'o1', 'p1'), ('i2', 'o1', 'p2'), ('i3', 'o2', 'p1')");
        $adapter->execute("INSERT INTO nel_products VALUES ('p1', 'Widget'), ('p2', 'Gadget')");
    }

    #[Test]
    public function dot_path_loads_relations_of_loaded_relations_in_batches(): void
    {
        $adapter = $this->orm->getAdapter();
        $hydrator = new ResourceModelHydrator();
        $loader = new ResourceModelRelationLoader($adapter, $hydrator);

        $orders = array_map(
            fn (array $row): object => $hydrator->hydrate($row, NelOrderFixture::class),
            $adapter->query('SELECT * FROM nel_orders ORDER BY id')->rows,
        );

        $loader->loadRelations($orders, NelOrderFixture::class, ['items.product']);

        $itemsOfFirst = $this->relationValue($orders[0], 'items');
        self::assertCount(2, $itemsOfFirst);

        $product = $this->relationValue($itemsOfFirst[0], 'product');
        self::assertInstanceOf(NelProductFixture::class, $product);
        self::assertSame('Widget', $product->title);

        $itemsOfSecond = $this->relationValue($orders[1], 'items');
        self::assertCount(1, $itemsOfSecond);
        self::assertSame('Widget', $this->relationValue($itemsOfSecond[0], 'product')->title);
    }

    #[Test]
    public function nested_loading_stays_batched_not_per_row(): void
    {
        $counting = new CountingAdapter($this->orm->getAdapter());
        $hydrator = new ResourceModelHydrator();
        $loader = new ResourceModelRelationLoader($counting, $hydrator);

        $orders = array_map(
            fn (array $row): object => $hydrator->hydrate($row, NelOrderFixture::class),
            $this->orm->getAdapter()->query('SELECT * FROM nel_orders ORDER BY id')->rows,
        );

        // One IN(...) SELECT for items (all orders), one for products (all
        // loaded items) — 2 total, regardless of row counts.
        $loader->loadRelations($orders, NelOrderFixture::class, ['items.product']);
        self::assertSame(2, $counting->executes);
    }

    #[Test]
    public function relation_ref_path_validates_every_segment(): void
    {
        $ref = RelationRef::path(NelOrderFixture::class, 'items.product');
        self::assertSame('items.product', $ref->propertyName);

        $this->expectException(\Throwable::class);
        RelationRef::path(NelOrderFixture::class, 'items.nonexistent');
    }

    private function relationValue(object $model, string $property): mixed
    {
        $value = (new \ReflectionProperty($model, $property))->getValue($model);

        return $value instanceof RelationState ? $value->value() : $value;
    }
}

/** Counts execute() calls; delegates everything to the wrapped adapter. */
final class CountingAdapter implements \Semitexa\Orm\Adapter\DatabaseAdapterInterface
{
    public int $executes = 0;

    public function __construct(private readonly \Semitexa\Orm\Adapter\DatabaseAdapterInterface $inner) {}

    public function supports(\Semitexa\Orm\Adapter\ServerCapability $capability): bool
    {
        return $this->inner->supports($capability);
    }

    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }

    public function execute(string $sql, array $params = []): \Semitexa\Orm\Adapter\QueryResult
    {
        $this->executes++;

        return $this->inner->execute($sql, $params);
    }

    public function query(string $sql): \Semitexa\Orm\Adapter\QueryResult
    {
        return $this->inner->query($sql);
    }

    public function lastInsertId(): string
    {
        return $this->inner->lastInsertId();
    }
}

#[FromTable(name: 'nel_orders')]
final readonly class NelOrderFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $label,

        #[HasMany(
            target: NelItemFixture::class,
            foreignKey: 'orderId',
            writePolicy: RelationWritePolicy::ReferenceOnly,
        )]
        public ?RelationState $items = null,
    ) {}
}

#[FromTable(name: 'nel_items')]
final readonly class NelItemFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $orderId,

        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $productId,

        #[BelongsTo(
            target: NelProductFixture::class,
            foreignKey: 'productId',
            writePolicy: RelationWritePolicy::ReferenceOnly,
        )]
        public ?RelationState $product = null,
    ) {}
}

#[FromTable(name: 'nel_products')]
final readonly class NelProductFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,

        #[Column(type: MySqlType::Varchar, length: 128)]
        public string $title,
    ) {}
}
