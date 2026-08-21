<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Transaction;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\DriverErrorClassifier;
use Semitexa\Orm\Adapter\QueryRecorder;
use Semitexa\Orm\Adapter\SlowQueryLog;
use Semitexa\Orm\Adapter\PreparesCachedStatements;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;

/**
 * Adapter that wraps a single PDO connection (not a pool).
 * Used inside TransactionManager to ensure all operations run on the same connection.
 */
class SingleConnectionAdapter implements DatabaseAdapterInterface
{
    use PreparesCachedStatements;

    /**
     * Per-SQL prepared-statement cache. This adapter lives for one
     * transaction on one connection, and aggregate writes repeat the same
     * templated statements (cascade children, pivot chunks) — native
     * prepares (ATTR_EMULATE_PREPARES=false) make each prepare() a server
     * round-trip worth skipping.
     *
     * @var array<string, \PDOStatement>
     */
    private array $statements = [];

    public function __construct(
        private readonly \PDO $connection,
        private readonly string $serverVersion,
    ) {}

    public function supports(ServerCapability $capability): bool
    {
        $minVersion = ServerCapability::minimumVersions()[$capability->value] ?? null;
        if ($minVersion === null) {
            return false;
        }

        return version_compare($this->serverVersion, $minVersion, '>=');
    }

    public function getServerVersion(): string
    {
        return $this->serverVersion;
    }

    public function execute(string $sql, array $params = []): QueryResult
    {
        // Same recording seam as MysqlAdapter: without it, every query run
        // INSIDE a transaction was invisible to traces/profiles — exactly the
        // path you want to see when debugging a slow or deadlocking write.
        if (!QueryRecorder::isRecording() && SlowQueryLog::thresholdMs() <= 0) {
            return $this->executeUnrecorded($sql, $params);
        }

        $start = hrtime(true);
        try {
            return $this->executeUnrecorded($sql, $params);
        } finally {
            $milliseconds = (hrtime(true) - $start) / 1_000_000;
            if (QueryRecorder::isRecording()) {
                QueryRecorder::record($sql, $params, $milliseconds);
            }
            SlowQueryLog::maybeLog($sql, $milliseconds);
        }
    }

    /**
     * @param array<mixed> $params
     */
    private function executeUnrecorded(string $sql, array $params = []): QueryResult
    {
        $stmt = $this->statements[$sql] ?? null;
        if ($stmt === null) {
            $stmt = $this->preparedStatement($sql);
        }
        try {
            $stmt->execute($params);
        } catch (\PDOException $e) {
            // Defensive re-prepare, gated to MySQL 1615 ("statement needs to
            // be re-prepared" — DDL invalidated the cached statement). 1615
            // fails BEFORE any row is touched and does NOT roll back the open
            // transaction, so a re-prepared retry is safe here. NEVER retry
            // other errors: a deadlock (1213) has already rolled the tx back,
            // and a blind re-execute would silently apply a partial write.
            // Recognized driver errors surface as typed exceptions so the
            // caller (TransactionManager::runWithRetry) can replay the WHOLE
            // transaction — the only correct retry unit in here.
            if (($e->errorInfo[1] ?? null) !== 1615) {
                throw DriverErrorClassifier::classify($e) ?? $e;
            }
            unset($this->statements[$sql]);
            $stmt = $this->preparedStatement($sql);
            $stmt->execute($params);
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        $lastInsertId = $this->connection->lastInsertId();
        $stmt->closeCursor();

        return new QueryResult(
            rows: $rows,
            rowCount: $rowCount,
            lastInsertId: $lastInsertId,
        );
    }

    public function query(string $sql): QueryResult
    {
        if (!QueryRecorder::isRecording() && SlowQueryLog::thresholdMs() <= 0) {
            return $this->queryUnrecorded($sql);
        }

        $start = hrtime(true);
        try {
            return $this->queryUnrecorded($sql);
        } finally {
            $milliseconds = (hrtime(true) - $start) / 1_000_000;
            if (QueryRecorder::isRecording()) {
                QueryRecorder::record($sql, [], $milliseconds);
            }
            SlowQueryLog::maybeLog($sql, $milliseconds);
        }
    }

    private function queryUnrecorded(string $sql): QueryResult
    {
        try {
            $stmt = $this->connection->query($sql);
        } catch (\PDOException $e) {
            throw DriverErrorClassifier::classify($e) ?? $e;
        }
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        $stmt->closeCursor();

        return new QueryResult(
            rows: $rows,
            rowCount: $rowCount,
            lastInsertId: $this->connection->lastInsertId(),
        );
    }

    /**
     * @deprecated Use QueryResult::$lastInsertId instead.
     */
    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    public function getConnection(): \PDO
    {
        return $this->connection;
    }

    private function preparedStatement(string $sql): \PDOStatement
    {
        return $this->prepareIntoCache($this->connection, $sql, $this->statements);
    }
}
