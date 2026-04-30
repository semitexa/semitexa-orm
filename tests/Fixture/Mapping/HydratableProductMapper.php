<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;

#[AsMapper(resourceModel: HydratableProductResourceModel::class, domainModel: HydratableProductDomainModel::class)]
final class HydratableProductMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof HydratableProductResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        return new HydratableProductDomainModel(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenantId,
            name: $resourceModel->name,
            categoryId: $resourceModel->categoryId,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof HydratableProductDomainModel || throw new \InvalidArgumentException('Unexpected domain model.');

        return new HydratableProductResourceModel(
            id: $domainModel->id,
            tenantId: $domainModel->tenantId,
            name: $domainModel->name,
            categoryId: $domainModel->categoryId,
            deletedAt: null,
        );
    }
}
