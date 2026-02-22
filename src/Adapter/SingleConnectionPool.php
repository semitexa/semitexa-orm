<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Single PDO connection — used when Swoole is not available (e.g. CLI).
 * pop() returns the same connection; push() stores it for reuse. No coroutines.
 */
final class SingleConnectionPool implements ConnectionPoolInterface
{
    private ?\PDO $connection = null;

    public function __construct(
        private readonly \Closure $factory,
    ) {}

    public function pop(float $timeout = -1): \PDO
    {
        if ($this->connection === null) {
            $this->connection = ($this->factory)();
        }

        return $this->connection;
    }

    public function push(\PDO $connection): void
    {
        $this->connection = $connection;
    }

    public function close(): void
    {
        $this->connection = null;
    }

    public function getSize(): int
    {
        return 1;
    }

    public function getAvailable(): int
    {
        return $this->connection !== null ? 1 : 0;
    }
}
