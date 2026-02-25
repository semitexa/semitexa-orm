<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

class UpdateQuery
{
    use WhereTrait;

    /** @var array<string, mixed> */
    private array $wheres = [];

    /** @var array<string, mixed> */
    private array $params = [];

    private int $paramCounter = 0;

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

    /**
     * Update rows matching the WHERE conditions built via where*() methods.
     *
     * @param array<string, mixed> $data Column name → new value
     * @throws \LogicException When called without any WHERE condition (safety guard)
     * @throws \InvalidArgumentException When $data is empty
     */
    public function executeWhere(array $data): void
    {
        if ($this->wheres === []) {
            throw new \LogicException(
                'executeWhere() requires at least one WHERE condition. ' .
                'Use where(), whereIn(), whereNull() etc. before calling executeWhere().'
            );
        }

        if ($data === []) {
            throw new \InvalidArgumentException('executeWhere() requires at least one column to update.');
        }

        $setClauses = [];
        foreach ($data as $col => $value) {
            $paramName = $this->nextParam($col);
            $setClauses[] = "`{$col}` = :{$paramName}";
            $this->params[$paramName] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        $setString = implode(', ', $setClauses);
        $sql = "UPDATE `{$this->table}` SET {$setString}" . $this->buildWhereClause();

        $this->adapter->execute($sql, $this->params);
    }
}
