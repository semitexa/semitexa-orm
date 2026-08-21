<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

use Semitexa\Orm\Exception\ConnectionLostException;

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
        // Two boolean checks when nothing observes, which is every production
        // process with slow-query logging off. The measurement wraps the call
        // rather than living inside it so the original body keeps its own
        // early returns.
        if (!QueryRecorder::isRecording() && SlowQueryLog::thresholdMs() <= 0) {
            return $this->executeRecorded($sql, $params);
        }

        $start = hrtime(true);
        try {
            return $this->executeRecorded($sql, $params);
        } finally {
            $milliseconds = (hrtime(true) - $start) / 1_000_000;
            if (QueryRecorder::isRecording()) {
                QueryRecorder::record($sql, $params, $milliseconds);
            }
            SlowQueryLog::maybeLog($sql, $milliseconds);
        }
    }

    public function query(string $sql): QueryResult
    {
        if (!QueryRecorder::isRecording() && SlowQueryLog::thresholdMs() <= 0) {
            return $this->queryRecorded($sql);
        }

        $start = hrtime(true);
        try {
            return $this->queryRecorded($sql);
        } finally {
            $milliseconds = (hrtime(true) - $start) / 1_000_000;
            if (QueryRecorder::isRecording()) {
                QueryRecorder::record($sql, [], $milliseconds);
            }
            SlowQueryLog::maybeLog($sql, $milliseconds);
        }
    }

    /**
     * @param array<mixed> $params
     */
    private function executeRecorded(string $sql, array $params = []): QueryResult
    {
        // Native prepares reject repeated named placeholders (HY093);
        // rewrite them deterministically before hitting the statement cache.
        [$sql, $params] = RepeatedPlaceholderExpander::expand($sql, $params);

        try {
            return $this->executeOnPooledConnection($sql, $params);
        } catch (ConnectionLostException $e) {
            // The dead connection was discarded (not re-queued). A READ-ONLY
            // statement is safe to replay once on a fresh connection: it
            // changes nothing, so it does not matter whether the server had
            // processed it before dropping. A write is NEVER auto-retried —
            // the drop can arrive after the server applied the write, and a
            // replay would duplicate it. This adapter is the POOLED path, so
            // no transaction can be open here (transactions run on
            // SingleConnectionAdapter), making the single retry safe.
            if (!DriverErrorClassifier::isReadOnlyStatement($sql)) {
                throw $e;
            }

            return $this->executeOnPooledConnection($sql, $params);
        }
    }

    /**
     * @param array<mixed> $params
     */
    private function executeOnPooledConnection(string $sql, array $params): QueryResult
    {
        $connection = $this->pool->pop();
        $discard = false;

        try {
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
            } catch (\PDOException $e) {
                // Classify AFTER the 1615 path above: recognized errors become
                // typed exceptions callers can branch on; anything else is
                // rethrown untouched. A dead connection must not go back to
                // the channel — the pool discards it and frees its slot.
                $classified = DriverErrorClassifier::classify($e);
                if ($classified instanceof ConnectionLostException) {
                    $discard = true;
                }
                throw $classified ?? $e;
            }
        } finally {
            if ($discard) {
                $this->discardFromPool($connection);
            } else {
                $this->pool->push($connection);
            }
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
    private function queryRecorded(string $sql): QueryResult
    {
        try {
            return $this->queryOnPooledConnection($sql);
        } catch (ConnectionLostException $e) {
            // Same policy as executeRecorded(): one replay on a fresh
            // connection, read-only statements only.
            if (!DriverErrorClassifier::isReadOnlyStatement($sql)) {
                throw $e;
            }

            return $this->queryOnPooledConnection($sql);
        }
    }

    private function queryOnPooledConnection(string $sql): QueryResult
    {
        $connection = $this->pool->pop();
        $discard = false;

        try {
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
            } catch (\PDOException $e) {
                $classified = DriverErrorClassifier::classify($e);
                if ($classified instanceof ConnectionLostException) {
                    $discard = true;
                }
                throw $classified ?? $e;
            }
        } finally {
            if ($discard) {
                $this->discardFromPool($connection);
            } else {
                $this->pool->push($connection);
            }
        }
    }

    /**
     * Drop a dead connection instead of re-queueing it. ConnectionPool frees
     * the slot so a replacement can be minted; other pools simply lose the
     * reference — SingleConnectionPool's next pop() health-checks its cached
     * connection and re-mints anyway.
     */
    private function discardFromPool(\PDO $connection): void
    {
        if ($this->pool instanceof ConnectionPool) {
            $this->pool->discard($connection);
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
        if ($this->pool instanceof EphemeralConnectionAwareInterface
            && $this->pool->isEphemeralConnection($connection)) {
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
