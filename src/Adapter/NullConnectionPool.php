<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Sentinel pool implementation used when a driver does not need pooling
 * (e.g. SQLite). Every method either returns a safe no-op value or throws
 * — callers that reach pop() on this pool have a misconfiguration.
 *
 * Kept as a first-class class (rather than an anonymous class inline)
 * so stack traces, type hints, and static analysis stay readable.
 */
final class NullConnectionPool implements TenantSwitchingConnectionPoolInterface
{
    public function pop(float $timeout = -1): \PDO
    {
        throw new \LogicException(
            'NullConnectionPool::pop() was called — the active adapter does not need pooling.',
        );
    }

    public function push(\PDO $connection): void
    {
        // Intentional no-op: connections managed elsewhere.
    }

    public function close(): void
    {
        // Intentional no-op.
    }

    public function getSize(): int
    {
        return 0;
    }

    public function getAvailable(): int
    {
        return 0;
    }

    public function switchTo(string $tenantId): void
    {
        throw new \LogicException(sprintf(
            'Tenant switching is not supported for the current driver (requested tenant: %s).',
            $tenantId,
        ));
    }

    public function supportsTenantSwitch(): bool
    {
        return false;
    }
}
