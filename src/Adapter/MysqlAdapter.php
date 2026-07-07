<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

class MysqlAdapter implements DatabaseAdapterInterface
{
    /**
     * Per-connection prepared-statement cap. ORM SQL is templated (a finite
     * set per worker), but whereRaw() can mint unbounded shapes — reset the
     * connection's cache rather than grow without bound.
     */
    private const STATEMENT_CACHE_MAX = 256;

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
                // Defensive re-prepare: a cached statement can be invalidated
                // server-side (e.g. MySQL 1615 after DDL touches the table).
                // Drop it, prepare fresh, retry ONCE; a second failure is real.
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
        $cache = $this->statements[$connection] ?? [];
        $stmt = $cache[$sql] ?? null;
        if ($stmt instanceof \PDOStatement) {
            return $stmt;
        }

        $stmt = $connection->prepare($sql);
        if (count($cache) >= self::STATEMENT_CACHE_MAX) {
            $cache = [];
        }
        $cache[$sql] = $stmt;
        $this->statements[$connection] = $cache;

        return $stmt;
    }

    private function forgetStatement(\PDO $connection, string $sql): void
    {
        $cache = $this->statements[$connection] ?? [];
        unset($cache[$sql]);
        $this->statements[$connection] = $cache;
    }
}
