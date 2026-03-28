<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TenantScoped
{
    public function __construct(
        public string $strategy = 'same_storage',
        public ?string $column = null,
    ) {}
}
