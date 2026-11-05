<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Sync;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\SqlIdentifier;

/**
 * Empties a database of its tables, so orm:fresh can sync the schema from
 * nothing (bench-no-fresh-schema-command: resetting a dev database took a
 * hand-written DROP per table, or deleting the volume). Every base table
 * goes, whether or not the code still declares it: a fresh schema is the
 * one the code describes and nothing else. See dropAll() for what happens
 * to foreign keys while the tables go.
 */
final class SchemaReset
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly string $driver,
        private readonly string $database,
    ) {}

    /**
     * The tables there are, sorted. SQLite's own tables are left out by GLOB:
     * LIKE's `_` matches any character, which spared a user table such as
     * sqliteXorders.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $rows = $this->driver === 'sqlite'
            ? $this->adapter->execute("SELECT name AS t FROM sqlite_master WHERE type = 'table' AND name NOT GLOB 'sqlite_*'")->rows
            : $this->adapter->execute("SELECT TABLE_NAME AS t FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'", ['db' => $this->database])->rows;
        $tables = [];
        foreach ($rows as $row) {
            $name = $row['t'] ?? $row['T'] ?? null;
            if (is_string($name) && $name !== '') {
                $tables[] = $name;
            }
        }
        sort($tables);

        return $tables;
    }

    /**
     * Drops every table. Foreign keys are switched off first, but that lasts
     * for one session, and a pooled adapter may run the drops on others, as a
     * Swoole-hooked pool outside a coroutine does. So MySQL's keys go before
     * the tables: two tables that reference each other have no order to drop
     * them in, and no switch would reach them. A key that will not go stays:
     * an account without ALTER or a table MySQL refuses to rebuild still has
     * the switch on one session. So the drops go in passes, and a pass that
     * drops nothing ends it with the last error: no table is silently left.
     *
     * @return list<string> the tables dropped
     */
    public function dropAll(): array
    {
        $tables = $this->tables();
        if ($tables === []) {
            return [];
        }
        $sqlite = $this->driver === 'sqlite';
        $this->adapter->execute($sqlite ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS = 0');
        try {
            if (!$sqlite) {
                $this->dropForeignKeys();
            }
            $left = $tables;
            while ($left !== []) {
                $failed = [];
                $error = null;
                foreach ($left as $table) {
                    try {
                        $this->adapter->execute('DROP TABLE IF EXISTS ' . SqlIdentifier::quote($table, $sqlite ? SqlIdentifier::DOUBLE_QUOTE : SqlIdentifier::BACKTICK));
                    } catch (\Throwable $e) {
                        $failed[] = $table;
                        $error = $e;
                    }
                }
                if ($failed !== [] && count($failed) === count($left)) {
                    throw new \RuntimeException('could not drop ' . implode(', ', $failed) . ': ' . $error?->getMessage(), 0, $error);
                }
                $left = $failed;
            }
        } finally {
            $this->adapter->execute($sqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
        }

        return $tables;
    }

    private function dropForeignKeys(): void
    {
        $keys = $this->adapter->execute(
            'SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS k FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = :db',
            ['db' => $this->database],
        )->rows;
        foreach ($keys as $key) {
            $table = $key['t'] ?? $key['T'] ?? null;
            $name = $key['k'] ?? $key['K'] ?? null;
            if (is_string($table) && is_string($name)) {
                try {
                    $this->adapter->execute(sprintf(
                        'ALTER TABLE %s DROP FOREIGN KEY %s',
                        SqlIdentifier::quote($table),
                        SqlIdentifier::quote($name),
                    ));
                } catch (\Throwable) {
                    continue;
                }
            }
        }
    }
}
