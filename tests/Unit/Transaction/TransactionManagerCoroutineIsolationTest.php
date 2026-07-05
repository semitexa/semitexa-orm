<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Transaction;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPoolInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;

/**
 * TransactionManager is a worker-singleton: one instance serves every request
 * coroutine on a worker. Its transaction state (active connection, nesting
 * depth, buffered events) is REQUEST-scoped, so it must be isolated per
 * coroutine — otherwise coroutine B, running while A has yielded mid-BEGIN,
 * would observe A's depth=1, take the nested-savepoint branch on A's connection,
 * and cross-leak A's pending events. This pins the CoroutineLocal isolation:
 * two overlapping transactions each get their own connection and their own
 * pending-event buffer.
 */
final class TransactionManagerCoroutineIsolationTest extends TestCase
{
    #[Test]
    public function concurrent_coroutines_get_isolated_transaction_state(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Coroutine isolation requires the Swoole runtime.');
        }

        $pool = new PerPopConnectionPool();
        $manager = new TransactionManager($pool, new NonSqliteAdapter());

        $eventA = (object) ['id' => 'A'];
        $eventB = (object) ['id' => 'B'];

        // Shared, cross-coroutine barrier + capture — deliberately NOT on the
        // manager, so the only thing under test is the manager's own isolation.
        $state = new \stdClass();
        $state->buffered = 0;
        $snapshots = new \ArrayObject();

        $body = function (string $key, object $event) use ($manager, $state, $snapshots): void {
            $manager->run(function () use ($manager, $event, $state, $snapshots, $key) {
                // This coroutine now holds its own outer transaction (depth=1).
                $manager->bufferEvent($event);
                $state->buffered++;

                // Yield until BOTH coroutines are mid-transaction, so the two
                // transactions genuinely overlap in time on this one worker.
                $spins = 0;
                while ($state->buffered < 2 && $spins < 2000) {
                    \Swoole\Coroutine::sleep(0.001);
                    $spins++;
                }

                // With shared state this coroutine would see BOTH events queued;
                // isolated, it sees only the one it buffered itself.
                $snapshots[$key] = $manager->getPendingEvents();

                return null;
            });
        };

        \Swoole\Coroutine\run(function () use ($body, $eventA, $eventB): void {
            $wg = new \Swoole\Coroutine\WaitGroup();
            $wg->add(2);
            \Swoole\Coroutine::create(function () use ($body, $eventA, $wg) {
                try {
                    $body('A', $eventA);
                } finally {
                    $wg->done();
                }
            });
            \Swoole\Coroutine::create(function () use ($body, $eventB, $wg) {
                try {
                    $body('B', $eventB);
                } finally {
                    $wg->done();
                }
            });
            $wg->wait();
        });

        // Connection isolation: each coroutine took the OUTER branch and popped
        // its own connection. A leaked depth would push the second coroutine into
        // the nested-savepoint branch, which does not pop — popCount would be 1.
        self::assertSame(2, $pool->popCount, 'each concurrent transaction must pop its own connection');
        self::assertCount(
            2,
            array_unique(array_map('spl_object_id', $pool->popped)),
            'the two overlapping transactions must not share a PDO',
        );

        // Pending-event isolation: each coroutine's buffer held only its own event.
        self::assertSame([$eventA], $snapshots['A'], 'coroutine A must see only its own buffered event');
        self::assertSame([$eventB], $snapshots['B'], 'coroutine B must see only its own buffered event');

        // The manager is idle again once both coroutines finished.
        self::assertFalse($manager->isActive(), 'no transaction state must linger after both coroutines complete');
    }
}

/** Hands out a fresh real PDO on every pop, so two coroutines get distinct connections. */
final class PerPopConnectionPool implements ConnectionPoolInterface
{
    public int $popCount = 0;

    /** @var \PDO[] */
    public array $popped = [];

    public function pop(float $timeout = -1): \PDO
    {
        $this->popCount++;
        $pdo = new \PDO('sqlite::memory:');
        $this->popped[] = $pdo;

        return $pdo;
    }

    public function push(\PDO $connection): void {}
    public function close(): void {}
    public function getSize(): int { return 2; }
    public function getAvailable(): int { return 2; }
    public function switchTo(string $tenantId): void {}
}

/** A non-SQLite adapter so run() takes the pooled outer-transaction path. */
final class NonSqliteAdapter implements DatabaseAdapterInterface
{
    public function supports(ServerCapability $capability): bool { return true; }
    public function getServerVersion(): string { return '8.0.0'; }
    public function execute(string $sql, array $params = []): QueryResult { return new QueryResult(); }
    public function query(string $sql): QueryResult { return new QueryResult(); }
    public function lastInsertId(): string { return '0'; }
}
