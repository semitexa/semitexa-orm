<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Sync\Fixture;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;

/**
 * MySQL behind a pool that gives every statement a new session: the foreign-key
 * switch reaches nothing, so a table something still references refuses its drop.
 */
final class SessionlessMysqlAdapter implements DatabaseAdapterInterface
{
    /** @var list<string> */
    public array $alters = [];

    /**
     * @param list<string> $tables
     * @param list<array{string, string, string}> $keys table, key name, referenced table
     */
    public function __construct(public array $tables, private array $keys, private readonly bool $refusesAlter = false)
    {
    }

    public function execute(string $sql, array $params = []): QueryResult
    {
        if (str_contains($sql, 'INFORMATION_SCHEMA.TABLES')) {
            return new QueryResult(array_map(static fn (string $table): array => ['t' => $table], $this->tables));
        }
        if ($sql === 'SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS k FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = :db'
            && $params === ['db' => 'app']
        ) {
            return new QueryResult(array_map(static fn (array $key): array => ['t' => $key[0], 'k' => $key[1]], $this->keys));
        }
        if (preg_match('/^ALTER TABLE `(\w+)` DROP FOREIGN KEY `(\w+)`$/', $sql, $alter) === 1) {
            if ($this->refusesAlter) {
                throw new \RuntimeException('ALTER command denied to user');
            }
            $this->alters[] = $sql;
            $this->keys = array_values(array_filter($this->keys, static fn (array $key): bool => $key[1] !== $alter[2]));
        }
        if (preg_match('/^DROP TABLE IF EXISTS `(\w+)`$/', $sql, $drop) === 1) {
            foreach ($this->keys as $key) {
                if ($key[2] === $drop[1]) {
                    throw new \RuntimeException('Cannot drop table referenced by a foreign key constraint');
                }
            }
            $this->tables = array_values(array_diff($this->tables, [$drop[1]]));
            $this->keys = array_values(array_filter($this->keys, static fn (array $key): bool => $key[0] !== $drop[1]));
        }

        return new QueryResult();
    }

    public function supports(ServerCapability $capability): bool
    {
        return false;
    }

    public function getServerVersion(): string
    {
        return '8.0';
    }

    public function query(string $sql): QueryResult
    {
        return $this->execute($sql);
    }

    public function lastInsertId(): string
    {
        return '0';
    }
}
