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
     * after the previous one died: they change nothing and hold nothing, so
     * "did the server apply it before dropping?" has no wrong answer. A write
     * is never auto-retried — a 2013 can arrive AFTER the server committed the
     * write, and replaying it would duplicate the effect.
     *
     * Two families of SELECT are deliberately excluded even though they read:
     * locking reads (FOR UPDATE / FOR SHARE / LOCK IN SHARE MODE), whose locks
     * died with the connection so a replay silently acquires DIFFERENT locks
     * than the caller believes it holds; and SELECTs with side effects
     * (INTO OUTFILE/DUMPFILE writes a file, GET_LOCK/RELEASE_LOCK/SLEEP change
     * server state). When in doubt the answer is "not replayable": the cost of
     * a false negative is one surfaced ConnectionLostException, the cost of a
     * false positive is a silent duplicate effect.
     */
    public static function isReadOnlyStatement(string $sql): bool
    {
        // Leading CTEs and parenthesised reads are read-only only when the CTE
        // body is a SELECT — MySQL 8 also allows `WITH ... UPDATE/DELETE`.
        if (!preg_match('/^[\s(]*(WITH\b.*?\bSELECT|SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/is', $sql)) {
            return false;
        }

        if (preg_match('/\b(UPDATE|DELETE|INSERT|REPLACE|MERGE)\b/i', $sql)
            && !preg_match('/^[\s(]*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sql)) {
            return false;
        }

        return !preg_match(
            '/\b(FOR\s+UPDATE|FOR\s+SHARE|LOCK\s+IN\s+SHARE\s+MODE|INTO\s+(OUTFILE|DUMPFILE)|GET_LOCK|RELEASE_LOCK|SLEEP)\b/i',
            $sql,
        );
    }
}
