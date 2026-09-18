<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\OrmBootstrapValidator;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;
use Semitexa\Orm\Tests\Fixture\Mapping\ValidProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Mapping\ValidProductMapperInterface;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidReviewResourceModel;

final class OrmBootstrapValidatorTest extends TestCase
{
    #[Test]
    public function validates_metadata_and_mapper_bootstrap_as_one_pass(): void
    {
        $validator = new OrmBootstrapValidator(
            metadataRegistry: new ResourceModelMetadataRegistry(),
            mapperRegistry: new MapperRegistry(),
        );

        $report = $validator->validate(
            resourceModelClasses: [
                ValidCategoryResourceModel::class,
                ValidReviewResourceModel::class,
                HydratableProductResourceModel::class,
            ],
            mapperClasses: [HydratableProductMapper::class],
            domainModelClasses: [HydratableProductDomainModel::class],
        );

        $this->assertSame(
            [ValidCategoryResourceModel::class, ValidReviewResourceModel::class, HydratableProductResourceModel::class],
            $report->resourceModelClasses,
        );
        $this->assertSame([HydratableProductMapper::class], $report->mapperClasses);
        $this->assertSame([HydratableProductDomainModel::class], $report->domainModelClasses);
    }

    #[Test]
    public function derives_domain_models_from_mapper_declarations(): void
    {
        $validator = new OrmBootstrapValidator(
            metadataRegistry: new ResourceModelMetadataRegistry(),
            mapperRegistry: new MapperRegistry(),
        );

        $report = $validator->validate(
            resourceModelClasses: [HydratableProductResourceModel::class],
            mapperClasses: [HydratableProductMapper::class],
        );

        $this->assertSame([HydratableProductDomainModel::class], $report->domainModelClasses);
    }

    /**
     * A DIAGNOSTIC MUST NOT REBUILD WHAT IT INSPECTS.
     *
     * validate() called build() on the injected registry, and
     * OrmManager::getBootstrapValidator() injects the LIVE one. So
     * `validate(mapperClasses: [OneMapper::class])` — the public API, and the
     * shape a doctor check naturally writes — left the application's registry
     * holding exactly that one mapper, and every other mapToDomain() in the
     * worker threw MissingMapperException until something rebuilt it. Nothing
     * does: OrmManager builds the registry only when its field is null.
     */
    #[Test]
    public function validating_a_subset_does_not_empty_the_registry_it_was_given(): void
    {
        $live = new MapperRegistry();
        $live->build(mapperClasses: [HydratableProductMapper::class, ValidProductMapperInterface::class]);

        $validator = new OrmBootstrapValidator(
            metadataRegistry: new ResourceModelMetadataRegistry(),
            mapperRegistry: $live,
        );

        $report = $validator->validate(
            resourceModelClasses: [HydratableProductResourceModel::class],
            mapperClasses: [HydratableProductMapper::class],
        );

        // The report is about the subset it was asked about…
        self::assertSame([HydratableProductDomainModel::class], $report->domainModelClasses);

        // …and the registry the application is still using kept BOTH mappers.
        self::assertCount(2, $live->all());
        self::assertSame(
            ValidProductMapperInterface::class,
            $live->definitionFor(ValidProductResourceModel::class, ValidProductDomainModel::class)->mapperClass,
            'the mapper the subset did not name was dropped from the live registry',
        );
    }

    /**
     * The memoized mapper instances survive too. build() clears them, and a
     * rebuild under SWOOLE_HOOK_ALL is visible to every request in flight on
     * that worker — the same hazard the memoization comment on
     * OrmManager::getMapperRegistry() was written for.
     */
    #[Test]
    public function validating_does_not_discard_the_instances_the_registry_had_built(): void
    {
        $live = new MapperRegistry();
        $live->build(mapperClasses: [HydratableProductMapper::class]);
        $before = $live->mapperFor(HydratableProductResourceModel::class, HydratableProductDomainModel::class);

        (new OrmBootstrapValidator(
            metadataRegistry: new ResourceModelMetadataRegistry(),
            mapperRegistry: $live,
        ))->validate(
            resourceModelClasses: [HydratableProductResourceModel::class],
            mapperClasses: [HydratableProductMapper::class],
        );

        self::assertSame(
            $before,
            $live->mapperFor(HydratableProductResourceModel::class, HydratableProductDomainModel::class),
        );
    }

    /**
     * With no subset named, the live registry is read as it stands rather than
     * walked again — it was built from the same classmap this validate() would
     * walk, and walking it twice is the suspension point this change exists to
     * avoid.
     */
    #[Test]
    public function a_full_validation_reports_what_the_live_registry_holds(): void
    {
        $live = new MapperRegistry();
        $live->build(mapperClasses: [HydratableProductMapper::class, ValidProductMapperInterface::class]);

        $report = (new OrmBootstrapValidator(
            metadataRegistry: new ResourceModelMetadataRegistry(),
            mapperRegistry: $live,
        ))->validate(
            resourceModelClasses: [HydratableProductResourceModel::class, ValidProductResourceModel::class],
            mapperClasses: null,
        );

        self::assertEqualsCanonicalizing(
            [HydratableProductDomainModel::class, ValidProductDomainModel::class],
            $report->domainModelClasses,
        );
        self::assertCount(2, $live->all());
    }
}
