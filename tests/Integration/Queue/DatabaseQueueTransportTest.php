<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Queue\QueueStopSignals;
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
        // Not straight back: a throwing callback spent every attempt in a second.
        self::assertFalse($this->transport->consumeOne($this->queue, static fn (string $p) => null), 'the retry waits its backoff');

        $this->adapter->execute(
            'UPDATE queue_messages SET queued_at = :due WHERE queue_name = :q',
            ['due' => date('Y-m-d H:i:s', time() - 1), 'q' => $this->queue],
        );
        $ok = $this->transport->consumeOne($this->queue, static fn (string $p) => null);
        self::assertTrue($ok);
        self::assertSame(0, $this->countRows());
    }

    /**
     * A worker that died on the last attempt left the row `processing` with
     * no attempt left: never retried, never listed, never deleted.
     */
    #[Test]
    public function a_message_whose_worker_died_on_the_last_attempt_becomes_a_dead_letter(): void
    {
        $this->transport->publish($this->queue, 'killer');
        $this->adapter->execute(
            "UPDATE queue_messages SET status = 'processing', attempt_count = max_attempts, lease_owner = 'dead-worker', lease_expires_at = :past WHERE queue_name = :q",
            ['past' => date('Y-m-d H:i:s', time() - 10), 'q' => $this->queue],
        );

        self::assertFalse($this->transport->consumeOne($this->queue, static fn (string $p) => null));

        $letters = $this->transport->deadLetters($this->queue);
        self::assertCount(1, $letters);
        self::assertStringContainsString('lease ran out on the last attempt', (string) $letters[0]->error);
    }

    /** A consumer that ran past its lease deleted the row another consumer had reclaimed. */
    #[Test]
    public function a_consumer_that_lost_its_lease_neither_acks_nor_requeues_the_message(): void
    {
        $this->transport->publish($this->queue, 'slow');
        $this->transport->consumeOne($this->queue, function (string $p): void {
            // Meanwhile the lease ran out and another consumer took the message.
            $this->adapter->execute(
                "UPDATE queue_messages SET lease_owner = 'consumer-b' WHERE queue_name = :q",
                ['q' => $this->queue],
            );
        });

        $row = $this->adapter->execute(
            'SELECT status, lease_owner FROM queue_messages WHERE queue_name = :q',
            ['q' => $this->queue],
        )->rows[0] ?? null;
        self::assertNotNull($row, 'consumer B is still working on it');
        self::assertSame(['processing', 'consumer-b'], [$row['status'], $row['lease_owner']]);
    }

    /**
     * Review: with a publish joining the caller's transaction, the claim's
     * locking sub-select waited on that uncommitted row, and every consumer
     * of the queue stood still until the caller's transaction ended.
     */
    #[Test]
    public function a_message_still_inside_another_transaction_does_not_hold_up_the_queue(): void
    {
        if ((new OrmManager())->getDriver() !== 'mysql') {
            self::markTestSkipped('row locks: MySQL');
        }
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: 'mysql', getenv('DB_PORT') ?: '3306', getenv('DB_DATABASE') ?: 'semitexa');
        $other = new \PDO($dsn, (string) (getenv('DB_USERNAME') ?: getenv('DB_USER') ?: 'root'), (string) getenv('DB_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $this->transport->publish($this->queue, 'committed');
        $other->beginTransaction();
        try {
            $other->prepare(
                "INSERT INTO queue_messages (id, queue_name, payload, status, attempt_count, max_attempts, queued_at)
                 VALUES (UNHEX(REPLACE(UUID(), '-', '')), ?, 'in-flight', 'queued', 0, 5, ?)",
            )->execute([$this->queue, date('Y-m-d H:i:s', time() - 60)]);

            $received = [];
            $started = microtime(true);
            $this->transport->consumeOne($this->queue, function (string $p) use (&$received): void {
                $received[] = $p;
            });

            self::assertSame(['committed'], $received);
            self::assertLessThan(2.0, microtime(true) - $started, 'the claim did not wait on the uncommitted row');
        } finally {
            $other->rollBack();
        }
    }

    /**
     * Published on its own connection, the message outlived the caller's
     * rollback (and on SQLite waited out the caller's write lock, then failed).
     */
    #[Test]
    public function a_message_published_in_a_transaction_is_rolled_back_with_it(): void
    {
        try {
            (new OrmManager())->getTransactionManager()->run(function (): void {
                $this->transport->publish($this->queue, 'in-tx');
                throw new \RuntimeException('the caller rolls back');
            });
        } catch (\RuntimeException) {
        }

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
    public function a_delayed_message_waits_its_turn_and_does_not_hold_up_the_rest(): void
    {
        // The worker used to sleep the retry delay inside the consume loop;
        // the table holds a delayed retry back by itself now.
        $this->transport->publishDelayed($this->queue, '{"job":"retry"}', 60);
        $this->transport->publish($this->queue, '{"job":"fresh"}');

        $received = [];
        $collector = function (string $p) use (&$received): void {
            $received[] = $p;
        };

        self::assertTrue($this->transport->consumeOne($this->queue, $collector));
        self::assertFalse($this->transport->consumeOne($this->queue, $collector), 'the delayed message is not due yet');
        self::assertSame(['{"job":"fresh"}'], $received);
        self::assertSame(1, $this->transport->depth($this->queue), 'a delayed message is still owed');
        self::assertNull($this->transport->oldestAgeSeconds($this->queue), 'but it is not waiting yet');

        $this->adapter->execute(
            'UPDATE queue_messages SET queued_at = :due WHERE queue_name = :q',
            ['due' => date('Y-m-d H:i:s', time() - 1), 'q' => $this->queue],
        );

        self::assertTrue($this->transport->consumeOne($this->queue, $collector));
        self::assertSame(['{"job":"fresh"}', '{"job":"retry"}'], $received);
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

    /** `docker stop`: the job in hand finishes and is acked, the next stays queued. */
    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function sigterm_ends_consume_after_the_message_in_hand(): void
    {
        $this->transport->publish($this->queue, 'first');
        $this->transport->publish($this->queue, 'second');
        self::assertTrue(QueueStopSignals::install($this->transport));

        $done = [];
        try {
            $this->transport->consume($this->queue, static function (string $payload) use (&$done): void {
                posix_kill(posix_getpid(), SIGTERM);
                usleep(10_000);
                $done[] = $payload;
            });
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_async_signals(false);
        }

        self::assertCount(1, $done);
        self::assertSame(1, $this->transport->depth($this->queue), 'the other message waits for the next worker');
        self::assertSame(1, $this->countRows(), 'the finished one was acked');
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
