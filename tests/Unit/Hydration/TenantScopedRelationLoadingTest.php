<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Attribute\BelongsTo;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\HasMany;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\OneToOne;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;
use Semitexa\Orm\Domain\Model\RelationState;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\RelationRef;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Query\TenantReadScope;

final class TenantScopedRelationLoadingTest extends TestCase
{
    private TenantRelationSqliteAdapter $adapter;
    private ResourceModelHydrator $hydrator;
    private ResourceModelRelationLoader $loader;

    protected function setUp(): void
    {
        $this->adapter = new TenantRelationSqliteAdapter('sqlite::memory:');
        $this->hydrator = new ResourceModelHydrator();
        $this->loader = new ResourceModelRelationLoader($this->adapter, $this->hydrator);
        $this->adapter->execute('CREATE TABLE tenant_relation_records (id TEXT PRIMARY KEY, tenant_key TEXT NOT NULL, orderId TEXT, detailId TEXT)');
        $this->adapter->execute('CREATE TABLE tenant_relation_orders (id TEXT PRIMARY KEY, tenantId TEXT NOT NULL, customerId TEXT REFERENCES tenant_relation_records(id))');
        $this->adapter->execute('CREATE TABLE tenant_relation_links (order_id TEXT, record_id TEXT)');
        $this->adapter->execute("INSERT INTO tenant_relation_records VALUES
            ('record-a', 'tenant-a', 'order-a', 'record-b'),
            ('record-b', 'tenant-b', 'order-a', 'record-a'),
            ('record-b-own', 'tenant-b', 'order-b', 'record-b')");
        $this->adapter->execute("INSERT INTO tenant_relation_orders VALUES
            ('order-a', 'tenant-a', 'record-a'),
            ('order-a-cross', 'tenant-a', 'record-b'),
            ('order-b', 'tenant-b', 'record-b-own')");
        $this->adapter->execute("INSERT INTO tenant_relation_links VALUES
            ('order-a', 'record-a'), ('order-a', 'record-b'),
            ('order-a-cross', 'record-b'), ('order-b', 'record-b-own')");
        $this->adapter->executed = [];
    }

    #[Test]
    public function belongs_to_cannot_reveal_a_record_hidden_by_a_direct_scoped_query(): void
    {
        $direct = $this->query(TenantRelationRecord::class)->forTenant('tenant-a')
            ->where(ColumnRef::for(TenantRelationRecord::class, 'id'), Operator::Equals, 'record-b')->fetchOne();
        self::assertNull($direct);

        $orders = $this->orders('tenant-a', ['customer']);
        self::assertSame('record-a', $orders['order-a']->customer->value()->id);
        self::assertTrue($orders['order-a-cross']->customer->isLoaded());
        self::assertNull($orders['order-a-cross']->customer->value());
    }

    /** @return iterable<string, array{string, bool, int}> */
    public static function relationKinds(): iterable
    {
        yield 'has many' => ['items', true, 2];
        yield 'one to one' => ['profile', false, 2];
        yield 'many to many' => ['tags', true, 3];
    }

    #[Test]
    #[DataProvider('relationKinds')]
    public function each_relation_filters_targets_and_stays_batched(string $relation, bool $many, int $queryCount): void
    {
        $orders = $this->orders('tenant-a', [$relation]);
        $value = $orders['order-a']->{$relation}->value();
        $items = $many ? $value : [$value];
        self::assertSame(['record-a'], array_column($items, 'id'));
        self::assertSame($many ? [] : null, $orders['order-a-cross']->{$relation}->value());
        self::assertCount($queryCount, $this->adapter->executed, 'Queries are batched across both parent rows.');

        $target = $this->adapter->executed[array_key_last($this->adapter->executed)];
        self::assertStringContainsString('`tenant_key` = :tenant_scope', $target['sql']);
        self::assertSame('tenant-a', $target['params']['tenant_scope']);
    }

    #[Test]
    public function nested_relations_keep_the_root_scope(): void
    {
        $orders = $this->orders('tenant-a', ['customer.detail']);
        $customer = $orders['order-a']->customer->value();
        self::assertSame('record-a', $customer->id);
        self::assertTrue($customer->detail->isLoaded());
        self::assertNull($customer->detail->value());
        self::assertNull($orders['order-a-cross']->customer->value());
        self::assertCount(3, $this->adapter->executed);
    }

    #[Test]
    public function an_explicit_system_token_applies_to_nested_relations(): void
    {
        $orders = $this->query(TenantRelationOrder::class)
            ->withoutTenantScope(SystemScopeToken::issue())
            ->withRelation(RelationRef::path(TenantRelationOrder::class, 'customer.detail'))->fetchAll();
        $orders = array_column($orders, null, 'id');
        self::assertCount(3, $orders);
        self::assertSame('record-b', $orders['order-a-cross']->customer->value()->id);
        self::assertSame('record-b', $orders['order-a']->customer->value()->detail->value()->id);
    }

    #[Test]
    public function scoped_targets_of_an_unscoped_root_require_explicit_context(): void
    {
        try {
            $this->query(TenantRelationUnscopedOrder::class)
                ->withRelation(RelationRef::for(TenantRelationUnscopedOrder::class, 'customer'))->fetchAll();
            self::fail('A scoped target must not be read without tenant context.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('requires tenant context', $e->getMessage());
            self::assertStringContainsString(TenantRelationRecord::class, $e->getMessage());
        }
        self::assertCount(1, $this->adapter->executed, 'Only the unscoped root may be queried.');
    }

    #[Test]
    public function an_unscoped_root_can_supply_context_for_its_scoped_targets(): void
    {
        $orders = $this->query(TenantRelationUnscopedOrder::class)->forTenant('tenant-a')
            ->withRelation(RelationRef::for(TenantRelationUnscopedOrder::class, 'customer'))->fetchAll();
        $orders = array_column($orders, null, 'id');
        self::assertCount(3, $orders);
        self::assertSame('record-a', $orders['order-a']->customer->value()->id);
        self::assertNull($orders['order-a-cross']->customer->value());
        self::assertNull($orders['order-b']->customer->value());
    }

    #[Test]
    public function direct_loading_without_context_fails_before_reading_the_pivot(): void
    {
        $order = $this->hydrator->hydrate([
            'id' => 'order-a', 'tenantId' => 'tenant-a', 'customerId' => 'record-a',
        ], TenantRelationOrder::class);
        try {
            $this->loader->loadRelations([$order], TenantRelationOrder::class, ['tags']);
            self::fail('Direct loading must not infer authority from a parent row.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('requires tenant context', $e->getMessage());
        }
        self::assertSame([], $this->adapter->executed);
    }

    #[Test]
    public function direct_loading_accepts_an_explicit_scope(): void
    {
        $order = $this->hydrator->hydrate([
            'id' => 'order-a', 'tenantId' => 'tenant-a', 'customerId' => 'record-a',
        ], TenantRelationOrder::class);
        $this->loader->loadRelations([$order], TenantRelationOrder::class, ['tags'], TenantReadScope::from('tenant-a', null));
        self::assertSame(['record-a'], array_column($order->tags->value(), 'id'));
    }

    #[Test]
    public function reusing_the_loader_does_not_retain_a_tenant_or_system_bypass(): void
    {
        $this->query(TenantRelationOrder::class)->withoutTenantScope(SystemScopeToken::issue())
            ->withRelation(RelationRef::for(TenantRelationOrder::class, 'customer'))->fetchAll();
        self::assertSame('record-b-own', $this->orders('tenant-b', ['customer'])['order-b']->customer->value()->id);
        self::assertNull($this->orders('tenant-a', ['customer'])['order-a-cross']->customer->value());
    }

    #[Test]
    public function overlapping_coroutines_keep_scope_through_the_next_relation(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Requires Swoole.');
        }
        $results = [];
        $errors = [];
        \Swoole\Coroutine\run(function () use (&$results, &$errors): void {
            $entered = new \Swoole\Coroutine\Channel(1);
            $resume = new \Swoole\Coroutine\Channel(1);
            $finished = new \Swoole\Coroutine\Channel(1);
            $this->adapter->beforeExecute = static function (string $sql, array $params) use ($entered, $resume): void {
                if (str_contains($sql, 'tenant_relation_records') && ($params['tenant_scope'] ?? null) === 'tenant-a') {
                    $entered->push(true, 2);
                    $resume->pop(2);
                }
            };
            \Swoole\Coroutine::create(function () use (&$results, &$errors, $finished): void {
                try {
                    $results['a'] = $this->orders('tenant-a', ['customer', 'items']);
                } catch (\Throwable $e) {
                    $errors[] = $e;
                } finally {
                    $finished->push(true, 2);
                }
            });
            $results['overlapped'] = $entered->pop(2);
            $this->adapter->beforeExecute = null;
            try {
                $results['b'] = $this->orders('tenant-b', ['customer', 'items']);
            } catch (\Throwable $e) {
                $errors[] = $e;
            } finally {
                $resume->push(true, 2);
                $results['finished'] = $finished->pop(2);
            }
        });
        self::assertSame([], $errors);
        self::assertTrue($results['overlapped']);
        self::assertTrue($results['finished']);
        self::assertSame(['record-a'], array_column($results['a']['order-a']->items->value(), 'id'));
        self::assertNull($results['a']['order-a-cross']->customer->value());
        self::assertSame(['record-b-own'], array_column($results['b']['order-b']->items->value(), 'id'));
    }

    /** @param class-string $class */
    private function query(string $class): ResourceModelQuery
    {
        return new ResourceModelQuery($class, $this->adapter, $this->hydrator, $this->loader);
    }

    /** @param list<string> $relations
     *  @return array<string, TenantRelationOrder>
     */
    private function orders(string $tenant, array $relations): array
    {
        $query = $this->query(TenantRelationOrder::class)->forTenant($tenant);
        foreach ($relations as $relation) {
            $query->withRelation(RelationRef::path(TenantRelationOrder::class, $relation));
        }
        return array_column($query->fetchAll(), null, 'id');
    }
}

final class TenantRelationSqliteAdapter extends SqliteAdapter
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $executed = [];
    public ?\Closure $beforeExecute = null;

    public function execute(string $sql, array $params = []): QueryResult
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];
        if ($this->beforeExecute !== null) {
            ($this->beforeExecute)($sql, $params);
        }
        return parent::execute($sql, $params);
    }
}

#[FromTable(name: 'tenant_relation_orders')]
#[TenantScoped(strategy: 'column', column: 'tenantId')]
final readonly class TenantRelationOrder
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')] #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 64)]
        public string $tenantId,
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $customerId,
        #[BelongsTo(target: TenantRelationRecord::class, foreignKey: 'customerId', writePolicy: RelationWritePolicy::ReferenceOnly)]
        public ?RelationState $customer = null,
        #[HasMany(target: TenantRelationRecord::class, foreignKey: 'orderId', writePolicy: RelationWritePolicy::ReferenceOnly)]
        public ?RelationState $items = null,
        #[OneToOne(target: TenantRelationRecord::class, foreignKey: 'orderId', writePolicy: RelationWritePolicy::ReferenceOnly)]
        public ?RelationState $profile = null,
        #[ManyToMany(target: TenantRelationRecord::class, pivotTable: 'tenant_relation_links', foreignKey: 'order_id', relatedKey: 'record_id', writePolicy: RelationWritePolicy::SyncPivotOnly)]
        public ?RelationState $tags = null,
    ) {}
}

#[FromTable(name: 'tenant_relation_records')]
#[TenantScoped(strategy: 'column', column: 'tenantId')]
final readonly class TenantRelationRecord
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')] #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 64, name: 'tenant_key')]
        public string $tenantId,
        #[Column(type: MySqlType::Varchar, length: 36, nullable: true)]
        public ?string $orderId,
        #[Column(type: MySqlType::Varchar, length: 36, nullable: true)]
        public ?string $detailId,
        #[BelongsTo(target: self::class, foreignKey: 'detailId', writePolicy: RelationWritePolicy::ReferenceOnly)]
        public ?RelationState $detail = null,
    ) {}
}

#[FromTable(name: 'tenant_relation_orders')]
final readonly class TenantRelationUnscopedOrder
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')] #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $customerId,
        #[BelongsTo(target: TenantRelationRecord::class, foreignKey: 'customerId', writePolicy: RelationWritePolicy::ReferenceOnly)]
        public ?RelationState $customer = null,
    ) {}
}
