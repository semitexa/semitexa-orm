<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

class MysqlAdapter implements DatabaseAdapterInterface
{
    private string $serverVersion = '';

    public function __construct(
        private readonly ConnectionPoolInterface $pool,
    ) {
        $this->detectVersion();
    }

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
        $connection = $this->pool->pop();

        try {
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);

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
}
