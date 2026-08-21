<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

use Semitexa\Orm\Exception\ConnectionLostException;
use Semitexa\Orm\Exception\ConstraintViolationException;
use Semitexa\Orm\Exception\DatabaseException;
use Semitexa\Orm\Exception\DeadlockException;
use Semitexa\Orm\Exception\LockWaitTimeoutException;
use Semitexa\Orm\Exception\QueryTimeoutException;

/**
 * Maps raw \PDOException driver errors onto the typed hierarchy under
 * {@see DatabaseException}, so callers can decide retry-vs-fail from the
 * exception TYPE instead of string-matching messages or digging through
 * errorInfo.
 *
 * classify() returns null for anything it does not recognize — the caller
 * rethrows the original \PDOException unchanged. Deliberate: wrapping every
 * driver error would break the adapters' own errorInfo-based handling (the
 * MySQL 1615 re-prepare path) and change behavior for exceptions this
 * taxonomy has nothing to say about.
 */
final class DriverErrorClassifier
{
    /** MySQL client/server errno values that mean "the connection is gone". */
    private const CONNECTION_LOST_CODES = [
        1053, // server shutdown in progress
        2002, // can't connect through socket
        2003, // can't connect to server
        2006, // server has gone away
        2013, // lost connection during query
        4031, // client disconnected due to inactivity (MySQL 8.0.24+)
    ];

    public static function classify(\PDOException $e): ?DatabaseException
    {
        $sqlState = isset($e->errorInfo[0]) ? (string) $e->errorInfo[0] : null;
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;

        if ($driverCode === 1213 || $sqlState === '40001') {
            return new DeadlockException($e->getMessage(), $sqlState, $driverCode, $e);
        }

        if ($driverCode === 1205) {
            return new LockWaitTimeoutException($e->getMessage(), $sqlState, $driverCode, $e);
        }

        if ($driverCode === 3024) {
            return new QueryTimeoutException($e->getMessage(), $sqlState, $driverCode, $e);
        }

        if (
            in_array($driverCode, self::CONNECTION_LOST_CODES, true)
            || ($sqlState !== null && str_starts_with($sqlState, '08'))
        ) {
            return new ConnectionLostException($e->getMessage(), $sqlState, $driverCode, $e);
        }

        if ($sqlState !== null && str_starts_with($sqlState, '23')) {
            return new ConstraintViolationException($e->getMessage(), $sqlState, $driverCode, $e);
        }

        return null;
    }

    /**
     * Statements that are safe to transparently retry on a fresh connection
     * after the previous one died: they change nothing, so "did the server
     * apply it before dropping?" has no wrong answer. A write is never
     * auto-retried — a 2013 can arrive AFTER the server committed the write,
     * and replaying it would duplicate the effect.
     */
    public static function isReadOnlyStatement(string $sql): bool
    {
        return (bool) preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql);
    }
}
