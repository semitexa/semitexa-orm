<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;

/**
 * Once commit() has returned, the transaction is durable. The post-commit
 * event flush is a best-effort invalidation signal (same contract as
 * AggregateWriteEngine::dispatchResourceChanged): a throwing listener must
 * NOT surface as a failed write — a caller seeing an exception would retry a
 * transaction that already committed and duplicate it. This pins:
 *  1. run() returns the callback result even when every listener throws;
 *  2. one throwing event does not starve the remaining buffered events;
 *  3. events flush AFTER the connection went back to the pool, so slow
 *     subscribers never extend the connection hold time;
 *  4. a rollback still clears the buffer and dispatches nothing.
 */
final class TransactionManagerPostCommitFlushTest extends TestCase
{
    #[Test]
    public function a_throwing_post_commit_listener_does_not_fail_the_committed_transaction(): void
    {
        $pool = new SequenceRecordingPool();
        $dispatcher = new ThrowingRecordingDispatcher(throwOn: 'first');
        $manager = new TransactionManager($pool, new PostCommitFakeAdapter(), $dispatcher);

        $first = (object) ['id' => 'first'];
        $second = (object) ['id' => 'second'];

        $result = $manager->run(function () use ($manager, $first, $second) {
            $manager->bufferEvent($first);
            $manager->bufferEvent($second);

            return 'committed-result';
        });

        self::assertSame('committed-result', $result, 'the committed transaction must return its result despite the throwing listener');
        self::assertSame(
            ['first', 'second'],
            $dispatcher->dispatched,
            'the throwing first event must not starve the second buffered event',
        );
        self::assertSame([], $manager->getPendingEvents(), 'the buffer must be empty after the flush');
        self::assertFalse($manager->isActive());
    }

    #[Test]
    public function events_flush_after_the_connection_returned_to_the_pool(): void
    {
        $pool = new SequenceRecordingPool();
        $dispatcher = new ThrowingRecordingDispatcher(throwOn: null);
        $dispatcher->sequence = $pool->sequence;
        $manager = new TransactionManager($pool, new PostCommitFakeAdapter(), $dispatcher);

        $manager->run(function () use ($manager) {
            $manager->bufferEvent((object) ['id' => 'first']);

            return null;
        });

        self::assertSame(
            ['pop', 'push', 'dispatch:first'],
            $pool->sequence->getArrayCopy(),
            'the connection must be back in the pool before any listener runs',
        );
    }

    #[Test]
    public function a_rolled_back_transaction_dispatches_nothing(): void
    {
        $pool = new SequenceRecordingPool();
        $dispatcher = new ThrowingRecordingDispatcher(throwOn: null);
        $manager = new TransactionManager($pool, new PostCommitFakeAdapter(), $dispatcher);

        try {
            $manager->run(function () use ($manager) {
                $manager->bufferEvent((object) ['id' => 'first']);
                throw new \RuntimeException('domain failure');
            });
            self::fail('the domain failure must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('domain failure', $e->getMessage());
        }

        self::assertSame([], $dispatcher->dispatched, 'a rolled-back transaction must not signal any change');
        self::assertSame([], $manager->getPendingEvents());
    }

    /** A mail sent inside a transaction went out even when the transaction rolled back. */
    #[Test]
    public function after_commit_work_waits_for_the_commit_and_a_rollback_drops_it(): void
    {
        $pool = new SequenceRecordingPool();
        $manager = new TransactionManager($pool, new PostCommitFakeAdapter());

        $manager->afterCommit(static function () use ($pool): void { $pool->sequence[] = 'now'; });
        $manager->run(function () use ($manager, $pool) {
            $manager->afterCommit(static function () use ($pool): void { $pool->sequence[] = 'after commit'; });

            return null;
        });
        try {
            $manager->run(function () use ($manager, $pool) {
                $manager->afterCommit(static function () use ($pool): void { $pool->sequence[] = 'rolled back'; });
                throw new \RuntimeException('domain failure');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(
            ['now', 'pop', 'push', 'after commit', 'pop', 'push'],
            $pool->sequence->getArrayCopy(),
            'outside a transaction it runs at once; inside, after the connection is back, and never after a rollback — with no event dispatcher at all',
        );
    }

    #[Test]
    public function a_rolled_back_savepoint_drops_what_it_asked_for_and_keeps_the_rest(): void
    {
        $dispatcher = new ThrowingRecordingDispatcher(throwOn: null);
        $manager = new TransactionManager(new SequenceRecordingPool(), new PostCommitFakeAdapter(), $dispatcher);
        $ran = [];

        $manager->run(function () use ($manager, &$ran) {
            $manager->afterCommit(static function () use (&$ran): void { $ran[] = 'outer'; });
            try {
                $manager->run(function () use ($manager, &$ran) {
                    $manager->afterCommit(static function () use (&$ran): void { $ran[] = 'savepoint'; });
                    $manager->bufferEvent((object) ['id' => 'savepoint event']);
                    throw new \RuntimeException('inner failure');
                });
            } catch (\RuntimeException) {
            }
            $manager->run(function () use ($manager) {
                $manager->bufferEvent((object) ['id' => 'released']);
            });

            return null;
        });

        self::assertSame(['outer'], $ran);
        self::assertSame(['released'], $dispatcher->dispatched, 'a write the savepoint undid signals nothing');
    }

    #[Test]
    public function throwing_after_commit_work_does_not_fail_the_committed_transaction(): void
    {
        $manager = new TransactionManager(new SequenceRecordingPool(), new PostCommitFakeAdapter());
        $ran = false;

        $result = $manager->run(function () use ($manager, &$ran) {
            $manager->afterCommit(static function (): void { throw new \RuntimeException('smtp down'); });
            $manager->afterCommit(static function () use (&$ran): void { $ran = true; });

            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertTrue($ran, 'one failing piece of work does not starve the next');
    }
}

/** Hands out real sqlite PDOs and records pop/push ordering in a shared sequence. */
final class SequenceRecordingPool implements ConnectionPoolInterface
{
    public \ArrayObject $sequence;

    public function __construct()
    {
        $this->sequence = new \ArrayObject();
    }

    public function pop(?float $timeout = null): \PDO
    {
        $this->sequence[] = 'pop';

        return new \PDO('sqlite::memory:');
    }

    public function push(\PDO $connection): void
    {
        $this->sequence[] = 'push';
    }

    public function close(): void {}
    public function getSize(): int { return 1; }
    public function getAvailable(): int { return 1; }
    public function switchTo(string $tenantId): void {}
}

/** Records every dispatched event id; optionally throws on a given id (after recording it). */
final class ThrowingRecordingDispatcher implements EventDispatcherInterface
{
    /** @var string[] */
    public array $dispatched = [];

    public ?\ArrayObject $sequence = null;

    public function __construct(private readonly ?string $throwOn) {}

    public function create(string $eventClass, array $payload): object
    {
        return (object) $payload;
    }

    public function dispatch(object $event): void
    {
        $id = (string) ($event->id ?? '?');
        $this->dispatched[] = $id;
        if ($this->sequence !== null) {
            $this->sequence[] = 'dispatch:' . $id;
        }
        if ($id === $this->throwOn) {
            throw new \RuntimeException('listener exploded for ' . $id);
        }
    }

    public function addPostDispatchHook(callable $hook): void {}
}

/** A non-SQLite adapter so run() takes the pooled outer-transaction path. */
final class PostCommitFakeAdapter implements DatabaseAdapterInterface
{
    public function supports(ServerCapability $capability): bool { return true; }
    public function getServerVersion(): string { return '8.0.0'; }
    public function execute(string $sql, array $params = []): QueryResult { return new QueryResult(); }
    public function query(string $sql): QueryResult { return new QueryResult(); }
    public function lastInsertId(): string { return '0'; }
}
