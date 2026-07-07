<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableCategoryDomainModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductMapper;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableReviewDomainModel;

/**
 * Aggregate writes are ATOMIC: the root row + cascade-owned children + pivot
 * sync either all commit or all roll back. Before the engine wrapped its
 * write path in TransactionManager::run(), a mid-cascade failure (here: the
 * child table is missing) committed the root row and lost the children —
 * partial aggregates with no error trail. Exercised against a real in-memory
 * SQLite driver so BEGIN/ROLLBACK actually execute.
 */
final class AggregateWriteEngineAtomicityTest extends TestCase
{
    private OrmManager $orm;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        // products exists; reviews (the cascade-owned child table) does NOT —
        // the second INSERT of the aggregate is guaranteed to fail mid-write.
        $this->orm->getAdapter()->execute(
            'CREATE TABLE products (id TEXT PRIMARY KEY, tenantId TEXT, name TEXT, categoryId TEXT, deletedAt TEXT)'
        );
    }

    #[Test]
    public function a_mid_cascade_failure_rolls_back_the_root_row(): void
    {
        $engine = $this->orm->getAggregateWriteEngine();

        try {
            $engine->insert($this->productWithReviews(), ValidProductResourceModel::class, $this->registry());
            self::fail('The cascade insert into the missing reviews table must throw.');
        } catch (\Throwable) {
            // expected — reviews table does not exist
        }

        self::assertSame(
            0,
            (int) $this->orm->getAdapter()->query('SELECT COUNT(*) AS c FROM products')->rows[0]['c'],
            'The root products row must be rolled back when a cascade-owned child insert fails.',
        );
    }

    #[Test]
    public function a_transactionless_engine_documents_the_legacy_partial_write(): void
    {
        // Control group: an engine built WITHOUT a TransactionManager (the
        // hand-built/test construction path) keeps the legacy non-atomic
        // behaviour — proving the atomicity above comes from the tx wrap,
        // not from driver/session side effects.
        $engine = new AggregateWriteEngine($this->orm->getAdapter(), new ResourceModelHydrator());

        try {
            $engine->insert($this->productWithReviews(), ValidProductResourceModel::class, $this->registry());
            self::fail('The cascade insert into the missing reviews table must throw.');
        } catch (\Throwable) {
            // expected
        }

        self::assertSame(
            1,
            (int) $this->orm->getAdapter()->query('SELECT COUNT(*) AS c FROM products')->rows[0]['c'],
            'Without a TransactionManager the root row survives — the documented legacy behaviour.',
        );
    }

    #[Test]
    public function a_successful_aggregate_write_commits_root_and_children(): void
    {
        $this->orm->getAdapter()->execute(
            'CREATE TABLE reviews (id TEXT PRIMARY KEY, productId TEXT, rating INTEGER)'
        );

        $engine = $this->orm->getAggregateWriteEngine();
        $engine->insert($this->productWithReviews(), ValidProductResourceModel::class, $this->registry());

        $adapter = $this->orm->getAdapter();
        self::assertSame(1, (int) $adapter->query('SELECT COUNT(*) AS c FROM products')->rows[0]['c']);
        self::assertSame(2, (int) $adapter->query('SELECT COUNT(*) AS c FROM reviews')->rows[0]['c']);
    }

    #[Test]
    public function an_aggregate_write_nests_inside_a_caller_transaction(): void
    {
        $this->orm->getAdapter()->execute(
            'CREATE TABLE reviews (id TEXT PRIMARY KEY, productId TEXT, rating INTEGER)'
        );

        $engine = $this->orm->getAggregateWriteEngine();

        // The engine's own atomically() must become a SAVEPOINT here, and the
        // whole write must roll back with the OUTER transaction.
        try {
            $this->orm->getTransactionManager()->run(function () use ($engine): void {
                $engine->insert($this->productWithReviews(), ValidProductResourceModel::class, $this->registry());
                throw new \RuntimeException('outer rollback');
            });
            self::fail('The outer transaction must re-throw.');
        } catch (\RuntimeException) {
            // expected
        }

        $adapter = $this->orm->getAdapter();
        self::assertSame(
            0,
            (int) $adapter->query('SELECT COUNT(*) AS c FROM products')->rows[0]['c'],
            'A caller-level rollback must also revert the nested aggregate write.',
        );
        self::assertSame(0, (int) $adapter->query('SELECT COUNT(*) AS c FROM reviews')->rows[0]['c']);
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
