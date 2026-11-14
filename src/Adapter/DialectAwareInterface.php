<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * An adapter that knows its dialect. Separate from DatabaseAdapterInterface, which some
 * fifteen test doubles across packages implement: adding a method there would break
 * them all (the EphemeralConnectionAwareInterface pattern).
 */
interface DialectAwareInterface
{
    public function dialect(): SqlDialect;
}
