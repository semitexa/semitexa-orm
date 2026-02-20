<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

class MysqlAdapter implements DatabaseAdapterInterface
{
    private string $serverVersion = '';

    /** @var array<string, string> Minimum version required per capability */
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
        private readonly ConnectionPool $pool,
    ) {
        $this->detectVersion();
    }

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

    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $connection = $this->pool->pop();

        try {
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } finally {
            $this->pool->push($connection);
        }
    }

    public function query(string $sql): \PDOStatement
    {
        $connection = $this->pool->pop();

        try {
            return $connection->query($sql);
        } finally {
            $this->pool->push($connection);
        }
    }

    public function lastInsertId(): string
    {
        $connection = $this->pool->pop();

        try {
            return $connection->lastInsertId();
        } finally {
            $this->pool->push($connection);
        }
    }

    private function detectVersion(): void
    {
        $stmt = $this->query('SELECT VERSION()');
        $raw = $stmt->fetchColumn();

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
