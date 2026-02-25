<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

final readonly class QueryLogEntry
{
    public function __construct(
        public string $sql,
        /** @var array<string, mixed> */
        public array $params,
        public float $timeMs,
    ) {}
}
