<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

final readonly class HydratableProductDomainModel
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public string $categoryId,
    ) {}
}
