<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Application\Service\Persistence\TenantWriteScope;
use Semitexa\Orm\Domain\Model\RelationState;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;

/**
 * A resource model read without withRelation() carries its relations as
 * "not loaded". Saving it back must not touch the children: not loaded is
 * not "none", and treating it so deleted every owned review of the product.
 */
final class NotLoadedRelationWriteTest extends TestCase
{
    #[Test]
    public function updating_a_model_whose_relation_was_never_loaded_leaves_the_children_alone(): void
    {
        $adapter = $this->adapter();
        $this->update($adapter, RelationState::notLoaded());

        foreach ($adapter->executed as $statement) {
            self::assertStringNotContainsString('`reviews`', $statement['sql']);
        }
        self::assertNotSame([], $adapter->executed, 'the root row is still written');
    }

    #[Test]
    public function a_loaded_empty_relation_still_replaces_the_children(): void
    {
        $adapter = $this->adapter();
        $this->update($adapter, RelationState::loadedEmptyCollection());

        $sql = array_column($adapter->executed, 'sql');
        self::assertNotEmpty(array_filter($sql, static fn (string $s): bool => str_starts_with($s, 'DELETE FROM `reviews`')));
    }

    private function adapter(): FakeDatabaseAdapter
    {
        // The tenant-scope probe that precedes a scoped write finds the row.
        return new FakeDatabaseAdapter([
            'SELECT 1 FROM `hydratable_products` WHERE `id` = :__pk AND `tenantId` = :__tenant_scope LIMIT 1' => [['1' => 1]],
        ]);
    }

    private function update(FakeDatabaseAdapter $adapter, RelationState $reviews): void
    {
        $product = new HydratableProductResourceModel(
            id: 'product-1',
            tenantId: 'tenant-a',
            name: 'Renamed',
            categoryId: 'category-1',
            deletedAt: null,
            category: RelationState::notLoaded(),
            reviews: $reviews,
        );

        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator());
        (new \ReflectionMethod($engine, 'updateResourceModel'))
            ->invoke($engine, $product, $adapter, TenantWriteScope::from('tenant-a', null));
    }
}
