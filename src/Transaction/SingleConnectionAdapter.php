<?php

declare(strict_types=1);

namespace Semitexa\Orm\Transaction;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\ServerCapability;

/**
 * Adapter that wraps a single PDO connection (not a pool).
 * Used inside TransactionManager to ensure all operations run on the same connection.
 */
class SingleConnectionAdapter implements DatabaseAdapterInterface
{
    private string $lastInsertIdValue = '0';

    public function __construct(
        private readonly \PDO $connection,
        private readonly string $serverVersion,
    ) {}

    public function supports(ServerCapability $capability): bool
    {
        // Delegate to version check — same logic as MysqlAdapter
        $minVersions = [
            'atomic_ddl'    => '8.0.0',
            'check'         => '8.0.16',
            'default_expr'  => '8.0.13',
            'invisible_col' => '8.0.23',
            'json_table'    => '8.0.4',
            'window_func'   => '8.0.0',
            'desc_index'    => '8.0.0',
        ];

        $minVersion = $minVersions[$capability->value] ?? null;
        if ($minVersion === null) {
            return false;
        }

        return version_compare($this->serverVersion, $minVersion, '>=');
    }

    public function getServerVersion(): string
    {
        return $this->serverVersion;
    }

    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        $this->lastInsertIdValue = $this->connection->lastInsertId();
        return $stmt;
    }

    public function query(string $sql): \PDOStatement
    {
        return $this->connection->query($sql);
    }

    public function lastInsertId(): string
    {
        return $this->lastInsertIdValue;
    }

    public function getConnection(): \PDO
    {
        return $this->connection;
    }
}
