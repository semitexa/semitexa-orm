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
use Semitexa\Orm\Tests\Fixture\Mapping\ValidProductMapper;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductTableModel;

require_once __DIR__ . '/../../Fixture/Metadata/ValidCategoryTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidReviewTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidProductTableModel.php';
require_once __DIR__ . '/../../Fixture/Mapping/ValidProductDomainModel.php';
require_once __DIR__ . '/../../Fixture/Hydration/HydratableProductTableModel.php';
require_once __DIR__ . '/../../Fixture/Mapping/HydratableProductDomainModel.php';
require_once __DIR__ . '/../../Fixture/Mapping/HydratableProductMapper.php';
require_once __DIR__ . '/../../Fixture/Mapping/MissingMapperDomainModel.php';
require_once __DIR__ . '/../../Fixture/Mapping/InvalidMappedDomainModel.php';
require_once __DIR__ . '/../../Fixture/Mapping/ValidProductMapper.php';
require_once __DIR__ . '/../../Fixture/Mapping/DuplicateValidProductMapper.php';
require_once __DIR__ . '/../../Fixture/Mapping/InvalidMappedProductMapper.php';
require_once __DIR__ . '/../../Fixture/Mapping/NonImplementingMapper.php';

final class MapperRegistryTest extends TestCase
{
    #[Test]
    public function builds_valid_mapper_definitions(): void
    {
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [ValidProductMapper::class]);

        $definition = $registry->definitionFor(ValidProductTableModel::class, ValidProductDomainModel::class);

        $this->assertSame(ValidProductMapper::class, $definition->mapperClass);
        $this->assertSame(ValidProductTableModel::class, $definition->tableModelClass);
        $this->assertSame(ValidProductDomainModel::class, $definition->domainModelClass);
    }

    #[Test]
    public function rejects_missing_mapper_for_unregistered_pair(): void
    {
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [ValidProductMapper::class]);

        $this->expectException(MissingMapperException::class);

        $registry->definitionFor(ValidProductTableModel::class, MissingMapperDomainModel::class);
    }

    #[Test]
    public function rejects_duplicate_mapper_pairs(): void
    {
        $registry = new MapperRegistry();

        $this->expectException(DuplicateMapperException::class);

        $registry->build(mapperClasses: [ValidProductMapper::class, DuplicateValidProductMapper::class]);
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

        $tableModel = new HydratableProductTableModel(
            id: 'product-1',
            tenantId: 'tenant-1',
            name: 'Product 1',
            categoryId: 'category-1',
            deletedAt: null,
        );

        $domainModel = $registry->mapToDomain($tableModel, HydratableProductDomainModel::class);

        $this->assertInstanceOf(HydratableProductDomainModel::class, $domainModel);
        $this->assertSame('product-1', $domainModel->id);
        $this->assertSame('tenant-1', $domainModel->tenantId);
    }
}
