<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Transaction;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
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
            if (($e->errorInfo[1] ?? null) !== 1615) {
                throw $e;
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
        $stmt = $this->connection->query($sql);
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
