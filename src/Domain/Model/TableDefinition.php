<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Model;

use Semitexa\Orm\Domain\Enum\ForeignKeyAction;
use Semitexa\Orm\Exception\InvalidIndexDeclarationException;


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
        // Dedupe by effective DDL name: an explicit #[Index] and a #[Filterable]
        // auto-index on the same columns would otherwise emit two CREATE INDEX
        // statements with the same generated name (fatal on SQLite table create).
        $effectiveName = $this->effectiveIndexName($index);

        foreach ($this->indexes as $existing) {
            if ($this->effectiveIndexName($existing) !== $effectiveName) {
                continue;
            }

            // A true duplicate (same columns AND same uniqueness) is dropped.
            // A name collision between differently-defined indexes is NOT a
            // duplicate — silently discarding it would drop a uniqueness
            // constraint or a distinct index and emit an incorrect schema.
            // Fail loudly so the schema author resolves the naming conflict.
            if ($existing->columns === $index->columns && $existing->unique === $index->unique) {
                return;
            }

            throw new InvalidIndexDeclarationException(sprintf(
                'Index name collision on table "%s": "%s" is already defined with different '
                . 'columns or uniqueness. Rename one of the indexes to resolve the conflict.',
                $this->name,
                $effectiveName,
            ));
        }

        $this->indexes[] = $index;
    }

    private function effectiveIndexName(IndexDefinition $index): string
    {
        return $index->name
            ?? ($index->unique ? 'uniq' : 'idx') . '_' . $this->name . '_' . implode('_', $index->columns);
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
