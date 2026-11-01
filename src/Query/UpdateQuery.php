<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\SqlIdentifier;

class UpdateQuery implements WhereCapableInterface
{
    use WhereTrait;

    /** @var list<array<string, mixed>> staged WHERE conditions, in order */
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
     * @return int the rows the database reports changed (MySQL: changed, not merely matched)
     */
    public function execute(array $data, string $pkColumn): int
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
            $paramName = $this->nextParam('set_' . $col);
            $setClauses[] = SqlIdentifier::quote($col) . " = :{$paramName}";
            $params[$paramName] = $value;
        }

        if ($setClauses === []) {
            return 0;
        }

        $params['pk_value'] = $pkValue;
        $setString = implode(', ', $setClauses);

        $sql = 'UPDATE ' . SqlIdentifier::quote($this->table) . " SET {$setString}"
            . ' WHERE ' . SqlIdentifier::quote($pkColumn) . ' = :pk_value';

        return $this->adapter->execute($sql, $params)->rowCount;
    }

    /**
     * Update rows matching the WHERE conditions built via where*() methods.
     *
     * @param array<string, mixed> $data Column name → new value
     * @throws \LogicException When called without any WHERE condition (safety guard)
     * @return int the rows the database reports changed (MySQL: changed, not merely matched)
     * @throws \InvalidArgumentException When $data is empty
     */
    public function executeWhere(array $data): int
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
            $setClauses[] = SqlIdentifier::quote($col) . " = :{$paramName}";
            $this->params[$paramName] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        $setString = implode(', ', $setClauses);
        $sql = 'UPDATE ' . SqlIdentifier::quote($this->table) . " SET {$setString}" . $this->buildWhereClause();

        return $this->adapter->execute($sql, $this->params)->rowCount;
    }
}
