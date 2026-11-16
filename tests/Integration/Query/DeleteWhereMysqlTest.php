<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Tests\Fixture\BulkDelete\BulkSessionResourceModel;
use Semitexa\Orm\Tests\Support\RequiresDatabase;

/**
 * deleteWhere() with limit() on MySQL: the batch selects its rows from the table
 * it writes, which MySQL refuses (error 1093) unless the select is a derived
 * table. Proven here for the soft-delete UPDATE, the statement where it matters.
 */
final class DeleteWhereMysqlTest extends TestCase
{
    use RequiresDatabase;

    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = self::mysqlOrm('a batch through a derived table is what MySQL needs');
        $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS bulk_sessions');
        $this->orm->getAdapter()->execute(
            'CREATE TABLE bulk_sessions (id VARCHAR(36) PRIMARY KEY, tenantId VARCHAR(64) NOT NULL, state VARCHAR(32) NOT NULL,'
            . ' version INT NOT NULL, deletedAt DATETIME NULL) ENGINE=InnoDB',
        );
        $this->orm->getAdapter()->execute("INSERT INTO bulk_sessions VALUES
            ('a1', 'acme', 'expired', 1, NULL), ('a2', 'acme', 'expired', 1, NULL), ('a3', 'acme', 'expired', 1, NULL),
            ('g1', 'globex', 'expired', 1, NULL)");
    }

    protected function tearDown(): void
    {
        if (isset($this->orm)) {
            try {
                $this->orm->getAdapter()->execute('DROP TABLE IF EXISTS bulk_sessions');
            } finally {
                $this->orm->shutdown();
            }
        }
    }

    #[Test]
    public function a_soft_delete_in_batches_goes_until_the_tenant_has_none_left(): void
    {
        $repository = (new DomainRepository(
            BulkSessionResourceModel::class,
            BulkSessionResourceModel::class,
            $this->orm->getAdapter(),
            new MapperRegistry(),
            transactions: $this->orm->getTransactionManager(),
        ))->forTenant('acme');
        $batch = fn (ResourceModelQuery $q) => $q->where(BulkSessionResourceModel::column('state'), Operator::Equals, 'expired')->limit(2);

        self::assertSame([2, 1, 0], [$repository->deleteWhere($batch), $repository->deleteWhere($batch), $repository->deleteWhere($batch)]);
        self::assertSame(
            [['id' => 'a1', 'gone' => 1, 'version' => 2], ['id' => 'a2', 'gone' => 1, 'version' => 2], ['id' => 'a3', 'gone' => 1, 'version' => 2], ['id' => 'g1', 'gone' => 0, 'version' => 1]],
            array_map(
                static fn (array $r): array => ['id' => $r['id'], 'gone' => (int) $r['gone'], 'version' => (int) $r['version']],
                $this->orm->getAdapter()->query('SELECT id, deletedAt IS NOT NULL AS gone, version FROM bulk_sessions ORDER BY id')->rows,
            ),
        );
    }
}
