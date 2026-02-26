<?php

declare(strict_types=1);

namespace Semitexa\Orm\Transaction;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;

/**
 * Adapter that wraps a single PDO connection (not a pool).
 * Used inside TransactionManager to ensure all operations run on the same connection.
 */
class SingleConnectionAdapter implements DatabaseAdapterInterface
{
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
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

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
}
