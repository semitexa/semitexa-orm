<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductTableModel;

#[AsMapper(resourceModel: HydratableProductTableModel::class, domainModel: HydratableProductDomainModel::class)]
final class HydratableProductMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof HydratableProductTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        return new HydratableProductDomainModel(
            id: $tableModel->id,
            tenantId: $tableModel->tenantId,
            name: $tableModel->name,
            categoryId: $tableModel->categoryId,
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof HydratableProductDomainModel || throw new \InvalidArgumentException('Unexpected domain model.');

        return new HydratableProductTableModel(
            id: $domainModel->id,
            tenantId: $domainModel->tenantId,
            name: $domainModel->name,
            categoryId: $domainModel->categoryId,
            deletedAt: null,
        );
    }
}
