<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

class MysqlAdapter implements DatabaseAdapterInterface
{
    use PreparesCachedStatements;

    private string $serverVersion = '';

    /**
     * Prepared-statement cache, keyed per PDO connection. The pool uses
     * native prepares (ATTR_EMULATE_PREPARES=false), so every prepare() is a
     * server round-trip; templated ORM SQL repeats constantly. A statement
     * belongs to its connection: WeakMap keys by the PDO instance so a
     * healed/discarded connection takes its statements with it, and the
     * statement is only ever used inside this method's pop()/push() window —
     * the connection (and thus the statement) has exactly one owner at a time.
     *
     * CAVEAT for future tenant-switching pools: MySQL binds the default
     * schema at PREPARE time. A pool whose switchTo() re-points an existing
     * connection to another database MUST clear that connection's cached
     * statements, or pre-switch statements would execute against the previous
     * tenant's schema. No in-repo pool implements switchTo() today (all
     * throw) — revisit before shipping one.
     *
     * @var \WeakMap<\PDO, array<string, \PDOStatement>>
     */
    private \WeakMap $statements;

    public function __construct(
        private readonly ConnectionPoolInterface $pool,
    ) {
        $this->statements = new \WeakMap();
    }

    public function supports(ServerCapability $capability): bool
    {
        if ($this->serverVersion === '') {
            $this->detectVersion();
        }

        $minVersion = ServerCapability::minimumVersions()[$capability->value] ?? null;

        if ($minVersion === null) {
            return false;
        }

        return version_compare($this->serverVersion, $minVersion, '>=');
    }

    public function getServerVersion(): string
    {
        if ($this->serverVersion === '') {
            $this->detectVersion();
        }

        return $this->serverVersion;
    }

    public function execute(string $sql, array $params = []): QueryResult
    {
        $connection = $this->pool->pop();

        try {
            $stmt = $this->preparedStatement($connection, $sql);
            try {
                $stmt->execute($params);
            } catch (\PDOException $e) {
                // Defensive re-prepare, gated to MySQL 1615 ("statement needs
                // to be re-prepared" — DDL invalidated the cached statement).
                // NEVER retry other errors: a deadlock (1213) or lock-wait
                // rollback inside an open transaction destroys the tx, and a
                // blind re-execute would silently succeed in autocommit —
                // partial writes with no error trail.
                if (($e->errorInfo[1] ?? null) !== 1615) {
                    throw $e;
                }
                $this->forgetStatement($connection, $sql);
                $stmt = $this->preparedStatement($connection, $sql);
                $stmt->execute($params);
            }

            // Materialize ALL data before returning connection to pool.
            // This is critical for coroutine safety — after push(), another
            // coroutine may reuse this PDO and invalidate the PDOStatement.
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $rowCount = $stmt->rowCount();
            $lastInsertId = $connection->lastInsertId() ?: '0';
            $stmt->closeCursor();

            return new QueryResult(
                rows: $rows,
                rowCount: $rowCount,
                lastInsertId: $lastInsertId,
            );
        } finally {
            $this->pool->push($connection);
        }
    }

    /**
     * Execute a raw SQL query without prepared statements.
     *
     * This method does not support parameter binding and should not be used
     * with user-supplied input. Prefer execute($sql, $params) for queries
     * that need parameters or input sanitization.
     *
     * Intended primarily for trusted raw SQL and internal adapter operations
     * such as bootstrapping/version detection.
     */
    public function query(string $sql): QueryResult
    {
        $connection = $this->pool->pop();

        try {
            $stmt = $connection->query($sql);
            if ($stmt === false) {
                throw new \RuntimeException("Query failed: {$sql}");
            }
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $rowCount = $stmt->rowCount();
            $lastInsertId = $connection->lastInsertId() ?: '0';
            $stmt->closeCursor();

            return new QueryResult(
                rows: $rows,
                rowCount: $rowCount,
                lastInsertId: $lastInsertId,
            );
        } finally {
            $this->pool->push($connection);
        }
    }

    /**
     * @deprecated Use QueryResult::$lastInsertId instead.
     */
    public function lastInsertId(): string
    {
        return '0';
    }

    private function detectVersion(): void
    {
        $result = $this->query('SELECT VERSION()');
        $raw = $result->fetchColumn();

        // Parse version string — MySQL returns e.g. "8.0.35" or "8.0.35-0ubuntu0.22.04.1"
        if (preg_match('/^(\d+\.\d+\.\d+)/', (string) $raw, $matches)) {
            $this->serverVersion = $matches[1];
        } else {
            throw new \RuntimeException("Unable to parse MySQL server version from: {$raw}");
        }

        if (version_compare($this->serverVersion, '8.0.0', '<')) {
            throw new \RuntimeException("Semitexa ORM requires MySQL 8.0+, got {$this->serverVersion}.");
        }
    }

    private function preparedStatement(\PDO $connection, string $sql): \PDOStatement
    {
        // When the pool hands out a FRESH un-pooled PDO per pop() (its
        // non-coroutine fallback) and push() drops it, the connection lives on
        // refcount alone. Caching its statement would create a
        // WeakMap→PDOStatement→PDO cycle that only cycle-GC can reclaim, so
        // query-dense CLI/phpunit stretches hold every socket open until the
        // next sweep (observed: MySQL max_connections exhausted mid-suite).
        // Cache only connections that will actually come back.
        if ($this->pool instanceof ConnectionPool && $this->pool->handsOutEphemeralConnections()) {
            $stmt = $connection->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException('PDO::prepare returned false for: ' . $sql);
            }

            return $stmt;
        }

        /** @var array<string, \PDOStatement> $cache */
        $cache = $this->statements[$connection] ?? [];
        $stmt = $cache[$sql] ?? null;
        if ($stmt instanceof \PDOStatement) {
            return $stmt;
        }

        $stmt = $this->prepareIntoCache($connection, $sql, $cache);
        $this->statements[$connection] = $cache;

        return $stmt;
    }

    private function forgetStatement(\PDO $connection, string $sql): void
    {
        /** @var array<string, \PDOStatement> $cache */
        $cache = $this->statements[$connection] ?? [];
        unset($cache[$sql]);
        $this->statements[$connection] = $cache;
    }
}
