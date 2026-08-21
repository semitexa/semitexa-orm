<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DriverErrorClassifier;
use Semitexa\Orm\Exception\ConnectionLostException;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Orm\Exception\DeadlockException;
use Semitexa\Orm\Exception\LockWaitTimeoutException;

final class DriverErrorClassifierTest extends TestCase
{
    #[Test]
    public function it_maps_driver_errors_onto_the_typed_hierarchy(): void
    {
        $cases = [
            [['40001', 1213, 'Deadlock found when trying to get lock'], DeadlockException::class, true],
            [['HY000', 1205, 'Lock wait timeout exceeded'], LockWaitTimeoutException::class, true],
            [['HY000', 2006, 'MySQL server has gone away'], ConnectionLostException::class, true],
            [['HY000', 2013, 'Lost connection to MySQL server during query'], ConnectionLostException::class, true],
            [['08S01', 0, 'Communication link failure'], ConnectionLostException::class, true],
            [['23000', 1062, "Duplicate entry 'x' for key 'y'"], ConstraintViolationException::class, false],
            [['23000', 1452, 'Cannot add or update a child row'], ConstraintViolationException::class, false],
        ];

        foreach ($cases as [$errorInfo, $expectedClass, $expectedTransient]) {
            $pdoException = new \PDOException($errorInfo[2]);
            $pdoException->errorInfo = $errorInfo;

            $classified = DriverErrorClassifier::classify($pdoException);

            self::assertInstanceOf($expectedClass, $classified, $errorInfo[2]);
            self::assertSame($expectedTransient, $classified->isTransient(), $errorInfo[2]);
            self::assertSame($errorInfo[0], $classified->sqlState);
            self::assertSame($errorInfo[1], $classified->driverCode);
            self::assertSame($pdoException, $classified->getPrevious(), 'the original driver exception must be chained');
        }
    }

    #[Test]
    public function unrecognized_errors_classify_to_null_so_the_original_exception_survives(): void
    {
        $pdoException = new \PDOException('You have an error in your SQL syntax');
        $pdoException->errorInfo = ['42000', 1064, 'syntax error'];

        self::assertNull(DriverErrorClassifier::classify($pdoException));
    }

    #[Test]
    public function only_read_only_statements_qualify_for_transparent_replay(): void
    {
        self::assertTrue(DriverErrorClassifier::isReadOnlyStatement('SELECT * FROM t'));
        self::assertTrue(DriverErrorClassifier::isReadOnlyStatement("  \n select 1"));
        self::assertTrue(DriverErrorClassifier::isReadOnlyStatement('SHOW TABLES'));
        self::assertTrue(DriverErrorClassifier::isReadOnlyStatement('EXPLAIN SELECT 1'));

        self::assertFalse(DriverErrorClassifier::isReadOnlyStatement('INSERT INTO t (id) VALUES (1)'));
        self::assertFalse(DriverErrorClassifier::isReadOnlyStatement('UPDATE t SET x = 1'));
        self::assertFalse(DriverErrorClassifier::isReadOnlyStatement('DELETE FROM t'));
        // WITH can prefix UPDATE/DELETE in MySQL 8 — excluded on purpose.
        self::assertFalse(DriverErrorClassifier::isReadOnlyStatement('WITH cte AS (SELECT 1) DELETE FROM t'));
    }
}
