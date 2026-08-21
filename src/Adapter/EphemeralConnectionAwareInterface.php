<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * A pool that can tell whether a specific handed-out connection is EPHEMERAL —
 * minted for one caller and dropped on push() — as opposed to a pooled
 * connection that will return to the pool.
 *
 * Why adapters ask: caching a prepared statement for an ephemeral connection
 * creates a WeakMap→PDOStatement→PDO reference cycle that only cycle-GC can
 * reclaim, so query-dense CLI/phpunit stretches hold every socket open until
 * the next sweep (observed as MySQL max_connections exhaustion mid-suite).
 * Statements are cached only for connections that actually come back.
 */
interface EphemeralConnectionAwareInterface
{
    public function isEphemeralConnection(\PDO $connection): bool;
}
