<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductTableModel;

#[AsMapper(resourceModel: ValidProductTableModel::class, domainModel: ValidProductDomainModel::class)]
final class ValidProductMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof ValidProductTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        return new ValidProductDomainModel(
            id: $tableModel->id,
            tenantId: $tableModel->tenantId,
            name: $tableModel->name,
            categoryId: $tableModel->categoryId,
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof ValidProductDomainModel || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ValidProductTableModel(
            id: $domainModel->id,
            tenantId: $domainModel->tenantId,
            name: $domainModel->name,
            categoryId: $domainModel->categoryId,
        );
    }
}
