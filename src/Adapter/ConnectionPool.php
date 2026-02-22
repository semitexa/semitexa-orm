<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

use Swoole\Coroutine\Channel;

class ConnectionPool implements ConnectionPoolInterface
{
    private Channel $pool;
    private int $created = 0;

    public function __construct(
        private readonly int $size,
        private readonly \Closure $factory,
    ) {
        $this->pool = new Channel($size);
    }

    public function pop(float $timeout = -1): \PDO
    {
        if ($this->pool->isEmpty() && $this->created < $this->size) {
            $this->created++;
            return ($this->factory)();
        }

        $connection = $this->pool->pop($timeout);

        if ($connection === false) {
            throw new \RuntimeException('Failed to obtain database connection from pool (timeout).');
        }

        return $connection;
    }

    public function push(\PDO $connection): void
    {
        $this->pool->push($connection);
    }

    public function fill(): void
    {
        while ($this->created < $this->size) {
            $this->created++;
            $this->pool->push(($this->factory)());
        }
    }

    public function close(): void
    {
        while (!$this->pool->isEmpty()) {
            $this->pool->pop();
        }

        $this->pool->close();
        $this->created = 0;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getAvailable(): int
    {
        return $this->pool->length();
    }
}
