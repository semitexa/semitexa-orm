<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Queue\DatabaseQueueTransport;
use Semitexa\Orm\OrmManager;

/**
 * Claim-lease semantics of the broker-less 'database' transport against a
 * real database. Skips when no database is reachable (pure-unit contexts).
 */
final class DatabaseQueueTransportTest extends TestCase
{
    private DatabaseAdapterInterface $adapter;
    private DatabaseQueueTransport $transport;
    private string $queue;

    protected function setUp(): void
    {
        try {
            $this->adapter = new OrmManager()->getAdapter();
            $this->adapter->execute('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database unavailable: ' . $e->getMessage());
        }

        $this->adapter->execute(
            'CREATE TABLE IF NOT EXISTS queue_messages (
                id BINARY(16) NOT NULL PRIMARY KEY,
                queue_name VARCHAR(128) NOT NULL,
                payload LONGTEXT NOT NULL,
                status VARCHAR(16) NOT NULL,
                attempt_count INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 5,
                lease_owner VARCHAR(128) NULL,
                lease_expires_at DATETIME NULL,
                last_error VARCHAR(512) NULL,
                queued_at DATETIME NOT NULL
            )',
            [],
        );

        $this->transport = new DatabaseQueueTransport();
        $this->queue = 'itest-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (isset($this->adapter)) {
            $this->adapter->execute(
                'DELETE FROM queue_messages WHERE queue_name = :q',
                ['q' => $this->queue],
            );
        }
    }

    #[Test]
    public function publishedMessageIsConsumedOnceAndAcked(): void
    {
        $this->transport->publish($this->queue, '{"job":"a"}');

        $received = [];
        $claimed = $this->transport->consumeOne($this->queue, function (string $p) use (&$received): void {
            $received[] = $p;
        });

        self::assertTrue($claimed);
        self::assertSame(['{"job":"a"}'], $received);
        self::assertSame(0, $this->countRows(), 'acked message must be deleted');
        self::assertFalse($this->transport->consumeOne($this->queue, static fn (string $p) => null));
    }

    #[Test]
    public function messagesAreConsumedInPublishOrder(): void
    {
        $this->transport->publish($this->queue, 'first');
        $this->transport->publish($this->queue, 'second');

        $received = [];
        $collector = function (string $p) use (&$received): void {
            $received[] = $p;
        };
        $this->transport->consumeOne($this->queue, $collector);
        $this->transport->consumeOne($this->queue, $collector);

        self::assertSame(['first', 'second'], $received);
    }

    #[Test]
    public function failingCallbackRequeuesWithErrorThenSucceeds(): void
    {
        $this->transport->publish($this->queue, 'flaky');

        $claimed = $this->transport->consumeOne($this->queue, static function (): void {
            throw new \RuntimeException('boom');
        });
        self::assertTrue($claimed);

        $row = $this->adapter->execute(
            'SELECT status, attempt_count, last_error, lease_owner FROM queue_messages WHERE queue_name = :q',
            ['q' => $this->queue],
        )->rows[0];
        self::assertSame('queued', $row['status']);
        self::assertSame(1, (int) $row['attempt_count']);
        self::assertStringContainsString('boom', (string) $row['last_error']);
        self::assertNull($row['lease_owner']);

        $ok = $this->transport->consumeOne($this->queue, static fn (string $p) => null);
        self::assertTrue($ok);
        self::assertSame(0, $this->countRows());
    }

    #[Test]
    public function exhaustedMessageParksAsFailedAndIsNotReclaimed(): void
    {
        $this->transport->publish($this->queue, 'poison');
        $this->adapter->execute(
            'UPDATE queue_messages SET max_attempts = 1 WHERE queue_name = :q',
            ['q' => $this->queue],
        );

        $this->transport->consumeOne($this->queue, static function (): void {
            throw new \RuntimeException('always fails');
        });

        $row = $this->adapter->execute(
            'SELECT status FROM queue_messages WHERE queue_name = :q',
            ['q' => $this->queue],
        )->rows[0];
        self::assertSame('failed', $row['status']);

        self::assertFalse(
            $this->transport->consumeOne($this->queue, static fn (string $p) => null),
            'failed messages must never be re-claimed',
        );
    }

    #[Test]
    public function emptyQueueClaimsNothing(): void
    {
        self::assertFalse($this->transport->consumeOne($this->queue, static fn (string $p) => null));
    }

    private function countRows(): int
    {
        $result = $this->adapter->execute(
            'SELECT COUNT(*) AS c FROM queue_messages WHERE queue_name = :q',
            ['q' => $this->queue],
        );

        return (int) ($result->rows[0]['c'] ?? -1);
    }
}
