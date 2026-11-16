<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;
use Semitexa\Orm\Domain\Event\ResourceChangedEvent;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Tests\Fixture\BulkDelete\BulkLogResourceModel;
use Semitexa\Orm\Tests\Fixture\BulkDelete\BulkSessionResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

/**
 * orm-gap-4-bulk-writes: deleteWhere() removes the rows a query selects in one
 * statement — the rows that query would read, in its tenant scope and past its
 * soft-delete filter — and reports how many went.
 */
final class DeleteWhereTest extends TestCase
{
    private OrmManager $orm;
    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $db = $this->orm->getAdapter();
        $db->execute('CREATE TABLE bulk_sessions (id TEXT PRIMARY KEY, tenantId TEXT NOT NULL, state TEXT NOT NULL, version INTEGER NOT NULL, deletedAt TEXT)');
        $db->execute('CREATE TABLE bulk_logs (id TEXT PRIMARY KEY, level TEXT NOT NULL)');
        $db->execute('CREATE TABLE products (id TEXT PRIMARY KEY, tenantId TEXT NOT NULL, name TEXT, categoryId TEXT, deletedAt TEXT)');
        $db->execute("INSERT INTO bulk_sessions VALUES
            ('a1', 'acme', 'expired', 1, NULL), ('a2', 'acme', 'expired', 1, NULL), ('a3', 'acme', 'live', 1, NULL),
            ('a4', 'acme', 'expired', 3, '2026-01-01 00:00:00'),
            ('g1', 'globex', 'expired', 1, NULL)");
        $db->execute("INSERT INTO bulk_logs VALUES ('l1', 'debug'), ('l2', 'debug'), ('l3', 'debug'), ('l4', 'error')");
    }

    #[Test]
    public function a_soft_delete_model_is_soft_deleted_in_its_tenant_only(): void
    {
        $deleted = $this->sessions()->forTenant('acme')->deleteWhere(
            fn (ResourceModelQuery $q) => $q->where(BulkSessionResourceModel::column('state'), Operator::Equals, 'expired'),
        );

        self::assertSame(2, $deleted, 'a1 and a2: a4 was already deleted, g1 is another tenant\'s');
        self::assertSame([
            'a1' => [true, 2], 'a2' => [true, 2], 'a3' => [false, 1], 'a4' => [true, 3], 'g1' => [false, 1],
        ], $this->sessionRows());
    }

    #[Test]
    public function an_or_condition_stays_inside_the_tenant(): void
    {
        $deleted = $this->sessions()->forTenant('acme')->deleteWhere(fn (ResourceModelQuery $q) => $q
            ->where(BulkSessionResourceModel::column('state'), Operator::Equals, 'live')
            ->orWhere(BulkSessionResourceModel::column('id'), Operator::Equals, 'g1'));

        self::assertSame(1, $deleted);
        self::assertSame([false, 1], $this->sessionRows()['g1'], 'the OR must not reach globex');
        self::assertSame([true, 2], $this->sessionRows()['a3']);
    }

    #[Test]
    public function only_soft_deleted_purges_what_is_already_deleted(): void
    {
        $purged = $this->sessions()->forTenant('acme')->deleteWhere(fn (ResourceModelQuery $q) => $q
            ->onlySoftDeleted()
            ->where(BulkSessionResourceModel::column('state'), Operator::Equals, 'expired'));

        self::assertSame(1, $purged);
        self::assertSame(['a1', 'a2', 'a3', 'g1'], array_keys($this->sessionRows()), 'a4 is gone, nothing else');
    }

    #[Test]
    public function a_model_without_soft_delete_loses_the_rows(): void
    {
        $deleted = $this->logs()->deleteWhere(fn (ResourceModelQuery $q) => $q->where(BulkLogResourceModel::column('level'), Operator::Equals, 'debug'));

        self::assertSame(3, $deleted);
        self::assertSame(['l4'], array_column($this->orm->getAdapter()->query('SELECT id FROM bulk_logs ORDER BY id')->rows, 'id'));
    }

    #[Test]
    public function limit_deletes_in_batches_until_none_are_left(): void
    {
        $repository = $this->logs();
        $batch = fn (ResourceModelQuery $q) => $q->where(BulkLogResourceModel::column('level'), Operator::Equals, 'debug')->limit(2);

        self::assertSame([2, 1, 0], [$repository->deleteWhere($batch), $repository->deleteWhere($batch), $repository->deleteWhere($batch)]);
        self::assertSame(['l4'], array_column($this->orm->getAdapter()->query('SELECT id FROM bulk_logs')->rows, 'id'));
    }

    /**
     * The batch is chosen by a read that locks nothing, so a row another
     * transaction moved out of the match meanwhile is still in it: the write
     * checks every condition again on the row it locks, under parameter names
     * of its own (a native prepare refuses a name bound twice). Review, orm#119.
     */
    #[Test]
    public function a_batch_checks_its_conditions_again_on_the_rows_it_writes(): void
    {
        $sql = [];
        $recording = new class ($this->orm->getAdapter(), $sql) implements DatabaseAdapterInterface {
            /** @param list<array{string, array<string, mixed>}> $sql */
            public function __construct(private DatabaseAdapterInterface $inner, private array &$sql) {}

            public function supports(ServerCapability $capability): bool
            {
                return $this->inner->supports($capability);
            }

            public function getServerVersion(): string
            {
                return $this->inner->getServerVersion();
            }

            public function execute(string $sql, array $params = []): QueryResult
            {
                $this->sql[] = [$sql, $params];

                return $this->inner->execute($sql, $params);
            }

            public function query(string $sql): QueryResult
            {
                return $this->inner->query($sql);
            }

            public function lastInsertId(): string
            {
                return $this->inner->lastInsertId();
            }
        };
        $repository = new DomainRepository(BulkSessionResourceModel::class, BulkSessionResourceModel::class, $recording, new MapperRegistry());

        $deleted = $repository->forTenant('acme')->deleteWhere(fn (ResourceModelQuery $q) => $q
            ->where(BulkSessionResourceModel::column('state'), Operator::Equals, 'expired')
            ->whereRaw("id <> 'a:w0'")
            ->limit(5));

        self::assertSame(2, $deleted);
        self::assertCount(1, $sql);
        [$statement, $params] = $sql[0];
        self::assertSame(
            'UPDATE `bulk_sessions` SET `deletedAt` = :__bulk_deleted_at, `version` = `version` + 1'
            . ' WHERE `id` IN (SELECT `id` FROM (SELECT `id` FROM `bulk_sessions` WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL AND `state` = :w0 AND (id <> \'a:w0\') LIMIT 5) AS bulk_batch)'
            . ' AND (`tenantId` = :tenant_scope__outer AND `deletedAt` IS NULL AND `state` = :w0__outer AND (id <> \'a:w0\'))',
            $statement,
        );
        unset($params['__bulk_deleted_at']);
        self::assertSame(['tenant_scope' => 'acme', 'w0' => 'expired', 'tenant_scope__outer' => 'acme', 'w0__outer' => 'expired'], $params);
    }

    #[Test]
    public function a_tenant_scoped_model_with_no_scope_throws_before_any_row_changes(): void
    {
        try {
            $this->sessions()->deleteWhere(fn (ResourceModelQuery $q) => $q->where(BulkSessionResourceModel::column('state'), Operator::Equals, 'expired'));
            self::fail('a tenant-scoped delete without a scope must throw');
        } catch (\LogicException $e) {
            self::assertStringStartsWith('Query for tenant-scoped resource model ' . BulkSessionResourceModel::class . ' requires tenant context.', $e->getMessage());
        }

        self::assertSame(['a1' => [false, 1], 'a2' => [false, 1], 'a3' => [false, 1], 'a4' => [true, 3], 'g1' => [false, 1]], $this->sessionRows());
    }

    #[Test]
    public function the_scope_alone_is_not_a_choice_of_rows(): void
    {
        $this->expectExceptionObject(new \LogicException(
            'deleteWhere() on ' . BulkSessionResourceModel::class . ': the query has no condition of its own. Add where*() for the rows to delete.',
        ));

        $this->sessions()->forTenant('acme')->deleteWhere(static fn (ResourceModelQuery $q) => $q);
    }

    #[Test]
    public function include_soft_deleted_is_refused_as_ambiguous(): void
    {
        $this->expectExceptionObject(new \LogicException(
            'deleteWhere() on ' . BulkSessionResourceModel::class . ': includeSoftDeleted() mixes live rows (soft-deleted) with deleted ones (purged). Use one query for each: a plain one, or onlySoftDeleted().',
        ));

        $this->sessions()->forTenant('acme')->deleteWhere(fn (ResourceModelQuery $q) => $q
            ->includeSoftDeleted()
            ->where(BulkSessionResourceModel::column('state'), Operator::Equals, 'expired'));
    }

    #[Test]
    public function only_soft_deleted_on_a_model_without_soft_delete_is_refused_rather_than_deleting_live_rows(): void
    {
        try {
            $this->logs()->deleteWhere(fn (ResourceModelQuery $q) => $q->onlySoftDeleted()->where(BulkLogResourceModel::column('level'), Operator::Equals, 'debug'));
            self::fail('must be refused');
        } catch (\LogicException $e) {
            self::assertSame('deleteWhere() on ' . BulkLogResourceModel::class . ': the model has no soft delete, so onlySoftDeleted() and includeSoftDeleted() mean nothing here.', $e->getMessage());
        }

        self::assertSame(4, count($this->orm->getAdapter()->query('SELECT id FROM bulk_logs')->rows));
    }

    #[Test]
    public function an_owned_relation_is_refused_because_one_statement_cannot_cascade(): void
    {
        $products = new DomainRepository(ValidProductResourceModel::class, ValidProductResourceModel::class, $this->orm->getAdapter(), new MapperRegistry());

        $this->expectExceptionObject(new \LogicException(
            'deleteWhere() on ' . ValidProductResourceModel::class . ': relation reviews is written with its owner (cascade_owned), which one statement cannot do. Fetch the rows and delete() each.',
        ));

        $products->forTenant('acme')->deleteWhere(fn (ResourceModelQuery $q) => $q->where(ValidProductResourceModel::column('name'), Operator::Equals, 'x'));
    }

    #[Test]
    public function read_only_parts_of_a_query_are_refused(): void
    {
        $this->expectExceptionObject(new \LogicException(
            'deleteWhere() on ' . BulkLogResourceModel::class . ': orderBy(), offset() cannot shape a delete. limit() can, as a batch size.',
        ));

        $this->logs()->deleteWhere(fn (ResourceModelQuery $q) => $q
            ->where(BulkLogResourceModel::column('level'), Operator::Equals, 'debug')
            ->orderBy(BulkLogResourceModel::column('id'), \Semitexa\Orm\Query\Direction::Asc)
            ->offset(1));
    }

    #[Test]
    public function one_change_event_is_sent_when_rows_went_and_none_when_nothing_matched(): void
    {
        $repository = $this->logs(withEvents: true);

        $repository->deleteWhere(fn (ResourceModelQuery $q) => $q->where(BulkLogResourceModel::column('level'), Operator::Equals, 'debug'));
        $repository->deleteWhere(fn (ResourceModelQuery $q) => $q->where(BulkLogResourceModel::column('level'), Operator::Equals, 'nothing'));

        self::assertEquals([new ResourceChangedEvent('bulk_logs', ResourceChangeOperation::Delete)], $this->events);
    }

    #[Test]
    public function a_delete_in_a_rolled_back_transaction_is_undone(): void
    {
        $repository = $this->logs();
        try {
            $this->orm->getTransactionManager()->run(function () use ($repository): void {
                $repository->deleteWhere(fn (ResourceModelQuery $q) => $q->where(BulkLogResourceModel::column('level'), Operator::Equals, 'debug'));
                throw new \DomainException('roll back');
            });
        } catch (\DomainException) {
        }

        self::assertSame(4, count($this->orm->getAdapter()->query('SELECT id FROM bulk_logs')->rows));
    }

    private function sessions(): DomainRepository
    {
        return new DomainRepository(
            BulkSessionResourceModel::class,
            BulkSessionResourceModel::class,
            $this->orm->getAdapter(),
            new MapperRegistry(),
            transactions: $this->orm->getTransactionManager(),
        );
    }

    private function logs(bool $withEvents = false): DomainRepository
    {
        return new DomainRepository(
            BulkLogResourceModel::class,
            BulkLogResourceModel::class,
            $this->orm->getAdapter(),
            new MapperRegistry(),
            events: $withEvents ? $this->recorder() : null,
            transactions: $this->orm->getTransactionManager(),
        );
    }

    /** @return array<string, array{bool, int}> id => [soft-deleted, version] */
    private function sessionRows(): array
    {
        $out = [];
        foreach ($this->orm->getAdapter()->query('SELECT id, version, deletedAt FROM bulk_sessions ORDER BY id')->rows as $row) {
            $out[(string) $row['id']] = [$row['deletedAt'] !== null, (int) $row['version']];
        }

        return $out;
    }

    private function recorder(): EventDispatcherInterface
    {
        $events = &$this->events;

        return new class ($events) implements EventDispatcherInterface {
            /** @param list<object> $events */
            public function __construct(private array &$events) {}

            public function create(string $eventClass, array $payload): object
            {
                return new $eventClass(...$payload);
            }

            public function dispatch(object $event): void
            {
                $this->events[] = $event;
            }

            public function addPostDispatchHook(callable $hook): void {}
        };
    }
}
