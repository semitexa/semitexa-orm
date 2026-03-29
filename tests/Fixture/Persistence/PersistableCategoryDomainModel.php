<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Persistence;

final readonly class PersistableCategoryDomainModel
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
