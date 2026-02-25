<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

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
     * @param bool $upsert When true, appends ON DUPLICATE KEY UPDATE for all columns in $data
     * @return string Last insert ID
     */
    public function execute(array $data, bool $upsert = false): string
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn(string $col) => ":{$col}", $columns);

        $colList = implode(', ', array_map(fn(string $c) => "`{$c}`", $columns));
        $phList = implode(', ', $placeholders);

        $sql = "INSERT INTO `{$this->table}` ({$colList}) VALUES ({$phList})";

        if ($upsert) {
            $updateParts = array_map(fn(string $c) => "`{$c}` = VALUES(`{$c}`)", $columns);
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateParts);
        }

        $result = $this->adapter->execute($sql, $data);

        return $result->lastInsertId;
    }

    /**
     * Insert multiple rows in a single query.
     *
     * All rows must have the same set of columns (determined by the first row).
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
        $colList = implode(', ', array_map(fn(string $c) => "`{$c}`", $columns));

        $valueSets = [];
        $params = [];

        foreach ($rows as $i => $row) {
            $phList = implode(', ', array_map(fn(string $col) => ":{$col}_{$i}", $columns));
            $valueSets[] = "({$phList})";
            foreach ($columns as $col) {
                $params["{$col}_{$i}"] = $row[$col];
            }
        }

        $sql = "INSERT INTO `{$this->table}` ({$colList}) VALUES " . implode(', ', $valueSets);

        $result = $this->adapter->execute($sql, $params);

        return $result->lastInsertId;
    }
}
