<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Persistence;

final readonly class ReplicatedNote
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
    ) {}
}
