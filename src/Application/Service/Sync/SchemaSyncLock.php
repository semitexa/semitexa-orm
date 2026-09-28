<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Sync;

use Semitexa\Orm\Exception\SchemaSyncLockLostException;
use Semitexa\Orm\Exception\SchemaSyncLockTimeoutException;
use Semitexa\Orm\OrmManager;

/**
 * Serialises schema synchronisation across processes and nodes.
 *
 * Several nodes deploying at once would each diff the schema, each plan the
 * same DDL and each run it — the second ALTER against a column the first one
 * already added. The lock therefore covers the WHOLE diff → plan → execute
 * cycle, not execute() alone: a node that waited must re-read the schema the
 * winner left behind, and usually finds nothing left to do.
 *
 * MySQL named locks (GET_LOCK) belong to the session, survive the implicit
 * commit every DDL statement performs, and are released when the session ends
 * — so a process killed mid-sync never leaves the schema locked. SQLite has
 * one writer per file already and takes no lock.
 *
 * The lock is held on a pooled connection. In the CLI, where schema sync runs,
 * the pool is one shared connection and the plan's own pop() gets the same
 * session back; under a coroutine pool the plan pins a second connection, so
 * a pool of size one would wait on itself.
 */
final class SchemaSyncLock
{
    private const DEFAULT_WAIT_SECONDS = 300;

    public function __construct(
        private readonly OrmManager $orm,
        private readonly int $waitSeconds = self::DEFAULT_WAIT_SECONDS,
    ) {}

    /**
     * Run $cycle while holding the schema lock for this database.
     *
     * @template T
     * @param callable(): T $cycle
     * @return T
     *
     * @throws SchemaSyncLockTimeoutException when another sync held it for longer than the wait
     * @throws SchemaSyncLockLostException    when the lock's session ended before the cycle did
     */
    public function run(callable $cycle): mixed
    {
        if ($this->orm->getDriver() !== 'mysql') {
            return $cycle();
        }

        $name = self::lockName($this->orm->getDatabaseName());
        $pool = $this->orm->getPool();
        $pdo  = $pool->pop();

        try {
            $statement = $pdo->prepare('SELECT GET_LOCK(:name, :wait)');
            $statement->execute(['name' => $name, 'wait' => $this->waitSeconds]);
            $acquired = $statement->fetchColumn();

            if ((string) $acquired !== '1') {
                throw SchemaSyncLockTimeoutException::for($name, $this->waitSeconds);
            }

            try {
                $result = $cycle();

                // MySQL releases a named lock when its session ends. If the
                // lock's connection died during the cycle, the pool carried on
                // with a fresh session and another node may have been syncing
                // alongside this one; say so rather than report a clean run.
                try {
                    $held = $pdo->prepare('SELECT IS_USED_LOCK(:name) = CONNECTION_ID()');
                    $held->execute(['name' => $name]);
                    $stillHeld = (string) $held->fetchColumn() === '1';
                } catch (\PDOException $e) {
                    throw SchemaSyncLockLostException::for($name, $e); // the lock's session is gone
                }
                if (!$stillHeld) {
                    throw SchemaSyncLockLostException::for($name);
                }

                return $result;
            } finally {
                // A failed release must not mask the cycle's own error — on a
                // connection that died mid-DDL it would. The lock ends with the
                // session anyway.
                try {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
                    $release->execute(['name' => $name]);
                } catch (\Throwable) {
                }
            }
        } finally {
            $pool->push($pdo);
        }
    }

    /** MySQL caps lock names at 64 characters. */
    public static function lockName(string $databaseName): string
    {
        $name = 'semitexa.schema_sync.' . $databaseName;

        return strlen($name) <= 64 ? $name : 'semitexa.schema_sync.' . substr(hash('sha256', $databaseName), 0, 40);
    }
}
