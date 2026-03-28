<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Mapping;

final readonly class MissingMapperDomainModel
{
    public function __construct(
        public string $id,
    ) {}
}
