<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Exception\DuplicateMapperException;
use Semitexa\Orm\Exception\InvalidMapperDeclarationException;
use Semitexa\Orm\Exception\MissingMapperException;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Tests\Fixture\Mapping\DuplicateValidProductMapper;

use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;

use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;

use Semitexa\Orm\Tests\Fixture\Mapping\InvalidMappedDomainModel;

use Semitexa\Orm\Tests\Fixture\Mapping\InvalidMappedProductMapper;

use Semitexa\Orm\Tests\Fixture\Mapping\MissingMapperDomainModel;

use Semitexa\Orm\Tests\Fixture\Mapping\NonImplementingMapper;

use Semitexa\Orm\Tests\Fixture\Mapping\ValidProductDomainModel;

use Semitexa\Orm\Tests\Fixture\Mapping\ValidProductMapperInterface;

use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;

use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

final class MapperRegistryTest extends TestCase
{
    #[Test]
    public function builds_valid_mapper_definitions(): void
    {
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [ValidProductMapperInterface::class]);

        $definition = $registry->definitionFor(ValidProductResourceModel::class, ValidProductDomainModel::class);

        $this->assertSame(ValidProductMapperInterface::class, $definition->mapperClass);
        $this->assertSame(ValidProductResourceModel::class, $definition->resourceModelClass);
        $this->assertSame(ValidProductDomainModel::class, $definition->domainModelClass);
    }

    #[Test]
    public function rejects_missing_mapper_for_unregistered_pair(): void
    {
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [ValidProductMapperInterface::class]);

        $this->expectException(MissingMapperException::class);

        $registry->definitionFor(ValidProductResourceModel::class, MissingMapperDomainModel::class);
    }

    #[Test]
    public function rejects_duplicate_mapper_pairs(): void
    {
        $registry = new MapperRegistry();

        $this->expectException(DuplicateMapperException::class);

        $registry->build(mapperClasses: [ValidProductMapperInterface::class, DuplicateValidProductMapper::class]);
    }

    #[Test]
    public function rejects_mappers_that_do_not_implement_the_contract(): void
    {
        $registry = new MapperRegistry();

        $this->expectException(InvalidMapperDeclarationException::class);

        $registry->build(mapperClasses: [NonImplementingMapper::class]);
    }

    #[Test]
    public function can_map_objects_via_registered_mapper_instances(): void
    {
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [HydratableProductMapper::class]);

        $resourceModel = new HydratableProductResourceModel(
            id: 'product-1',
            tenantId: 'tenant-1',
            name: 'Product 1',
            categoryId: 'category-1',
            deletedAt: null,
        );

        $domainModel = $registry->mapToDomain($resourceModel, HydratableProductDomainModel::class);

        $this->assertInstanceOf(HydratableProductDomainModel::class, $domainModel);
        $this->assertSame('product-1', $domainModel->id);
        $this->assertSame('tenant-1', $domainModel->tenantId);
    }
}
