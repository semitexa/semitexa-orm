<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Exception\InvalidRelationWriteException;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;

use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\ValidTaggedProductResourceModel;

use Semitexa\Orm\Tests\Fixture\Persistence\PersistableCategoryDomainModel;

use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductDomainModel;

use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductMapper;

use Semitexa\Orm\Tests\Fixture\Persistence\PersistableReviewDomainModel;

use Semitexa\Orm\Tests\Fixture\Persistence\TaggedProductDomainModel;

use Semitexa\Orm\Tests\Fixture\Persistence\TaggedProductMapper;

final class AggregateWriteEngineTest extends TestCase
{
    #[Test]
    public function insert_persists_root_row_and_owned_children_without_touching_reference_only_targets(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator());
        $registry = $this->buildRegistry();

        $persisted = $engine->insert($this->validDomainModel(id: ''), ValidProductResourceModel::class, $registry);

        $this->assertCount(3, $adapter->executed);
        $this->assertInstanceOf(PersistableProductDomainModel::class, $persisted);
        $this->assertNotSame('', $persisted->id);
        $this->assertSame(
            'INSERT INTO `products` (`id`, `tenantId`, `name`, `categoryId`, `deletedAt`) VALUES (:id, :tenantId, :name, :categoryId, :deletedAt)',
            $adapter->executed[0]['sql'],
        );
        $this->assertNotSame('', $adapter->executed[0]['params']['id']);
        $this->assertSame(
            'INSERT INTO `reviews` (`id`, `productId`, `rating`) VALUES (:id, :productId, :rating)',
            $adapter->executed[1]['sql'],
        );
        $this->assertSame(
            'INSERT INTO `reviews` (`id`, `productId`, `rating`) VALUES (:id, :productId, :rating)',
            $adapter->executed[2]['sql'],
        );
    }

    #[Test]
    public function update_rewrites_root_row_and_replaces_owned_children(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator());
        $registry = $this->buildRegistry();

        $engine->update($this->validDomainModel(), ValidProductResourceModel::class, $registry);

        $this->assertCount(4, $adapter->executed);
        $this->assertSame(
            'UPDATE `products` SET `tenantId` = :tenantId, `name` = :name, `categoryId` = :categoryId, `deletedAt` = :deletedAt WHERE `id` = :__pk',
            $adapter->executed[0]['sql'],
        );
        $this->assertSame(
            'DELETE FROM `reviews` WHERE `productId` = :__parent_fk',
            $adapter->executed[1]['sql'],
        );
        $this->assertSame('INSERT INTO `reviews` (`id`, `productId`, `rating`) VALUES (:id, :productId, :rating)', $adapter->executed[2]['sql']);
        $this->assertSame('INSERT INTO `reviews` (`id`, `productId`, `rating`) VALUES (:id, :productId, :rating)', $adapter->executed[3]['sql']);
    }

    #[Test]
    public function delete_removes_owned_children_before_root_row(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator());
        $registry = $this->buildRegistry();

        $engine->delete($this->validDomainModel(), ValidProductResourceModel::class, $registry);

        $this->assertCount(2, $adapter->executed);
        $this->assertSame('DELETE FROM `reviews` WHERE `productId` = :__parent_fk', $adapter->executed[0]['sql']);
        $this->assertSame('DELETE FROM `products` WHERE `id` = :__pk', $adapter->executed[1]['sql']);
    }

    #[Test]
    public function rejects_reference_only_relation_mismatches(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator());
        $registry = $this->buildRegistry();

        $this->expectException(InvalidRelationWriteException::class);

        $engine->insert(
            new PersistableProductDomainModel(
                id: 'product-1',
                tenantId: 'tenant-1',
                name: 'Product 1',
                categoryId: 'category-1',
                category: new PersistableCategoryDomainModel(
                    id: 'category-2',
                    name: 'Category 2',
                ),
                reviews: [],
            ),
            ValidProductResourceModel::class,
            $registry,
        );
    }

    #[Test]
    public function sync_pivot_only_replaces_pivot_rows_on_insert_update_and_delete(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator());
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [TaggedProductMapper::class],
            domainModelClasses: [TaggedProductDomainModel::class],
        );

        $domainModel = new TaggedProductDomainModel(
            id: 'product-1',
            name: 'Product 1',
            tagIds: ['tag-1', 'tag-2'],
        );

        $engine->insert($domainModel, ValidTaggedProductResourceModel::class, $registry);
        $engine->update($domainModel, ValidTaggedProductResourceModel::class, $registry);
        $engine->delete($domainModel, ValidTaggedProductResourceModel::class, $registry);

        $this->assertSame(
            [
                'INSERT INTO `tagged_products` (`id`, `name`) VALUES (:id, :name)',
                'DELETE FROM `product_tags` WHERE `productId` = :__pivot_fk',
                'INSERT INTO `product_tags` (`productId`, `tagId`) VALUES (:foreign_key, :related_key)',
                'INSERT INTO `product_tags` (`productId`, `tagId`) VALUES (:foreign_key, :related_key)',
                'UPDATE `tagged_products` SET `name` = :name WHERE `id` = :__pk',
                'DELETE FROM `product_tags` WHERE `productId` = :__pivot_fk',
                'INSERT INTO `product_tags` (`productId`, `tagId`) VALUES (:foreign_key, :related_key)',
                'INSERT INTO `product_tags` (`productId`, `tagId`) VALUES (:foreign_key, :related_key)',
                'DELETE FROM `product_tags` WHERE `productId` = :__pivot_fk',
                'DELETE FROM `tagged_products` WHERE `id` = :__pk',
            ],
            array_map(static fn (array $entry): string => $entry['sql'], $adapter->executed),
        );
    }

    private function buildRegistry(): MapperRegistry
    {
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [PersistableProductMapper::class],
            domainModelClasses: [PersistableProductDomainModel::class],
        );

        return $registry;
    }

    private function validDomainModel(string $id = 'product-1'): PersistableProductDomainModel
    {
        return new PersistableProductDomainModel(
            id: $id,
            tenantId: 'tenant-1',
            name: 'Product 1',
            categoryId: 'category-1',
            category: new PersistableCategoryDomainModel(
                id: 'category-1',
                name: 'Category 1',
            ),
            reviews: [
                new PersistableReviewDomainModel(
                    id: 'review-1',
                    productId: 'product-1',
                    rating: 5,
                ),
                new PersistableReviewDomainModel(
                    id: 'review-2',
                    productId: 'product-1',
                    rating: 4,
                ),
            ],
        );
    }
}
