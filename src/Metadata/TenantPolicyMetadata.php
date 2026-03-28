<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

final readonly class TenantPolicyMetadata
{
    public function __construct(
        public string $strategy,
        public ?string $column = null,
    ) {}
}
