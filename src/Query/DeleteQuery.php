<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

class DeleteQuery
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
     * Delete a row by primary key value.
     */
    public function execute(string $pkColumn, mixed $pkValue): void
    {
        $sql = "DELETE FROM `{$this->table}` WHERE `{$pkColumn}` = :pk_value";
        $this->adapter->execute($sql, ['pk_value' => $pkValue]);
    }

    /**
     * Delete rows matching the WHERE conditions built via where*() methods.
     *
     * @throws \LogicException When called without any WHERE condition (safety guard)
     */
    public function executeWhere(): void
    {
        if ($this->wheres === []) {
            throw new \LogicException(
                'executeWhere() requires at least one WHERE condition. ' .
                'Use where(), whereIn(), whereNull() etc. before calling executeWhere().'
            );
        }

        $sql = "DELETE FROM `{$this->table}`" . $this->buildWhereClause();
        $this->adapter->execute($sql, $this->params);
    }
}
