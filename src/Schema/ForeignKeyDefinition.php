<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

final readonly class ForeignKeyDefinition
{
    public function __construct(
        /** Table that owns the FK column */
        public string $table,
        /** FK column name in the owning table */
        public string $column,
        /** Referenced table */
        public string $referencedTable,
        /** Referenced column (usually the PK) */
        public string $referencedColumn,
        public ForeignKeyAction $onDelete,
        public ForeignKeyAction $onUpdate,
    ) {}

    /** Constraint name: fk_{table}_{column} */
    public function constraintName(): string
    {
        return "fk_{$this->table}_{$this->column}";
    }
}
