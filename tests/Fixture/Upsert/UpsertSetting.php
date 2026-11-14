<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Upsert;

/** The domain side of an upserted settings row. */
final readonly class UpsertSetting
{
    public function __construct(
        public int $id,
        public string $tenantId,
        public string $name,
        public string $value,
        public string $createdAt,
        public int $version,
    ) {}
}
