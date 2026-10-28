<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Application\Service\Transaction\SharedTestTransaction;
use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;
use Semitexa\Orm\Domain\Event\ResourceChangedEvent;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableCategoryDomainModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductMapper;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableReviewDomainModel;

/**
 * A test transaction is joined by every OrmManager of the process that serves
 * its connection name — including managers built the way application code
 * builds them, never handed the connection — and one rollback undoes all their
 * writes. SQLite on a file, so each manager would otherwise open a connection
 * of its own; the MySQL path is proven against the test database in
 * semitexa-testing (RollsBackDatabaseTest).
 */
final class SharedTestTransactionTest extends TestCase
{
    private const CONNECTION = 'shared_tx_probe';

    private string $path;
    private ConnectionConfig $config;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/semitexa-shared-tx-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->config = new ConnectionConfig(driver: 'sqlite', sqlitePath: $this->path);

        $schema = $this->appManager();
        $schema->getAdapter()->execute('CREATE TABLE products (id TEXT PRIMARY KEY, tenantId TEXT, name TEXT, categoryId TEXT, deletedAt TEXT)');
        $schema->getAdapter()->execute('CREATE TABLE reviews (id TEXT PRIMARY KEY, productId TEXT, rating INTEGER)');
        $schema->getAdapter()->execute('CREATE TABLE probe (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $schema->shutdown();
    }

    protected function tearDown(): void
    {
        // A failed assertion must not leave the transaction open for the next test.
        try {
            SharedTestTransaction::for(self::CONNECTION, 'sqlite')?->rollBack();
        } catch (\LogicException) {
        }

        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    #[Test]
    public function writes_of_managers_built_like_application_code_are_rolled_back(): void
    {
        $transaction = SharedTestTransaction::begin(self::CONNECTION, $this->config);

        // A repository-style aggregate write: the engine wraps it in its own
        // TransactionManager::run(), which must become a savepoint here.
        $this->appManager()->getAggregateWriteEngine()->insert(
            $this->productWithReviews(),
            ValidProductResourceModel::class,
            $this->registry(),
            tenantValue: 'tenant-1',
        );
        // A raw write through a second manager's plain adapter.
        $this->appManager()->getAdapter()->execute("INSERT INTO probe (v) VALUES ('raw')");
        // A transaction a third manager believes it commits.
        $this->appManager()->getTransactionManager()->run(
            static fn ($adapter) => $adapter->execute("INSERT INTO probe (v) VALUES ('committed')"),
        );

        // An in-process request wipes the CLI CoroutineLocal store; the test
        // transaction must survive it, or the next run() would try a second BEGIN.
        CoroutineLocal::endRequest();
        $this->appManager()->getTransactionManager()->run(
            static fn ($adapter) => $adapter->execute("INSERT INTO probe (v) VALUES ('after a request')"),
        );

        $inside = $this->appManager();
        self::assertSame(1, $this->rowsIn($inside, 'products'), 'the test sees its own writes');
        self::assertSame(2, $this->rowsIn($inside, 'reviews'));
        self::assertSame(3, $this->rowsIn($inside, 'probe'));

        $transaction->rollBack();

        $after = $this->appManager();
        self::assertSame(0, $this->rowsIn($after, 'products'), 'the aggregate write was rolled back');
        self::assertSame(0, $this->rowsIn($after, 'reviews'));
        self::assertSame(0, $this->rowsIn($after, 'probe'), 'raw and "committed" writes were rolled back');
    }

    /** One in-memory SQLite database per connection: the test's would be empty, and every manager pointed at it. */
    #[Test]
    public function an_in_memory_sqlite_database_is_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('in-memory SQLite');

        SharedTestTransaction::begin('memory-probe', new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
    }

    #[Test]
    public function the_change_signal_of_a_rolled_back_write_never_fires(): void
    {
        $dispatched = [];
        $spy = new class ($dispatched) implements EventDispatcherInterface {
            /** @param list<object> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function dispatch(object $event): void
            {
                $this->sink[] = $event;
            }

            public function create(string $eventClass, array $payload): object
            {
                return new $eventClass(...$payload);
            }

            public function addPostDispatchHook(callable $hook): void
            {
            }
        };

        $transaction = SharedTestTransaction::begin(self::CONNECTION, $this->config);
        $app = new OrmManager(config: $this->config, connectionName: self::CONNECTION, events: $spy);
        $app->getAggregateWriteEngine()->insert(
            $this->productWithReviews(),
            ValidProductResourceModel::class,
            $this->registry(),
            tenantValue: 'tenant-1',
        );
        $transaction->rollBack();

        self::assertSame([], $dispatched, 'no commit, so no post-commit signal');
        self::assertSame([], $app->getTransactionManager()->getPendingEvents(), 'and nothing left buffered for the next test');

        // Control: the same write, committed, reaches the spy — so the empty
        // list above is the rollback's doing, not a dispatcher never wired.
        $app->getAggregateWriteEngine()->insert(
            $this->productWithReviews(),
            ValidProductResourceModel::class,
            $this->registry(),
            tenantValue: 'tenant-1',
        );
        self::assertCount(1, $dispatched);
        /** @var list<object> $dispatched the spy filled it; phpstan still holds the empty list asserted above */
        self::assertInstanceOf(ResourceChangedEvent::class, $dispatched[0]);
        self::assertSame(ResourceChangeOperation::Insert, $dispatched[0]->operation);
    }

    #[Test]
    public function a_transaction_committed_before_the_end_fails_loudly(): void
    {
        $transaction = SharedTestTransaction::begin(self::CONNECTION, $this->config);
        $this->appManager()->getAdapter()->execute("INSERT INTO probe (v) VALUES ('leaks')");

        // What a MySQL DDL statement or TRUNCATE does implicitly.
        $transaction->connection->commit();

        try {
            $transaction->rollBack();
            self::fail('A rollback of a transaction that was already committed must not pass silently.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('was committed before the test ended', $e->getMessage());
        }

        self::assertNull(SharedTestTransaction::for(self::CONNECTION, 'sqlite'), 'the connection is no longer shared');
        self::assertSame(1, $this->rowsIn($this->appManager(), 'probe'), 'the row did leak, which is what the failure reports');
    }

    #[Test]
    public function on_sqlite_schema_changes_roll_back_with_the_rows(): void
    {
        $transaction = SharedTestTransaction::begin(self::CONNECTION, $this->config);
        $this->appManager()->getAdapter()->execute('CREATE TABLE made_in_test (id INTEGER PRIMARY KEY)');
        $transaction->rollBack();

        $tables = $this->appManager()->getAdapter()->execute(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'made_in_test'",
        )->rows;
        self::assertSame([], $tables);
    }

    #[Test]
    public function a_second_transaction_on_the_same_connection_is_refused(): void
    {
        $transaction = SharedTestTransaction::begin(self::CONNECTION, $this->config);

        try {
            SharedTestTransaction::begin(self::CONNECTION, $this->config);
            self::fail('A second test transaction must be refused.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('already open', $e->getMessage());
        } finally {
            $transaction->rollBack();
        }
    }

    #[Test]
    public function it_refuses_to_start_inside_a_coroutine(): void
    {
        $refusal = null;
        \Co\run(function () use (&$refusal): void {
            try {
                SharedTestTransaction::begin(self::CONNECTION, $this->config)->rollBack();
            } catch (\LogicException $e) {
                $refusal = $e->getMessage();
            }
        });

        self::assertNotNull($refusal, 'One PDO must never be shared between coroutines; begin() must refuse.');
        self::assertStringContainsString('inside a Swoole coroutine', $refusal);
    }

    /**
     * A Co\run() leaves SWOOLE_HOOK_ALL set behind it (unless flags were set
     * explicitly), so one coroutine test anywhere earlier in the run refused
     * every test transaction after it.
     */
    #[Test]
    public function hooks_left_on_outside_a_coroutine_do_not_refuse_it(): void
    {
        $flags = \Swoole\Runtime::getHookFlags();
        \Swoole\Coroutine::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        try {
            $transaction = SharedTestTransaction::begin(self::CONNECTION, $this->config);
            $this->appManager()->getAdapter()->execute("INSERT INTO probe (v) VALUES ('x')");
            $transaction->rollBack();

            self::assertSame(0, $this->rowsIn($this->appManager(), 'probe'));
            self::assertSame(SWOOLE_HOOK_ALL, \Swoole\Runtime::getHookFlags(), 'left as they were');
        } finally {
            // Never an explicit 0: every later Co\run() would run unhooked.
            \Swoole\Coroutine::set(['hook_flags' => $flags === 0 ? SWOOLE_HOOK_ALL : $flags]);
        }
    }

    /** A manager built the way ConnectionRegistry builds one — never handed the test's connection. */
    private function appManager(): OrmManager
    {
        return new OrmManager(config: $this->config, connectionName: self::CONNECTION);
    }

    private function rowsIn(OrmManager $orm, string $table): int
    {
        return (int) $orm->getAdapter()->execute("SELECT COUNT(*) AS c FROM {$table}")->rows[0]['c'];
    }

    private function registry(): MapperRegistry
    {
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [PersistableProductMapper::class],
            domainModelClasses: [PersistableProductDomainModel::class],
        );

        return $registry;
    }

    private function productWithReviews(): PersistableProductDomainModel
    {
        return new PersistableProductDomainModel(
            id: 'product-1',
            tenantId: 'tenant-1',
            name: 'Product 1',
            categoryId: 'category-1',
            category: new PersistableCategoryDomainModel(id: 'category-1', name: 'Category 1'),
            reviews: [
                new PersistableReviewDomainModel(id: 'review-1', productId: 'product-1', rating: 5),
                new PersistableReviewDomainModel(id: 'review-2', productId: 'product-1', rating: 4),
            ],
        );
    }
}
