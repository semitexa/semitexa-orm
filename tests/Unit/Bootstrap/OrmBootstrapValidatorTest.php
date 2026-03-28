<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Bootstrap\OrmBootstrapValidator;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductTableModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidReviewTableModel;

require_once __DIR__ . '/../../Fixture/Metadata/ValidCategoryTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/ValidReviewTableModel.php';
require_once __DIR__ . '/../../Fixture/Hydration/HydratableProductTableModel.php';
require_once __DIR__ . '/../../Fixture/Mapping/HydratableProductDomainModel.php';
require_once __DIR__ . '/../../Fixture/Mapping/HydratableProductMapper.php';

final class OrmBootstrapValidatorTest extends TestCase
{
    #[Test]
    public function validates_metadata_and_mapper_bootstrap_as_one_pass(): void
    {
        $validator = new OrmBootstrapValidator(
            metadataRegistry: new TableModelMetadataRegistry(),
            mapperRegistry: new MapperRegistry(),
        );

        $report = $validator->validate(
            tableModelClasses: [
                ValidCategoryTableModel::class,
                ValidReviewTableModel::class,
                HydratableProductTableModel::class,
            ],
            mapperClasses: [HydratableProductMapper::class],
            domainModelClasses: [HydratableProductDomainModel::class],
        );

        $this->assertSame(
            [ValidCategoryTableModel::class, ValidReviewTableModel::class, HydratableProductTableModel::class],
            $report->tableModelClasses,
        );
        $this->assertSame([HydratableProductMapper::class], $report->mapperClasses);
        $this->assertSame([HydratableProductDomainModel::class], $report->domainModelClasses);
    }

    #[Test]
    public function derives_domain_models_from_mapper_declarations(): void
    {
        $validator = new OrmBootstrapValidator(
            metadataRegistry: new TableModelMetadataRegistry(),
            mapperRegistry: new MapperRegistry(),
        );

        $report = $validator->validate(
            tableModelClasses: [HydratableProductTableModel::class],
            mapperClasses: [HydratableProductMapper::class],
        );

        $this->assertSame([HydratableProductDomainModel::class], $report->domainModelClasses);
    }
}
