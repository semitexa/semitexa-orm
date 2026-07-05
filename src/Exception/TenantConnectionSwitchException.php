<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * A non-default tenant was resolved for the request, but the connection pool
 * could not be switched to that tenant's database.
 *
 * Proceeding would run the request against the previous/default connection — a
 * cross-tenant isolation breach — so the listener fails closed and aborts. A
 * pool that intentionally shares one database (row-level tenant scoping) opts
 * out via {@see \Semitexa\Orm\Adapter\TenantSwitchingConnectionPoolInterface::supportsTenantSwitch()}
 * returning false, which is skipped before any switch is attempted; this
 * exception only fires when a switch was genuinely expected but failed.
 */
final class TenantConnectionSwitchException extends \RuntimeException
{
    public function __construct(
        public readonly string $tenantId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Could not switch the connection pool to resolved tenant "%s"; refusing to serve the request on the wrong connection.',
                $tenantId,
            ),
            0,
            $previous,
        );
    }
}
