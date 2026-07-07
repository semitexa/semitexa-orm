<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * The one prepare-and-cache discipline shared by the statement-caching
 * adapters (pooled MysqlAdapter, transactional SingleConnectionAdapter).
 * The cache TOPOLOGIES differ — WeakMap-per-connection vs one array for one
 * connection — but the per-cache rules must never drift apart: evict
 * wholesale at the cap, guard prepare() returning false, store under the
 * verbatim SQL key.
 */
trait PreparesCachedStatements
{
    /**
     * Per-connection prepared-statement cap. ORM SQL is templated (a finite
     * set per worker), but whereRaw() can mint unbounded shapes — reset the
     * cache rather than grow without bound.
     */
    private const STATEMENT_CACHE_MAX = 256;

    /**
     * Prepare $sql on $connection and store it in $cache (evicting wholesale
     * at the cap first). The caller owns writing a by-value cache back to its
     * container; by-reference callers are done when this returns.
     *
     * @param array<string, \PDOStatement> $cache
     */
    private function prepareIntoCache(\PDO $connection, string $sql, array &$cache): \PDOStatement
    {
        if (count($cache) >= self::STATEMENT_CACHE_MAX) {
            $cache = [];
        }
        $stmt = $connection->prepare($sql);
        if ($stmt === false) {
            // Unreachable under ERRMODE_EXCEPTION; guards the type either way.
            throw new \RuntimeException('PDO::prepare returned false for: ' . $sql);
        }
        $cache[$sql] = $stmt;

        return $stmt;
    }
}
