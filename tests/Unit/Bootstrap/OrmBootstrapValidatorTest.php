<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Bootstrap\OrmBootstrapValidator;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryResourceModel;
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
}
