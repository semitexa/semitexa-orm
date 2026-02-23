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
     * @return string Last insert ID
     */
    public function execute(array $data): string
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn(string $col) => ":{$col}", $columns);

        $colList = implode(', ', array_map(fn(string $c) => "`{$c}`", $columns));
        $phList = implode(', ', $placeholders);

        $sql = "INSERT INTO `{$this->table}` ({$colList}) VALUES ({$phList})";
        $result = $this->adapter->execute($sql, $data);

        return $result->lastInsertId;
    }
}
