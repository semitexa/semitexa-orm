<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

class DeleteQuery implements WhereCapableInterface
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
     * Delete the rows whose $column equals $value.
     *
     * Delegates to the WHERE builder rather than interpolating the column
     * itself, so this path gets the same identifier escaping as where() —
     * previously only executeWhere() was protected. Conditions already staged
     * via where*() still apply and narrow the delete further.
     */
    public function execute(string $column, mixed $value): void
    {
        $this->where($column, '=', $value)->executeWhere();
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

        $sql = 'DELETE FROM ' . $this->quotedTable() . $this->buildWhereClause();
        $this->adapter->execute($sql, $this->params);
    }

    private function quotedTable(): string
    {
        return '`' . str_replace('`', '``', $this->table) . '`';
    }
}
