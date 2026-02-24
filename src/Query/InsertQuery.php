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
}
