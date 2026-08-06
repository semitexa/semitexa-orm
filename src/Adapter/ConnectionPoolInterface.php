<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Provides database connections (pop/push). Implementations may use
 * Swoole\Coroutine\Channel for a real pool, or a single PDO when Swoole is not available (e.g. CLI).
 */
interface ConnectionPoolInterface
{
    /**
     * Take a connection, waiting up to `$timeout` seconds for a free one.
     *
     * `null` leaves the wait to the implementation, which is what every caller
     * that has no opinion should pass — a pooled implementation is expected to
     * bound it, so a pool that cannot serve anyone fails the request loudly
     * rather than parking it forever. `-1` explicitly means wait indefinitely.
     */
    public function pop(?float $timeout = null): \PDO;

    public function push(\PDO $connection): void;

    public function close(): void;

    public function getSize(): int;

    public function getAvailable(): int;

    /**
     * Switch future connections to the tenant-specific database.
     *
     * Implementations that cannot safely perform separate-db switching must
     * fail loudly instead of silently keeping the default database selected.
     */
    public function switchTo(string $tenantId): void;
}
