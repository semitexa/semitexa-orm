<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

class SchemaDiff
{
    /** @var TableDefinition[] Tables to create */
    private array $createTables = [];

    /** @var string[] Table names to drop */
    private array $dropTables = [];

    /** @var array<string, ColumnDefinition[]> Table name → columns to add */
    private array $addColumns = [];

    /** @var array<string, array{column: ColumnDefinition, changes: string[]}[]> Table name → altered columns */
    private array $alterColumns = [];

    /** @var array<string, array{name: string, comment: string}[]> Table name → columns to drop */
    private array $dropColumns = [];

    /** @var array<string, array{index: IndexDefinition, name: string}[]> Table name → indexes to add */
    private array $addIndexes = [];

    /** @var array<string, string[]> Table name → index names to drop */
    private array $dropIndexes = [];

    public function addCreateTable(TableDefinition $table): void
    {
        $this->createTables[] = $table;
    }

    public function addDropTable(string $tableName): void
    {
        $this->dropTables[] = $tableName;
    }

    public function addAddColumn(string $tableName, ColumnDefinition $column): void
    {
        $this->addColumns[$tableName][] = $column;
    }

    /**
     * @param string[] $changes
     */
    public function addAlterColumn(string $tableName, ColumnDefinition $column, array $changes): void
    {
        $this->alterColumns[$tableName][] = ['column' => $column, 'changes' => $changes];
    }

    public function addDropColumn(string $tableName, string $columnName, string $comment = ''): void
    {
        $this->dropColumns[$tableName][] = ['name' => $columnName, 'comment' => $comment];
    }

    public function addAddIndex(string $tableName, IndexDefinition $index, string $name): void
    {
        $this->addIndexes[$tableName][] = ['index' => $index, 'name' => $name];
    }

    public function addDropIndex(string $tableName, string $indexName): void
    {
        $this->dropIndexes[$tableName][] = $indexName;
    }

    /** @return TableDefinition[] */
    public function getCreateTables(): array
    {
        return $this->createTables;
    }

    /** @return string[] */
    public function getDropTables(): array
    {
        return $this->dropTables;
    }

    /** @return array<string, ColumnDefinition[]> */
    public function getAddColumns(): array
    {
        return $this->addColumns;
    }

    /** @return array<string, array{column: ColumnDefinition, changes: string[]}[]> */
    public function getAlterColumns(): array
    {
        return $this->alterColumns;
    }

    /** @return array<string, array{name: string, comment: string}[]> */
    public function getDropColumns(): array
    {
        return $this->dropColumns;
    }

    /** @return array<string, array{index: IndexDefinition, name: string}[]> */
    public function getAddIndexes(): array
    {
        return $this->addIndexes;
    }

    /** @return array<string, string[]> */
    public function getDropIndexes(): array
    {
        return $this->dropIndexes;
    }

    public function isEmpty(): bool
    {
        return $this->createTables === []
            && $this->dropTables === []
            && $this->addColumns === []
            && $this->alterColumns === []
            && $this->dropColumns === []
            && $this->addIndexes === []
            && $this->dropIndexes === [];
    }
}
