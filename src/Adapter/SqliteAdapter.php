<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * SQLite database adapter.
 *
 * Handles connection management, query execution, and capability
 * detection for SQLite databases.
 *
 * Key differences from MySQL:
 * - No connection pooling needed (single file, no network)
 * - Foreign keys must be explicitly enabled per connection
 * - SQLite version detection via sqlite_version()
 * - Simpler execution model (no Swoole coroutine concerns)
 */
class SqliteAdapter implements DatabaseAdapterInterface
{
    private string $serverVersion = '';
    private ?\PDO $connection = null;

    /**
     * @param string $dsn SQLite DSN (e.g. "sqlite:/path/to/db.sqlite" or "sqlite::memory:")
     * @param array<string, mixed> $options PDO options
     */
    public function __construct(
        private readonly string $dsn,
        private readonly array $options = [],
    ) {
    }

    public function supports(ServerCapability $capability): bool
    {
        return $capability->isSupportedBySqlite();
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
        $pdo = $this->getConnection();

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        $lastInsertId = $pdo->lastInsertId() ?: '0';
        $stmt->closeCursor();

        return new QueryResult(
            rows: $rows,
            rowCount: $rowCount,
            lastInsertId: $lastInsertId,
        );
    }

    public function query(string $sql): QueryResult
    {
        $pdo = $this->getConnection();

        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new \RuntimeException("Query failed: {$sql}");
        }
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $rowCount = $stmt->rowCount();
        $lastInsertId = $pdo->lastInsertId() ?: '0';
        $stmt->closeCursor();

        return new QueryResult(
            rows: $rows,
            rowCount: $rowCount,
            lastInsertId: $lastInsertId,
        );
    }

    /**
     * @deprecated Use QueryResult::$lastInsertId instead.
     */
    public function lastInsertId(): string
    {
        return '0';
    }

    /**
     * Get the underlying PDO connection (useful for transactions).
     */
    public function getPdo(): \PDO
    {
        return $this->getConnection();
    }

    private function getConnection(): \PDO
    {
        if ($this->connection === null) {
            $this->connection = $this->createConnection();
        }

        return $this->connection;
    }

    private function createConnection(): \PDO
    {
        $defaultOptions = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new \PDO(
            $this->dsn,
            null,
            null,
            array_merge($defaultOptions, $this->options),
        );

        // Enable foreign key constraints (disabled by default in SQLite)
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Enable WAL mode for better concurrent read/write performance
        $pdo->exec('PRAGMA journal_mode = WAL');

        return $pdo;
    }

    private function detectVersion(): void
    {
        $result = $this->query('SELECT sqlite_version()');
        $raw = $result->rows[0]['sqlite_version()'] ?? null;
        $rawString = is_scalar($raw) ? (string) $raw : '';

        if (preg_match('/^(\d+\.\d+\.\d+)/', $rawString, $matches)) {
            $this->serverVersion = $matches[1];
        } else {
            throw new \RuntimeException("Unable to parse SQLite server version from: {$rawString}");
        }

        if (version_compare($this->serverVersion, '3.38.0', '<')) {
            throw new \RuntimeException("Semitexa ORM requires SQLite 3.38.0+, got {$this->serverVersion}.");
        }
    }
}
