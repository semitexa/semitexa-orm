<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Application\Service\Sync\SchemaReset;
use Semitexa\Orm\Tests\Unit\Sync\Fixture\RefuseFirstDropAdapter;

/** orm:fresh empties the database of every table, foreign keys or not (bench-no-fresh-schema-command). */
final class SchemaResetTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/semitexa-reset-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    /** A drop refused on its first pass is tried again after the rest (a pooled FK switch may not reach it). */
    #[Test]
    public function a_drop_refused_once_is_retried_after_the_rest(): void
    {
        $adapter = new SqliteAdapter('sqlite:' . $this->path);
        $adapter->execute('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $adapter->execute('CREATE TABLE children (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parents(id))');
        $refuseOnce = new RefuseFirstDropAdapter($adapter);

        self::assertSame(['children', 'parents'], (new SchemaReset($refuseOnce, 'sqlite', $this->path))->dropAll());
        self::assertSame(['DROP TABLE IF EXISTS "children"', 'DROP TABLE IF EXISTS "parents"', 'DROP TABLE IF EXISTS "children"'], $refuseOnce->drops);
        self::assertSame([], (new SchemaReset($adapter, 'sqlite', $this->path))->tables());
    }

    #[Test]
    public function every_table_goes_whatever_points_at_what(): void
    {
        $adapter = new SqliteAdapter('sqlite:' . $this->path);
        $adapter->execute('PRAGMA foreign_keys = ON');
        $adapter->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY)');
        $adapter->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NOT NULL REFERENCES customers(id))');
        $adapter->execute('CREATE TABLE "odd ""name""" (id INTEGER)');
        // LIKE 'sqlite_%' would have spared it: `_` matches any character.
        $adapter->execute('CREATE TABLE sqliteXorders (id INTEGER)');
        $adapter->execute('INSERT INTO customers (id) VALUES (1)');
        $adapter->execute('INSERT INTO orders (id, customer_id) VALUES (1, 1)');

        $reset = new SchemaReset($adapter, 'sqlite', $this->path);
        self::assertSame(['customers', 'odd "name"', 'orders', 'sqliteXorders'], $reset->tables());

        self::assertSame(['customers', 'odd "name"', 'orders', 'sqliteXorders'], $reset->dropAll(), 'the referenced table goes before the one pointing at it, in name order');
        self::assertSame([], $reset->tables());
        self::assertSame([], $reset->dropAll(), 'an empty database has nothing to drop');

        $fk = $adapter->execute('PRAGMA foreign_keys')->rows[0] ?? [];
        self::assertSame(1, (int) (array_values($fk)[0] ?? 0), 'foreign keys are checked again afterwards');
    }
}
