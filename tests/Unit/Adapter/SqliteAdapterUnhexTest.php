<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\SqliteAdapter;

/**
 * The database queue transport claims with UNHEX(), built into SQLite since
 * 3.41. The adapter took 3.38 and stood in for it on 3.38-3.40 with a PHP
 * function that answered TEXT, not a BLOB; it now takes 3.41 and the built-in.
 */
final class SqliteAdapterUnhexTest extends TestCase
{
    #[Test]
    public function the_adapter_answers_unhex_with_the_built_in_blob(): void
    {
        // verify:accept-test-change the stand-in UNHEX and its test are removed with SQLite < 3.41 support; the built-in is pinned here instead
        $row = (new SqliteAdapter('sqlite::memory:'))
            ->execute("SELECT HEX(UNHEX('00ff41')) AS bytes, UNHEX('0g') AS bad, UNHEX('abc') AS odd, typeof(UNHEX('00ff41')) AS storage")
            ->rows[0] ?? null;

        self::assertSame(['bytes' => '00FF41', 'bad' => null, 'odd' => null, 'storage' => 'blob'], $row);
    }

    #[Test]
    public function an_sqlite_without_a_built_in_unhex_is_refused(): void
    {
        $check = new \ReflectionMethod(SqliteAdapter::class, 'assertSupportedVersion');
        $check->invoke(null, '3.41.0');
        $check->invoke(null, '3.53.0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Semitexa ORM requires SQLite 3.41.0+ (built-in UNHEX), got 3.40.1.');
        $check->invoke(null, '3.40.1');
    }

    /** review: the refused version was kept, so the second call returned it and refused nothing. */
    #[Test]
    public function a_refused_version_is_refused_again_not_remembered(): void
    {
        $adapter = new class ('sqlite::memory:') extends SqliteAdapter {
            public function query(string $sql): QueryResult
            {
                return new QueryResult([['sqlite_version()' => '3.40.1']]);
            }
        };

        $messages = [];
        foreach ([1, 2] as $attempt) {
            try {
                $adapter->getServerVersion();
                $messages[] = 'no refusal';
            } catch (\RuntimeException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertSame(array_fill(0, 2, 'Semitexa ORM requires SQLite 3.41.0+ (built-in UNHEX), got 3.40.1.'), $messages);
    }
}
