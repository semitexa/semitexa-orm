<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class TenantScoped
{
    public function __construct(
        public readonly string $strategy = 'same_storage',
    ) {}
}
