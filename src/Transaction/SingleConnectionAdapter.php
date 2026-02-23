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
    /** @var array<string, string> */
    private const CAPABILITY_VERSIONS = [
        'atomic_ddl'    => '8.0.0',
        'check'         => '8.0.16',
        'default_expr'  => '8.0.13',
        'invisible_col' => '8.0.23',
        'json_table'    => '8.0.4',
        'window_func'   => '8.0.0',
        'desc_index'    => '8.0.0',
    ];

    public function __construct(
        private readonly \PDO $connection,
        private readonly string $serverVersion,
    ) {}

    public function supports(ServerCapability $capability): bool
    {
        $minVersion = self::CAPABILITY_VERSIONS[$capability->value] ?? null;
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
