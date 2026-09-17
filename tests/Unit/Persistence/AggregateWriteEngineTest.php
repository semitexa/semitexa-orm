<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Exception\InvalidRelationWriteException;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Query\SystemScopeToken;
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

        $persisted = $engine->insert(
            $this->validDomainModel(id: ''),
            ValidProductResourceModel::class,
            $registry,
            systemScopeToken: SystemScopeToken::issue(),
        );

        $this->assertCount(3, $adapter->executed);
        $this->assertInstanceOf(PersistableProductDomainModel::class, $persisted);
        $this->assertNotSame('', $persisted->id);
        $this->assertSame(
            'INSERT INTO `products` (`id`, `tenantId`, `name`, `categoryId`, `deletedAt`) VALUES (:v0, :v1, :v2, :v3, :v4)',
            $adapter->executed[0]['sql'],
        );
        $this->assertNotSame('', $adapter->executed[0]['params']['v0']);
        $this->assertSame(
            'INSERT INTO `reviews` (`id`, `productId`, `rating`) VALUES (:v0, :v1, :v2)',
            $adapter->executed[1]['sql'],
        );
        $this->assertSame(
            'INSERT INTO `reviews` (`id`, `productId`, `rating`) VALUES (:v0, :v1, :v2)',
            $adapter->executed[2]['sql'],
        );
    }

    #[Test]
    public function update_rewrites_root_row_and_replaces_owned_children(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator());
        $registry = $this->buildRegistry();

        $engine->update(
            $this->validDomainModel(),
            ValidProductResourceModel::class,
            $registry,
            systemScopeToken: SystemScopeToken::issue(),
        );

        $this->assertCount(4, $adapter->executed);
        $this->assertSame(
            'UPDATE `products` SET `tenantId` = :tenantId, `name` = :name, `categoryId` = :categoryId, `deletedAt` = :deletedAt WHERE `id` = :__pk',
            $adapter->executed[0]['sql'],
        );
        $this->assertSame(
            'DELETE FROM `reviews` WHERE `productId` = :p_productId_1',
            $adapter->executed[1]['sql'],
        );
        $this->assertSame('INSERT INTO `reviews` (`id`, `productId`, `rating`) VALUES (:v0, :v1, :v2)', $adapter->executed[2]['sql']);
        $this->assertSame('INSERT INTO `reviews` (`id`, `productId`, `rating`) VALUES (:v0, :v1, :v2)', $adapter->executed[3]['sql']);
    }

    #[Test]
    public function delete_marks_soft_deletable_root_without_destroying_owned_children(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator());
        $registry = $this->buildRegistry();

        $engine->delete(
            $this->validDomainModel(),
            ValidProductResourceModel::class,
            $registry,
            systemScopeToken: SystemScopeToken::issue(),
        );

        $this->assertCount(1, $adapter->executed);
        $this->assertSame(
            'UPDATE `products` SET `deletedAt` = :__deleted_at WHERE `id` = :__pk',
            $adapter->executed[0]['sql'],
        );
        $this->assertSame('product-1', $adapter->executed[0]['params']['__pk']);
        $this->assertNotEmpty($adapter->executed[0]['params']['__deleted_at']);
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
            systemScopeToken: SystemScopeToken::issue(),
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

        // The two tags now go in ONE multi-row INSERT per sync (was one INSERT
        // per tag), while the delete-then-replace shape is unchanged.
        $this->assertSame(
            [
                'INSERT INTO `tagged_products` (`id`, `name`) VALUES (:v0, :v1)',
                'DELETE FROM `product_tags` WHERE `productId` = :p_productId_1',
                'INSERT INTO `product_tags` (`productId`, `tagId`) VALUES (:v0_0, :v0_1), (:v1_0, :v1_1)',
                'UPDATE `tagged_products` SET `name` = :name WHERE `id` = :__pk',
                'DELETE FROM `product_tags` WHERE `productId` = :p_productId_1',
                'INSERT INTO `product_tags` (`productId`, `tagId`) VALUES (:v0_0, :v0_1), (:v1_0, :v1_1)',
                'DELETE FROM `product_tags` WHERE `productId` = :p_productId_1',
                'DELETE FROM `tagged_products` WHERE `id` = :__pk',
            ],
            array_map(static fn (array $entry): string => $entry['sql'], $adapter->executed),
        );

        // The batched INSERT carries the same rows, one placeholder set per row.
        $batchedInsert = array_values(array_filter(
            $adapter->executed,
            static fn (array $e): bool => str_contains($e['sql'], 'VALUES (:v0_0'),
        ))[0];
        $this->assertSame([
            'v0_0' => 'product-1', 'v0_1' => 'tag-1',
            'v1_0' => 'product-1', 'v1_1' => 'tag-2',
        ], $batchedInsert['params']);
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
