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
 * one the code describes and nothing else. Foreign keys are not checked
 * while the tables go, so the order does not matter.
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
     * Drops every table. Foreign keys are switched off first, but a pooled
     * adapter may run that statement on one connection and the drops on
     * others, as a Swoole-hooked pool outside a coroutine does. So the drops
     * go in passes: a table another one still references fails, and is
     * tried again after the rest. A pass that drops nothing ends it with the
     * last error, so no table is silently left.
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
}
