<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Upsert;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;

#[AsMapper(resourceModel: UpsertSettingResource::class, domainModel: UpsertSetting::class)]
final class UpsertSettingMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof UpsertSettingResource || throw new \InvalidArgumentException('Unexpected resource model.');

        return new UpsertSetting($resourceModel->id, $resourceModel->tenantId, $resourceModel->name, $resourceModel->value, $resourceModel->createdAt, $resourceModel->version);
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof UpsertSetting || throw new \InvalidArgumentException('Unexpected domain model.');

        return new UpsertSettingResource($domainModel->id, $domainModel->tenantId, $domainModel->name, $domainModel->value, $domainModel->createdAt, $domainModel->version);
    }
}
