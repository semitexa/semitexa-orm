<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Exception\DuplicateMapperException;
use Semitexa\Orm\Exception\InvalidMapperDeclarationException;
use Semitexa\Orm\Exception\MissingMapperException;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Tests\Fixture\Mapping\DuplicateValidProductMapper;

use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;

use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;

use Semitexa\Orm\Tests\Fixture\Mapping\InvalidMappedDomainModel;

use Semitexa\Orm\Tests\Fixture\Mapping\InvalidMappedProductMapper;

use Semitexa\Orm\Tests\Fixture\Mapping\TwiceDeclaredMapper;

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

    /**
     * THE KEY IS THE PAIR, and this is the case that says why.
     *
     * One row shape legitimately becomes more than one domain model — a full
     * record and a summary — so two mappers on one resource model are not a
     * duplicate and must not be treated as one. Nothing in this repository
     * depends on it today, which is exactly why it is written down here: the
     * obvious next helper is `mapperFor($resourceModelClass)`, and with two
     * legal definitions that helper has to pick one silently.
     */
    #[Test]
    public function two_mappers_on_one_resource_model_are_not_a_duplicate(): void
    {
        $registry = new MapperRegistry();
        $registry->build(mapperClasses: [
            ValidProductMapperInterface::class,
            InvalidMappedProductMapper::class,
        ]);

        $this->assertSame(
            ValidProductMapperInterface::class,
            $registry->definitionFor(ValidProductResourceModel::class, ValidProductDomainModel::class)->mapperClass,
        );
        $this->assertSame(
            InvalidMappedProductMapper::class,
            $registry->definitionFor(ValidProductResourceModel::class, InvalidMappedDomainModel::class)->mapperClass,
        );
        $this->assertCount(2, $registry->all());
    }

    /**
     * And the shape that IS a mistake: one class declaring the attribute twice.
     * PHP refuses to instantiate a non-repeatable attribute, so the second
     * declaration cannot be silently dropped by the `[0]` the registry reads —
     * but the raw Error names only the attribute. The registry says which class,
     * how many times, and what to do instead.
     */
    #[Test]
    public function a_class_declaring_the_attribute_twice_is_named_in_the_error(): void
    {
        $registry = new MapperRegistry();

        $this->expectException(InvalidMapperDeclarationException::class);
        $this->expectExceptionMessageMatches('/TwiceDeclaredMapper declares #\[AsMapper\] 2 times/');

        $registry->build(mapperClasses: [TwiceDeclaredMapper::class]);
    }
}