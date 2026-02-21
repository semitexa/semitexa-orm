<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

class UpdateQuery
{
    public function __construct(
        private readonly string $table,
        private readonly DatabaseAdapterInterface $adapter,
    ) {}

    /**
     * Update rows matching primary key.
     *
     * @param array<string, mixed> $data Column name → value (must include PK)
     * @param string $pkColumn Primary key column name
     */
    public function execute(array $data, string $pkColumn): void
    {
        $pkValue = $data[$pkColumn] ?? null;
        if ($pkValue === null) {
            throw new \InvalidArgumentException("Primary key column '{$pkColumn}' not found in data.");
        }

        $setClauses = [];
        $params = [];
        foreach ($data as $col => $value) {
            if ($col === $pkColumn) {
                continue;
            }
            $setClauses[] = "`{$col}` = :set_{$col}";
            $params["set_{$col}"] = $value;
        }

        if ($setClauses === []) {
            return;
        }

        $params['pk_value'] = $pkValue;
        $setString = implode(', ', $setClauses);

        $sql = "UPDATE `{$this->table}` SET {$setString} WHERE `{$pkColumn}` = :pk_value";
        $this->adapter->execute($sql, $params);
    }
}
