<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

final readonly class SystemScopeToken
{
    private function __construct() {}

    public static function issue(): self
    {
        return new self();
    }
}
