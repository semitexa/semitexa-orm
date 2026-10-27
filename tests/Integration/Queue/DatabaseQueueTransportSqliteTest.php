<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Queue\DatabaseQueueTransport;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * The transport on SQLite, which DatabaseQueueTransportTest (MySQL here)
 * never ran: ids went back to the database raw, as text, which SQLite never
 * finds equal to the stored blob — nothing was claimed, acknowledged or
 * requeued.
 */
final class DatabaseQueueTransportSqliteTest extends TestCase
{
    private OrmManager $orm;
    private DatabaseQueueTransport $transport;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $this->orm->getAdapter()->execute(
            'CREATE TABLE queue_messages (
                id BLOB PRIMARY KEY,
                queue_name TEXT NOT NULL,
                payload TEXT NOT NULL,
                status TEXT NOT NULL,
                attempt_count INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 5,
                lease_owner TEXT NULL,
                lease_expires_at TEXT NULL,
                last_error TEXT NULL,
                queued_at TEXT NOT NULL
            )',
        );
        $this->transport = new DatabaseQueueTransport();
        (new \ReflectionProperty(DatabaseQueueTransport::class, 'orm'))->setValue($this->transport, $this->orm);
    }

    #[Test]
    public function a_message_is_claimed_and_acknowledged(): void
    {
        $this->transport->publish('jobs', 'a');

        $received = [];
        self::assertTrue($this->transport->consumeOne('jobs', function (string $p) use (&$received): void {
            $received[] = $p;
        }));

        self::assertSame(['a'], $received);
        self::assertSame([], $this->rows(), 'acknowledged: the row is gone');
    }

    /** Nothing removed dead letters, and the table only grew. */
    #[Test]
    public function dead_letters_older_than_the_retention_go_and_live_messages_stay(): void
    {
        $db = $this->orm->getAdapter();
        $old = date('Y-m-d H:i:s', time() - 15 * 86400);
        $recent = date('Y-m-d H:i:s', time() - 86400);
        foreach ([
            ['jobs.failed', 'queued', $old, 'dlq-old'],
            ['jobs.failed', 'queued', $recent, 'dlq-recent'],
            ['jobs', 'failed', $old, 'failed-old'],
            ['jobs', 'queued', $old, 'live-old'],
            ['other.failed', 'queued', $old, 'other-queue'],
        ] as [$queue, $status, $at, $payload]) {
            // As the transport writes ids: a blob, which a raw bound string is not on SQLite.
            $db->execute('INSERT INTO queue_messages (id, queue_name, payload, status, queued_at) VALUES (UNHEX(:id), :q, :p, :s, :at)', ['id' => bin2hex(random_bytes(16)), 'q' => $queue, 'p' => $payload, 's' => $status, 'at' => $at]);
        }
        $previous = getenv('QUEUE_DEAD_LETTER_RETENTION_DAYS');
        putenv('QUEUE_DEAD_LETTER_RETENTION_DAYS=14');
        try {
            self::assertSame(2, $this->transport->pruneDeadLetters('jobs', time()));
            putenv('QUEUE_DEAD_LETTER_RETENTION_DAYS=0');
            self::assertSame(0, $this->transport->pruneDeadLetters('other', time()), '0 keeps them');
        } finally {
            putenv($previous === false ? 'QUEUE_DEAD_LETTER_RETENTION_DAYS' : 'QUEUE_DEAD_LETTER_RETENTION_DAYS=' . $previous);
        }

        $left = array_column($this->orm->getAdapter()->execute('SELECT payload FROM queue_messages')->rows, 'payload');
        sort($left);
        self::assertSame(['dlq-recent', 'live-old', 'other-queue'], $left, 'a queued message is never a dead letter, whatever its age');
    }

    #[Test]
    public function a_failed_run_is_requeued_with_its_error(): void
    {
        $this->transport->publish('jobs', 'a');

        $this->transport->consumeOne('jobs', static function (): void {
            throw new \RuntimeException('boom');
        });

        $row = $this->rows()[0] ?? [];
        self::assertSame('queued', $row['status'] ?? null);
        self::assertSame(1, (int) ($row['attempt_count'] ?? 0));
        self::assertStringContainsString('boom', (string) ($row['last_error'] ?? ''));
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->orm->getAdapter()->execute('SELECT status, attempt_count, last_error FROM queue_messages')->rows;
    }
}
