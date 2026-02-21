<?php

declare(strict_types=1);

namespace Semitexa\Orm;

use Semitexa\Core\Environment;
use Semitexa\Core\Util\ProjectRoot;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Schema\SchemaCollector;
use Semitexa\Orm\Schema\SchemaComparator;
use Semitexa\Orm\Sync\AuditLogger;
use Semitexa\Orm\Sync\SyncEngine;
use Semitexa\Orm\Transaction\TransactionManager;

class OrmManager
{
    private ?ConnectionPool $pool = null;
    private ?DatabaseAdapterInterface $adapter = null;
    private ?SchemaCollector $schemaCollector = null;
    private ?SchemaComparator $schemaComparator = null;
    private ?SyncEngine $syncEngine = null;
    private ?TransactionManager $transactionManager = null;

    public function getAdapter(): DatabaseAdapterInterface
    {
        if ($this->adapter === null) {
            $this->adapter = new MysqlAdapter($this->getPool());
        }

        return $this->adapter;
    }

    public function getPool(): ConnectionPool
    {
        if ($this->pool === null) {
            $this->pool = $this->createPool();
        }

        return $this->pool;
    }

    public function getSchemaCollector(): SchemaCollector
    {
        if ($this->schemaCollector === null) {
            $this->schemaCollector = new SchemaCollector();
        }

        return $this->schemaCollector;
    }

    public function getSchemaComparator(): SchemaComparator
    {
        if ($this->schemaComparator === null) {
            $this->schemaComparator = new SchemaComparator(
                $this->getAdapter(),
                $this->getDatabaseName(),
            );
        }

        return $this->schemaComparator;
    }

    public function getSyncEngine(): SyncEngine
    {
        if ($this->syncEngine === null) {
            $historyDir = ProjectRoot::get() . '/var/migrations/history';
            $this->syncEngine = new SyncEngine(
                $this->getAdapter(),
                new AuditLogger($historyDir),
            );
        }

        return $this->syncEngine;
    }

    public function getTransactionManager(): TransactionManager
    {
        if ($this->transactionManager === null) {
            $this->transactionManager = new TransactionManager(
                $this->getPool(),
                $this->getAdapter(),
            );
        }

        return $this->transactionManager;
    }

    public function getDatabaseName(): string
    {
        return Environment::getEnvValue('DB_DATABASE', 'semitexa');
    }

    public function shutdown(): void
    {
        $this->pool?->close();
        $this->pool = null;
        $this->adapter = null;
    }

    private function createPool(): ConnectionPool
    {
        $host = Environment::getEnvValue('DB_HOST', '127.0.0.1');
        $port = Environment::getEnvValue('DB_PORT', '3306');
        $database = Environment::getEnvValue('DB_DATABASE', 'semitexa');
        $username = Environment::getEnvValue('DB_USERNAME', 'root');
        $password = Environment::getEnvValue('DB_PASSWORD', '');
        $charset = Environment::getEnvValue('DB_CHARSET', 'utf8mb4');
        $poolSize = (int) Environment::getEnvValue('DB_POOL_SIZE', '10');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        $factory = static function () use ($dsn, $username, $password): \PDO {
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            return $pdo;
        };

        return new ConnectionPool($poolSize, $factory);
    }
}
