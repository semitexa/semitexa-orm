<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Persistence;

use Semitexa\Orm\Query\SystemScopeToken;

/**
 * Immutable per-operation tenant scope.
 *
 * AggregateWriteEngine is shared by all coroutines in a worker, so write scope
 * must travel on the call stack and must never be stored as mutable engine
 * state. A SystemScopeToken is deliberately reduced to a boolean here: its
 * presence is the explicit audit marker for an unscoped system write.
 */
final readonly class TenantWriteScope
{
    private function __construct(
        public mixed $tenantValue,
        public bool $bypass,
    ) {}

    public static function from(mixed $tenantValue, ?SystemScopeToken $systemScopeToken): self
    {
        return new self($tenantValue, $systemScopeToken !== null);
    }
}
