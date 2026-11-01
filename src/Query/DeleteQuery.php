<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Adapter\SqlIdentifier;

class DeleteQuery implements WhereCapableInterface
{
    use WhereTrait;

    /** @var list<array<string, mixed>> staged WHERE conditions, in order */
    private array $wheres = [];

    /** @var array<string, mixed> */
    private array $params = [];

    private int $paramCounter = 0;

    private ?int $limit = null;

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
     *
     * Runs on a clone so the equality condition is not retained: a builder may
     * be reused (InsertQuery is, one instance across chunks), and a mutating
     * execute() would AND every previous value into the next call.
     */
    public function execute(string $column, mixed $value): int
    {
        $query = clone $this;
        $query->groupStagedConditions();

        return $query->where($column, '=', $value)->executeWhere();
    }

    /**
     * At most $rows rows per executeWhere(): a retention job purges in
     * batches, each a short transaction, instead of one that locks the table
     * for the whole backlog. Which rows of the match go first is the
     * database's choice; repeat until executeWhere() returns less than $rows.
     *
     * On SQLite the rows go through rowid, so a WITHOUT ROWID table fails
     * loudly ("no such column: rowid"); the ORM's sync never creates one. The
     * limit stays on this builder, as its conditions do.
     */
    public function limit(int $rows): static
    {
        if ($rows < 1) {
            throw new \InvalidArgumentException('limit() takes at least one row.');
        }
        $this->limit = $rows;

        return $this;
    }

    /**
     * Delete rows matching the WHERE conditions built via where*() methods.
     *
     * @return int the rows deleted, as the database reports them
     * @throws \LogicException When called without any WHERE condition (safety guard)
     */
    public function executeWhere(): int
    {
        if ($this->wheres === []) {
            throw new \LogicException(
                'executeWhere() requires at least one WHERE condition. ' .
                'Use where(), whereIn(), whereNull() etc. before calling executeWhere().'
            );
        }

        $where = $this->buildWhereClause();
        $sql = match (true) {
            $this->limit === null => 'DELETE FROM ' . $this->quotedTable() . $where,
            $this->adapter->supports(ServerCapability::DeleteLimit) => 'DELETE FROM ' . $this->quotedTable() . $where . ' LIMIT ' . $this->limit,
            // SQLite without DELETE ... LIMIT: the same rows through rowid.
            default => sprintf('DELETE FROM %1$s WHERE rowid IN (SELECT rowid FROM %1$s%2$s LIMIT %3$d)', $this->quotedTable(), $where, $this->limit),
        };

        return $this->adapter->execute($sql, $this->params)->rowCount;
    }

    private function quotedTable(): string
    {
        return SqlIdentifier::quote($this->table);
    }
}
