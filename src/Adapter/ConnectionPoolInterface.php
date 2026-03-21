<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Provides database connections (pop/push). Implementations may use
 * Swoole\Coroutine\Channel for a real pool, or a single PDO when Swoole is not available (e.g. CLI).
 */
interface ConnectionPoolInterface
{
    public function pop(float $timeout = -1): \PDO;

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
