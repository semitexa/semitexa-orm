<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteType;
use Semitexa\Orm\Schema\ColumnDefinition;
use Semitexa\Orm\Schema\ForeignKeyAction;
use Semitexa\Orm\Schema\ForeignKeyDefinition;
use Semitexa\Orm\Schema\SqliteSchemaComparator;
use Semitexa\Orm\Schema\TableDefinition;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;

require_once __DIR__ . '/../../Fixture/Hydration/FakeDatabaseAdapter.php';

final class SqliteSchemaComparatorTest extends TestCase
{
    #[Test]
    public function skips_separate_add_fk_diffs_for_new_tables(): void
    {
        $adapter = new FakeDatabaseAdapter([
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name" => [],
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'" => [],
        ]);

        $posts = new TableDefinition('posts');
        $posts->addColumn(new ColumnDefinition(
            name: 'id',
            type: SqliteType::Varchar,
            phpType: 'string',
            isPrimaryKey: true,
            pkStrategy: 'uuid',
        ));
        $posts->addColumn(new ColumnDefinition(
            name: 'author_id',
            type: SqliteType::Varchar,
            phpType: 'string',
        ));
        $posts->addForeignKey(new ForeignKeyDefinition(
            table: 'posts',
            column: 'author_id',
            referencedTable: 'authors',
            referencedColumn: 'id',
            onDelete: ForeignKeyAction::Cascade,
            onUpdate: ForeignKeyAction::Cascade,
        ));

        $diff = (new SqliteSchemaComparator($adapter))->compare([
            'posts' => $posts,
        ]);

        $this->assertCount(1, $diff->getCreateTables());
        $this->assertSame([], $diff->getAddForeignKeys());
    }

    #[Test]
    public function drops_composite_indexes_that_only_happen_to_include_fk_columns(): void
    {
        $adapter = new FakeDatabaseAdapter([
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name" => [
                ['name' => 'posts'],
            ],
            'PRAGMA table_info("posts")' => [
                ['name' => 'id', 'type' => 'TEXT', 'notnull' => 1, 'dflt_value' => null, 'pk' => 1],
                ['name' => 'author_id', 'type' => 'TEXT', 'notnull' => 1, 'dflt_value' => null, 'pk' => 0],
                ['name' => 'slug', 'type' => 'TEXT', 'notnull' => 1, 'dflt_value' => null, 'pk' => 0],
            ],
            'PRAGMA index_list("posts")' => [
                ['name' => 'idx_posts_author_id_slug', 'unique' => 0],
            ],
            'PRAGMA index_info("idx_posts_author_id_slug")' => [
                ['name' => 'author_id'],
                ['name' => 'slug'],
            ],
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'" => [
                ['name' => 'posts'],
            ],
            'PRAGMA foreign_key_list("posts")' => [
                [
                    'from' => 'author_id',
                    'table' => 'authors',
                    'to' => 'id',
                    'on_delete' => 'CASCADE',
                    'on_update' => 'CASCADE',
                ],
            ],
        ]);

        $posts = new TableDefinition('posts');
        $posts->addColumn(new ColumnDefinition(
            name: 'id',
            type: SqliteType::Varchar,
            phpType: 'string',
            isPrimaryKey: true,
            pkStrategy: 'uuid',
        ));
        $posts->addColumn(new ColumnDefinition(
            name: 'author_id',
            type: SqliteType::Varchar,
            phpType: 'string',
        ));
        $posts->addColumn(new ColumnDefinition(
            name: 'slug',
            type: SqliteType::Varchar,
            phpType: 'string',
        ));
        $posts->addForeignKey(new ForeignKeyDefinition(
            table: 'posts',
            column: 'author_id',
            referencedTable: 'authors',
            referencedColumn: 'id',
            onDelete: ForeignKeyAction::Cascade,
            onUpdate: ForeignKeyAction::Cascade,
        ));

        $diff = (new SqliteSchemaComparator($adapter))->compare([
            'posts' => $posts,
        ]);

        $this->assertSame(['posts' => ['idx_posts_author_id_slug']], $diff->getDropIndexes());
    }

    #[Test]
    public function treats_all_positive_pk_ordinals_as_primary_key_membership(): void
    {
        $adapter = new FakeDatabaseAdapter([
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name" => [
                ['name' => 'memberships'],
            ],
            'PRAGMA table_info("memberships")' => [
                ['name' => 'tenant_id', 'type' => 'TEXT', 'notnull' => 0, 'dflt_value' => null, 'pk' => 1],
                ['name' => 'user_id', 'type' => 'TEXT', 'notnull' => 0, 'dflt_value' => null, 'pk' => 2],
            ],
            'PRAGMA index_list("memberships")' => [],
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'" => [
                ['name' => 'memberships'],
            ],
            'PRAGMA foreign_key_list("memberships")' => [],
        ]);

        $table = new TableDefinition('memberships');
        $table->addColumn(new ColumnDefinition(
            name: 'tenant_id',
            type: SqliteType::Varchar,
            phpType: 'string',
            isPrimaryKey: true,
            pkStrategy: 'uuid',
        ));
        $table->addColumn(new ColumnDefinition(
            name: 'user_id',
            type: SqliteType::Varchar,
            phpType: 'string',
            isPrimaryKey: true,
            pkStrategy: 'uuid',
        ));

        $diff = (new SqliteSchemaComparator($adapter))->compare([
            'memberships' => $table,
        ]);

        $this->assertSame([], $diff->getAlterColumns());
    }
}
