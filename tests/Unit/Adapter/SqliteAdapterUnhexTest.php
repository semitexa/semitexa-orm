<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;

/**
 * UNHEX() came with SQLite 3.41 and the adapter takes 3.38: on 3.38–3.40 the
 * database queue transport failed every claim with "no such function".
 */
final class SqliteAdapterUnhexTest extends TestCase
{
    /**
     * Pdo\Sqlite where PDO::connect() gives it; a plain PDO from `new PDO`, and
     * from PDO::connect() on builds that do not (the stack smoke's setup-php
     * PHP 8.4.27, 30.10), where the function was silently not added.
     *
     * @return iterable<string, array{bool}>
     */
    public static function connections(): iterable
    {
        yield 'PDO::connect' => [true];
        yield 'a plain PDO' => [false];
    }

    #[Test]
    #[DataProvider('connections')]
    public function an_older_sqlite_gets_an_unhex_that_answers_as_the_built_in_one(bool $viaConnect): void
    {
        // verify:accept-test-change the Pdo\Sqlite assertion was the assumption a plain-PDO build broke; both kinds now run
        /** @var \PDO $pdo the driver subclass or not, which is the point */
        $pdo = $viaConnect ? \PDO::connect('sqlite::memory:') : new \PDO('sqlite::memory:');
        // A build with neither driver method (setup-php's 8.4.27) cannot add it:
        // there the call must not throw, and the built-in answers.
        // @phpstan-ignore function.alreadyNarrowedType (the stubs say always; the setup-php build said no)
        $canAdd = $pdo instanceof \Pdo\Sqlite || method_exists($pdo, 'sqliteCreateFunction');
        // As on 3.40: the function is added (replacing the built-in on this connection).
        (new \ReflectionMethod(SqliteAdapter::class, 'addMissingFunctions'))->invoke(null, $pdo, '3.40.1');

        $versionStatement = $pdo->query('SELECT sqlite_version()');
        self::assertInstanceOf(\PDOStatement::class, $versionStatement);
        if (!$canAdd && version_compare((string) $versionStatement->fetchColumn(), '3.41.0', '<')) {
            // Neither a way to add the function nor a built-in one: UNHEX is absent, as the adapter says.
            $this->expectException(\PDOException::class);
            $pdo->query("SELECT UNHEX('41')");

            return;
        }

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
        self::assertSame($canAdd ? 'text' : 'blob', $row['storage']);
    }

    #[Test]
    public function the_adapter_answers_unhex_on_whatever_sqlite_it_has(): void
    {
        $adapter = new SqliteAdapter('sqlite::memory:');

        self::assertSame('41', $adapter->execute("SELECT HEX(UNHEX('41')) AS h")->rows[0]['h'] ?? null);
    }
}
