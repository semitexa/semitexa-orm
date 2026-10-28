<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;

/**
 * UNHEX() came with SQLite 3.41 and the adapter takes 3.38: on 3.38–3.40 the
 * database queue transport failed every claim with "no such function".
 */
final class SqliteAdapterUnhexTest extends TestCase
{
    #[Test]
    public function an_older_sqlite_gets_an_unhex_that_answers_as_the_built_in_one(): void
    {
        $pdo = \PDO::connect('sqlite::memory:');
        self::assertInstanceOf(\Pdo\Sqlite::class, $pdo);
        // As on 3.40: the function is added (replacing the built-in on this connection).
        (new \ReflectionMethod(SqliteAdapter::class, 'addMissingFunctions'))->invoke(null, $pdo, '3.40.1');

        $statement = $pdo->query("SELECT UNHEX('00ff41') AS bytes, UNHEX('0g') AS bad, UNHEX('abc') AS odd, UNHEX(NULL) AS none, UNHEX('') AS empty, typeof(UNHEX('00ff41')) AS storage");
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        self::assertSame("\x00\xffA", $row['bytes']);
        self::assertNull($row['bad']);
        self::assertNull($row['odd']);
        self::assertNull($row['none']);
        self::assertSame('', $row['empty']);
        // The built-in answers a blob; a PHP function cannot (see addMissingFunctions()).
        self::assertSame('text', $row['storage']);
    }

    #[Test]
    public function the_adapter_answers_unhex_on_whatever_sqlite_it_has(): void
    {
        $adapter = new SqliteAdapter('sqlite::memory:');

        self::assertSame('41', $adapter->execute("SELECT HEX(UNHEX('41')) AS h")->rows[0]['h'] ?? null);
    }
}
