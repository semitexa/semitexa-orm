<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Upsert;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

#[AsMapper(resourceModel: TwoUniqueSettingResource::class, domainModel: TwoUniqueSetting::class)]
final class TwoUniqueSettingMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof TwoUniqueSettingResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new TwoUniqueSetting($resourceModel->id, $resourceModel->name, $resourceModel->slug);
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof TwoUniqueSetting || throw new \InvalidArgumentException('Unexpected domain model.');

        return new TwoUniqueSettingResource($domainModel->id, $domainModel->name, $domainModel->slug);
    }
}
