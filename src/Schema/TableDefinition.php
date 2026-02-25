<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

class TableDefinition
{
    /** @var array<string, ColumnDefinition> */
    private array $columns = [];

    /** @var IndexDefinition[] */
    private array $indexes = [];

    /** @var array<string, array{type: string, target: string, foreignKey: string, pivotTable?: string, relatedKey?: string}> */
    private array $relations = [];

    /** @var ForeignKeyDefinition[] */
    private array $foreignKeys = [];

    private ?ColumnDefinition $primaryKey = null;

    public function __construct(
        public readonly string $name,
    ) {}

    public function addColumn(ColumnDefinition $column): void
    {
        $this->columns[$column->name] = $column;

        if ($column->isPrimaryKey) {
            $this->primaryKey = $column;
        }
    }

    public function addIndex(IndexDefinition $index): void
    {
        $this->indexes[] = $index;
    }

    public function addForeignKey(ForeignKeyDefinition $fk): void
    {
        $this->foreignKeys[] = $fk;
    }

    public function addRelation(
        string $property,
        string $type,
        string $target,
        string $foreignKey,
        ?string $pivotTable = null,
        ?string $relatedKey = null,
        ?ForeignKeyAction $onDelete = null,
        ?ForeignKeyAction $onUpdate = null,
    ): void {
        $relation = [
            'type'       => $type,
            'target'     => $target,
            'foreignKey' => $foreignKey,
            'onDelete'   => $onDelete,
            'onUpdate'   => $onUpdate,
        ];

        if ($pivotTable !== null) {
            $relation['pivotTable'] = $pivotTable;
        }
        if ($relatedKey !== null) {
            $relation['relatedKey'] = $relatedKey;
        }

        $this->relations[$property] = $relation;
    }

    public function getColumn(string $name): ?ColumnDefinition
    {
        return $this->columns[$name] ?? null;
    }

    /** @return array<string, ColumnDefinition> */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** @return IndexDefinition[] */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /** @return array<string, array{type: string, target: string, foreignKey: string, pivotTable?: string, relatedKey?: string}> */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /** @return ForeignKeyDefinition[] */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function getPrimaryKey(): ?ColumnDefinition
    {
        return $this->primaryKey;
    }

    /** @return string[] */
    public function validate(): array
    {
        $errors = [];

        if ($this->primaryKey === null) {
            $errors[] = "Table '{$this->name}' has no primary key defined.";
        }

        $columnNames = array_keys($this->columns);
        if (count($columnNames) !== count(array_unique($columnNames))) {
            $errors[] = "Table '{$this->name}' has duplicate column names.";
        }

        foreach ($this->indexes as $index) {
            foreach ($index->columns as $col) {
                if (!isset($this->columns[$col])) {
                    $errors[] = "Table '{$this->name}': index references unknown column '{$col}'.";
                }
            }
        }

        return $errors;
    }
}
