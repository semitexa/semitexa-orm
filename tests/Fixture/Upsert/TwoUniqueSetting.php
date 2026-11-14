<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Upsert;

final readonly class TwoUniqueSetting
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
    ) {}
}
