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
        // setUp() may skip after creating the adapter but before assigning the
        // per-test queue name. PHPUnit still calls tearDown() for that path.
        if (isset($this->adapter, $this->queue)) {
            $this->adapter->execute(
                'DELETE FROM queue_messages WHERE queue_name IN (:q, :failed)',
                ['q' => $this->queue, 'failed' => $this->queue . '.failed'],
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

    #[Test]
    public function the_queue_reports_its_depth_and_lists_both_kinds_of_dead_letter(): void
    {
        // verify:accept-test-change ep-queue-monitoring: tearDown now also removes the test's <queue>.failed rows
        $this->transport->publish($this->queue, '{"job":"waiting"}');
        $this->transport->publish($this->queue . '.failed', (string) json_encode([
            'type' => 'handler', 'handler' => 'App\\Jobs\\SendInvoice', 'attempts' => 3, 'error' => 'SMTP down', 'failed_at' => '2026-10-21T00:00:00+00:00',
        ]));
        $this->transport->publish($this->queue . '.failed', (string) json_encode(['raw' => '{not json', 'error' => 'not a JSON object']));

        self::assertSame(1, $this->transport->depth($this->queue));
        self::assertNotNull($this->transport->oldestAgeSeconds($this->queue));

        $letters = $this->transport->deadLetters($this->queue);
        self::assertCount(2, $letters);
        $byTarget = [];
        foreach ($letters as $letter) {
            $byTarget[$letter->target] = $letter;
        }
        self::assertSame('SMTP down', $byTarget['App\\Jobs\\SendInvoice']->error);
        self::assertTrue($byTarget['App\\Jobs\\SendInvoice']->retryable);
        self::assertFalse($byTarget['']->retryable, 'an unreadable payload can only be forgotten');
    }

    #[Test]
    public function a_retried_dead_letter_is_queued_again_once_and_a_forgotten_one_is_gone(): void
    {
        $this->transport->publish($this->queue . '.failed', (string) json_encode([
            'type' => 'handler', 'handler' => 'App\\Jobs\\SendInvoice', 'attempts' => 3, 'error' => 'SMTP down', 'failed_at' => '2026-10-21T00:00:00+00:00',
        ]));
        $this->transport->publish($this->queue . '.failed', '{"raw":"x","error":"bad"}');
        [$first, $second] = $this->transport->deadLetters($this->queue);
        $retryable = $first->retryable ? $first : $second;
        $raw = $first->retryable ? $second : $first;

        self::assertTrue($this->transport->retryDeadLetter($this->queue, $retryable->id));
        self::assertFalse($this->transport->retryDeadLetter($this->queue, $retryable->id), 'retried once, then gone');
        self::assertSame(1, $this->transport->depth($this->queue));

        $received = [];
        $this->transport->consumeOne($this->queue, function (string $p) use (&$received): void {
            $received[] = json_decode($p, true);
        });
        self::assertSame(0, $received[0]['attempts'], 'attempts start over');
        self::assertArrayNotHasKey('error', $received[0]);

        try {
            $this->transport->retryDeadLetter($this->queue, $raw->id);
            self::fail('an unreadable dead letter was retried');
        } catch (\LogicException $e) {
            self::assertStringContainsString('never readable', $e->getMessage());
        }
        self::assertTrue($this->transport->forgetDeadLetter($this->queue, $raw->id));
        self::assertSame([], $this->transport->deadLetters($this->queue));
        self::assertFalse($this->transport->forgetDeadLetter($this->queue, str_repeat('0', 32)));
    }

    #[Test]
    public function a_message_the_transport_gave_up_on_is_retried_in_place(): void
    {
        $this->transport->publish($this->queue, '{"handler":"App\\\\Jobs\\\\Crashes"}');
        $this->adapter->execute("UPDATE queue_messages SET status = 'failed', attempt_count = 5, last_error = 'worker died' WHERE queue_name = :q", ['q' => $this->queue]);

        [$letter] = $this->transport->deadLetters($this->queue);
        self::assertSame('worker died', $letter->error);
        self::assertSame(0, $this->transport->depth($this->queue));

        self::assertTrue($this->transport->retryDeadLetter($this->queue, $letter->id));
        self::assertSame(1, $this->transport->depth($this->queue));
        self::assertTrue($this->transport->consumeOne($this->queue, static fn (string $p) => null), 'claimable again');
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
