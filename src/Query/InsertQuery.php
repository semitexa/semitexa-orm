<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\SqliteAdapter;

class InsertQuery
{
    public function __construct(
        private readonly string $table,
        private readonly DatabaseAdapterInterface $adapter,
    ) {}

    /**
     * Insert a single row.
     *
     * @param array<string, mixed> $data Column name → value
     * @param bool $upsert When true, appends ON DUPLICATE KEY UPDATE (MySQL) or ON CONFLICT DO UPDATE (SQLite) for all columns in $data
     * @return string Last insert ID
     */
    public function execute(array $data, bool $upsert = false): string
    {
        if ($data === []) {
            throw new \InvalidArgumentException('execute() requires at least one column to insert.');
        }

        $columns = array_keys($data);
        $params = [];
        $placeholders = [];
        foreach (array_values($data) as $index => $value) {
            $name = 'v' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $value;
        }

        $colList = implode(', ', array_map($this->quoteIdentifier(...), $columns));
        $phList = implode(', ', $placeholders);

        $sql = 'INSERT INTO ' . $this->quoteIdentifier($this->table) . " ({$colList}) VALUES ({$phList})";

        if ($upsert) {
            $sql .= $this->buildUpsertClause($columns);
        }

        $result = $this->adapter->execute($sql, $params);

        return $result->lastInsertId;
    }

    /**
     * Insert multiple rows in a single query.
     *
     * All rows must have the same ordered set of columns (determined by the
     * first row). The full batch is validated before SQL is executed.
     * Returns the last insert ID of the first inserted row (MySQL behaviour).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return string Last insert ID of the first inserted row
     * @throws \InvalidArgumentException When $rows is empty
     */
    public function executeBatch(array $rows): string
    {
        if ($rows === []) {
            throw new \InvalidArgumentException('executeBatch() requires at least one row.');
        }

        $columns = array_keys($rows[0]);
        if ($columns === []) {
            throw new \InvalidArgumentException('executeBatch() rows must contain at least one column.');
        }
        foreach ($rows as $index => $row) {
            if (array_keys($row) !== $columns) {
                throw new \InvalidArgumentException(sprintf(
                    'executeBatch() row %d must have the same columns in the same order as row 0.',
                    $index,
                ));
            }
        }

        $colList = implode(', ', array_map($this->quoteIdentifier(...), $columns));

        $valueSets = [];
        $params = [];

        foreach ($rows as $i => $row) {
            $rowPlaceholders = [];
            foreach ($columns as $columnIndex => $column) {
                $name = sprintf('v%d_%d', $i, $columnIndex);
                $rowPlaceholders[] = ':' . $name;
                $params[$name] = $row[$column];
            }
            $phList = implode(', ', $rowPlaceholders);
            $valueSets[] = "({$phList})";
        }

        $sql = 'INSERT INTO ' . $this->quoteIdentifier($this->table) . " ({$colList}) VALUES " . implode(', ', $valueSets);

        $result = $this->adapter->execute($sql, $params);

        return $result->lastInsertId;
    }

    /**
     * Build the upsert clause appropriate for the current database.
     *
     * @param string[] $columns
     */
    private function buildUpsertClause(array $columns): string
    {
        if ($this->adapter instanceof SqliteAdapter) {
            // SQLite: ON CONFLICT DO UPDATE SET
            $updateParts = array_map(function (string $column): string {
                $quoted = $this->quoteIdentifier($column);
                return "{$quoted} = excluded.{$quoted}";
            }, $columns);
            return ' ON CONFLICT DO UPDATE SET ' . implode(', ', $updateParts);
        }

        // MySQL: ON DUPLICATE KEY UPDATE
        $updateParts = array_map(function (string $column): string {
            $quoted = $this->quoteIdentifier($column);
            return "{$quoted} = VALUES({$quoted})";
        }, $columns);
        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateParts);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '') {
            throw new \InvalidArgumentException('SQL identifiers must not be empty.');
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
