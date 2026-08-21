<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;
use Semitexa\Orm\Exception\ConstraintViolationException;

/**
 * The typed error taxonomy is a package-wide contract, not a MySQL-only one:
 * a SQLite write that violates a constraint must surface as
 * ConstraintViolationException too, or callers branching on the type would
 * silently take the wrong path depending on which driver is configured.
 */
final class SqliteAdapterClassificationTest extends TestCase
{
    #[Test]
    public function a_sqlite_constraint_violation_is_classified(): void
    {
        $adapter = new SqliteAdapter('sqlite::memory:');
        $adapter->execute('CREATE TABLE classify_probe (id INTEGER PRIMARY KEY)');
        $adapter->execute('INSERT INTO classify_probe (id) VALUES (1)');

        try {
            $adapter->execute('INSERT INTO classify_probe (id) VALUES (1)');
            self::fail('the duplicate insert must throw');
        } catch (ConstraintViolationException $e) {
            self::assertSame('23000', $e->sqlState);
            self::assertFalse($e->isTransient(), 'a constraint violation must never be auto-retried');
            self::assertInstanceOf(\PDOException::class, $e->getPrevious(), 'the driver exception must stay chained');
        }
    }

    #[Test]
    public function an_unrecognized_error_keeps_its_original_exception(): void
    {
        $adapter = new SqliteAdapter('sqlite::memory:');

        try {
            $adapter->query('THIS IS NOT SQL');
            self::fail('the syntax error must throw');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(
                ConstraintViolationException::class,
                $e,
                'only recognized SQLSTATEs are wrapped; everything else propagates unchanged',
            );
        }
    }
}
