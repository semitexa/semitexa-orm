<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

class DbTableState
{
    /** @var DbColumnState[] */
    private array $columns = [];

    /** @var DbIndexState[] */
    private array $indexes = [];

    public function __construct(
        public readonly string $name,
        public readonly string $tableComment = '',
    ) {}

    public function addColumn(DbColumnState $column): void
    {
        $this->columns[] = $column;
    }

    public function addIndex(DbIndexState $index): void
    {
        $this->indexes[] = $index;
    }

    /**
     * @return array<string, DbColumnState>
     */
    public function getColumnMap(): array
    {
        $map = [];
        foreach ($this->columns as $col) {
            $map[$col->name] = $col;
        }
        return $map;
    }

    /**
     * @return DbIndexState[]
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }
}
