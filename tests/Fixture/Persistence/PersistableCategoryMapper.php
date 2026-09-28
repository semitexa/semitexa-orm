<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Persistence;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryResourceModel;

#[AsMapper(resourceModel: ValidCategoryResourceModel::class, domainModel: PersistableCategoryDomainModel::class)]
final class PersistableCategoryMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ValidCategoryResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        return new PersistableCategoryDomainModel($resourceModel->id, $resourceModel->name);
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof PersistableCategoryDomainModel || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ValidCategoryResourceModel($domainModel->id, $domainModel->name);
    }
}
