<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\SqliteAdapter;

/**
 * Opening a connection must not need a lock it can fail to get: switching to
 * WAL takes one, and a process opening a file another one was writing failed
 * with "database is locked" before it ran a single query (2026-10-02). The
 * first fix left the PRAGMA's cursor open, and every FRESH file then failed
 * with "cannot change into wal mode from within a transaction".
 */
final class SqliteAdapterJournalModeTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/semitexa-sqlite-wal-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->path . $suffix);
        }
    }

    #[Test]
    public function a_fresh_file_opens_in_wal_mode(): void
    {
        $adapter = new SqliteAdapter('sqlite:' . $this->path);

        $adapter->execute('CREATE TABLE t (id INTEGER)');

        self::assertSame('wal', strtolower((string) (new \PDO('sqlite:' . $this->path))->query('PRAGMA journal_mode')->fetchColumn()));
    }

    #[Test]
    public function a_file_another_connection_is_writing_still_opens(): void
    {
        (new SqliteAdapter('sqlite:' . $this->path))->execute('CREATE TABLE t (id INTEGER)');
        $writer = new \PDO('sqlite:' . $this->path);
        $writer->exec('BEGIN IMMEDIATE');
        $writer->exec('INSERT INTO t VALUES (1)');

        // Already WAL: opening does not try to switch, so it needs no lock.
        $reader = new SqliteAdapter('sqlite:' . $this->path);
        $rows = $reader->execute('SELECT COUNT(*) AS c FROM t')->rows;

        $writer->exec('COMMIT');
        self::assertSame(0, (int) $rows[0]['c'], 'a reader sees the last committed state');
    }

    #[Test]
    public function the_lock_wait_is_ten_seconds_unless_the_caller_chose_one(): void
    {
        $default = new SqliteAdapter('sqlite:' . $this->path);
        $chosen = new SqliteAdapter('sqlite:' . $this->path, [\PDO::ATTR_TIMEOUT => 1]);

        self::assertSame(10000, (int) array_values($default->execute('PRAGMA busy_timeout')->rows[0])[0]);
        // PDO::ATTR_TIMEOUT sets the same busy handler; it used to be replaced silently.
        self::assertSame(1000, (int) array_values($chosen->execute('PRAGMA busy_timeout')->rows[0])[0]);
    }

    #[Test]
    public function the_default_attributes_reach_the_connection(): void
    {
        $adapter = new SqliteAdapter('sqlite:' . $this->path);
        $adapter->execute('CREATE TABLE t (id INTEGER)');
        $pdo = (new \ReflectionMethod($adapter, 'getConnection'))->invoke($adapter);

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        self::assertSame(\PDO::FETCH_ASSOC, $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
    }
}
