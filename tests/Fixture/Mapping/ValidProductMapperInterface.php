<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;

#[AsMapper(resourceModel: ValidProductResourceModel::class, domainModel: ValidProductDomainModel::class)]
final class ValidProductMapperInterface implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ValidProductResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        return new ValidProductDomainModel(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenantId,
            name: $resourceModel->name,
            categoryId: $resourceModel->categoryId,
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof ValidProductDomainModel || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ValidProductResourceModel(
            id: $domainModel->id,
            tenantId: $domainModel->tenantId,
            name: $domainModel->name,
            categoryId: $domainModel->categoryId,
        );
    }
}
