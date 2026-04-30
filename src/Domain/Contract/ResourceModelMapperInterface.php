<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Contract;

interface ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object;

    public function toSourceModel(object $domainModel): object;
}
