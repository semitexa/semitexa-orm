<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

use Swoole\Atomic;
use Swoole\Coroutine\Channel;

class ConnectionPool implements ConnectionPoolInterface
{
    private Channel $pool;

    /**
     * Atomic counter for the number of connections created so far.
     *
     * Swoole\Atomic operations (add, cmpset) are implemented via C-level
     * atomic instructions — they never suspend the coroutine, so there is
     * no window for another coroutine to observe a half-updated value.
     * This eliminates the race condition where two coroutines simultaneously
     * passed the `isEmpty() && created < size` check and both created a
     * new connection, exceeding the pool limit.
     */
    private Atomic $created;

    public function __construct(
        private readonly int $size,
        private readonly \Closure $factory,
    ) {
        $this->pool    = new Channel($size);
        $this->created = new Atomic(0);
    }

    public function pop(float $timeout = -1): \PDO
    {
        // Atomically claim a slot: increment only if we are still below the
        // limit. cmpset(expected, new) returns true exactly once per slot.
        $current = $this->created->get();

        if ($this->pool->isEmpty() && $current < $this->size) {
            if ($this->created->cmpset($current, $current + 1)) {
                // We won the race — create and return the new connection
                // without pushing it to the channel first.
                return ($this->factory)();
            }
        }

        // Either pool is not empty, limit is reached, or another coroutine
        // won the cmpset race — wait for a returned connection.
        $connection = $this->pool->pop($timeout);

        if ($connection === false) {
            throw new \RuntimeException('Failed to obtain database connection from pool (timeout).');
        }

        return $this->ensureAlive($connection);
    }

    public function push(\PDO $connection): void
    {
        $this->pool->push($connection);
    }

    /**
     * Eagerly open all connections and push them into the channel.
     * Call this once during worker start (inside a coroutine context) to
     * avoid any lazy-creation overhead on the first requests.
     */
    public function fill(): void
    {
        $current = $this->created->get();

        while ($current < $this->size) {
            if ($this->created->cmpset($current, $current + 1)) {
                $this->pool->push(($this->factory)());
            }

            $current = $this->created->get();
        }
    }

    public function close(): void
    {
        while (!$this->pool->isEmpty()) {
            $this->pool->pop();
        }

        $this->pool->close();
        $this->created->set(0);
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getAvailable(): int
    {
        return $this->pool->length();
    }

    /**
     * Verify that a pooled connection is still alive and reconnect if not.
     *
     * Connections that sat idle in the channel may have been dropped by MySQL
     * (wait_timeout) or by an intermediate proxy/firewall. A cheap SELECT 1
     * detects the broken socket before the caller issues a real query.
     *
     * Only called for connections retrieved from the channel (not for freshly
     * created ones), so the overhead is limited to idle connections.
     * The $created counter is NOT incremented on reconnect — the slot was
     * already claimed when the connection was first opened.
     */
    private function ensureAlive(\PDO $connection): \PDO
    {
        try {
            $connection->query('SELECT 1');
            return $connection;
        } catch (\PDOException) {
            // Connection is stale — replace it with a fresh one.
            return ($this->factory)();
        }
    }
}
