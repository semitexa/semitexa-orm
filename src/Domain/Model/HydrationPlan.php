<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Model;

/**
 * A cached, reflection-free recipe for hydrating one resource model class from
 * a DB row. Resolved once per class (constructor reflection + column/relation
 * metadata) and reused for every row — see {@see \Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator}.
 */
final class HydrationPlan
{
    /**
     * @param \ReflectionClass<object> $reflection the resource model class (for newInstanceArgs)
     * @param list<HydrationParameter> $parameters constructor parameters, in declaration order
     */
    public function __construct(
        public readonly \ReflectionClass $reflection,
        public readonly array $parameters,
    ) {}
}
