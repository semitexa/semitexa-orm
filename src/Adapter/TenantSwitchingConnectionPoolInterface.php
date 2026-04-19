<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Optional capability interface for pools that can report tenant-switch support
 * without forcing that method onto every external ConnectionPoolInterface implementation.
 */
interface TenantSwitchingConnectionPoolInterface extends ConnectionPoolInterface
{
    public function supportsTenantSwitch(): bool;
}
