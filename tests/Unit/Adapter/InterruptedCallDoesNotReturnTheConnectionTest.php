<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Adapter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\ConnectionPool;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\MysqlAdapter;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
use Swoole\Coroutine;
use Swoole\Coroutine\CanceledException;

/**
 * tk-cancelled-query-returns-dead-mysql-connection: an SSE stream whose browser
 * left is cancelled with Coroutine::cancel($cid, true), which throws
 * CanceledException where the coroutine waits, inside a MySQL call too. Probed
 * on MySQL: the call surfaces CanceledException, not a PDOException, and mysqlnd
 * has closed the connection, so its next use is "2006 MySQL server has gone
 * away". The pool took it back as just used, the idle ping (only after 1 s)
 * skipped it, and the next BEGIN failed: a 500 on a write, reported by a
 * consumer. A connection goes back only when the server answered whole: a
 * result, or an error it sent.
 *
 * No MySQL is needed: the connections are sqlite PDOs whose call throws where a
 * cancel would land.
 */
final class InterruptedCallDoesNotReturnTheConnectionTest extends TestCase
{
    #[Test]
    public function a_statement_cut_off_by_a_cancel_is_dropped_not_pooled(): void
    {
        $after = $this->afterAStatementThat(new CanceledException('cancelled while the reply was in flight'));

        self::assertSame(CanceledException::class, $after['thrown']);
        self::assertSame(1, $after['discards'], 'the connection is dropped');
        self::assertSame(0, $after['available'], 'nothing went back to the pool');
        self::assertSame(0, $after['created'], 'its slot is free for a fresh connection');
    }

    #[Test]
    public function a_raw_query_cut_off_by_a_cancel_is_dropped_not_pooled(): void
    {
        $after = $this->afterAStatementThat(new CanceledException('cancelled while the reply was in flight'), raw: true);

        self::assertSame(CanceledException::class, $after['thrown']);
        self::assertSame(1, $after['discards']);
        self::assertSame(0, $after['available']);
    }

    #[Test]
    public function a_statement_the_server_refused_goes_back_to_the_pool(): void
    {
        $refused = new \PDOException('SQLSTATE[42000]: Syntax error or access violation: 1064');
        $refused->errorInfo = ['42000', 1064, 'You have an error in your SQL syntax'];

        $after = $this->afterAStatementThat($refused);

        self::assertSame(\PDOException::class, $after['thrown']);
        self::assertSame(0, $after['discards'], 'an error reply was read whole: the socket is in step');
        self::assertSame(1, $after['available'], 'so the connection is kept');
    }

    #[Test]
    public function a_begin_cut_off_by_a_cancel_is_dropped_not_pooled(): void
    {
        $after = $this->afterATransactionWhose(beginThrows: new CanceledException('cancelled while BEGIN was in flight'));

        self::assertSame(CanceledException::class, $after['thrown']);
        self::assertSame(1, $after['discards'], 'no transaction was open, so only the interruption says the socket is broken');
        self::assertSame(0, $after['available']);
    }

    #[Test]
    public function a_transaction_whose_work_failed_keeps_its_connection(): void
    {
        $after = $this->afterATransactionWhose(workThrows: new \DomainException('the order is already paid'));

        self::assertSame(\DomainException::class, $after['thrown']);
        self::assertSame(0, $after['discards'], 'the rollback went through: the connection is healthy');
        self::assertSame(1, $after['available']);
    }

    #[Test]
    public function a_ping_cut_off_by_a_cancel_releases_the_slot(): void
    {
        $outcome = ['thrown' => null, 'created' => -1, 'discards' => -1, 'minted' => 0];
        $failure = null;

        Coroutine\run(function () use (&$outcome, &$failure): void {
            try {
                $minted = 0;
                $pool = new ConnectionPool(1, static function () use (&$minted): \PDO {
                    return ++$minted === 1
                        ? self::connectionThatThrows(onQuery: new CanceledException('cancelled while the ping was in flight'))
                        : new \PDO('sqlite::memory:');
                });
                $idle = $pool->pop();
                $pool->push($idle);
                // Idle long enough to be pinged before its next use.
                $idleSince = (new \ReflectionProperty(ConnectionPool::class, 'idleSince'))->getValue($pool);
                if (!$idleSince instanceof \WeakMap) {
                    self::fail('the pool keeps no idle stamps');
                }
                $idleSince[$idle] = microtime(true) - 60.0;

                try {
                    $pool->pop();
                } catch (\Throwable $e) {
                    $outcome['thrown'] = $e::class;
                }
                $stats = $pool->getStats();
                $outcome['created'] = $stats['created'];
                $outcome['discards'] = $stats['discards'];
                // With the slot leaked, this pop() waits out its timeout instead
                // of minting a fresh connection.
                try {
                    $pool->pop(0.5);
                } catch (\Throwable) {
                }
                $outcome['minted'] = $minted;
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });

        if ($failure !== null) {
            throw $failure;
        }
        self::assertSame(CanceledException::class, $outcome['thrown']);
        self::assertSame(0, $outcome['created'], 'the connection is neither lent nor pooled, so its slot is released');
        self::assertSame(1, $outcome['discards']);
        self::assertSame(2, $outcome['minted'], 'the next borrower gets a fresh connection');
    }

    /** @return array{thrown: ?string, discards: int, available: int, created: int} */
    private function afterAStatementThat(\Throwable $failure, bool $raw = false): array
    {
        return $this->inACoroutine(function () use ($failure, $raw): array {
            $pool = new ConnectionPool(1, static fn (): \PDO => $raw
                ? self::connectionThatThrows(onQuery: $failure)
                : self::connectionThatThrows(onPrepare: $failure));
            $adapter = new MysqlAdapter($pool);
            $thrown = null;
            try {
                $raw ? $adapter->query('SELECT SLEEP(2)') : $adapter->execute('SELECT SLEEP(2)');
            } catch (\Throwable $e) {
                $thrown = $e::class;
            }

            return ['thrown' => $thrown] + $this->counts($pool);
        });
    }

    /** @return array{thrown: ?string, discards: int, available: int, created: int} */
    private function afterATransactionWhose(?\Throwable $beginThrows = null, ?\Throwable $workThrows = null): array
    {
        return $this->inACoroutine(function () use ($beginThrows, $workThrows): array {
            $pool = new ConnectionPool(1, static fn (): \PDO => self::connectionThatThrows(onBegin: $beginThrows));
            $adapter = $this->createStub(DatabaseAdapterInterface::class);
            $adapter->method('getServerVersion')->willReturn('8.0.36');
            $transactions = new TransactionManager($pool, $adapter);
            $thrown = null;
            try {
                $transactions->run(static function () use ($workThrows): void {
                    if ($workThrows !== null) {
                        throw $workThrows;
                    }
                });
            } catch (\Throwable $e) {
                $thrown = $e::class;
            }

            return ['thrown' => $thrown] + $this->counts($pool);
        });
    }

    /** @return array{discards: int, available: int, created: int} */
    private function counts(ConnectionPool $pool): array
    {
        $stats = $pool->getStats();

        return ['discards' => $stats['discards'], 'available' => $stats['available'], 'created' => $stats['created']];
    }

    /**
     * @param \Closure(): array{thrown: ?string, discards: int, available: int, created: int} $work
     * @return array{thrown: ?string, discards: int, available: int, created: int}
     */
    private function inACoroutine(\Closure $work): array
    {
        $result = null;
        $failure = null;
        Coroutine\run(static function () use ($work, &$result, &$failure): void {
            try {
                $result = $work();
            } catch (\Throwable $e) {
                $failure = $e;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }
        if (!is_array($result)) {
            self::fail('the coroutine returned no outcome');
        }

        return $result;
    }

    private static function connectionThatThrows(
        ?\Throwable $onPrepare = null,
        ?\Throwable $onQuery = null,
        ?\Throwable $onBegin = null,
    ): \PDO {
        return new class ('sqlite::memory:', $onPrepare, $onQuery, $onBegin) extends \PDO {
            public function __construct(
                string $dsn,
                private readonly ?\Throwable $onPrepare,
                private readonly ?\Throwable $onQuery,
                private readonly ?\Throwable $onBegin,
            ) {
                parent::__construct($dsn);
            }

            /** @param array<int|string, mixed> $options */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if ($this->onPrepare !== null) {
                    throw $this->onPrepare;
                }

                return parent::prepare($query, $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                if ($this->onQuery !== null) {
                    throw $this->onQuery;
                }

                return parent::query($query, $fetchMode, ...$fetchModeArgs);
            }

            public function beginTransaction(): bool
            {
                if ($this->onBegin !== null) {
                    throw $this->onBegin;
                }

                return parent::beginTransaction();
            }
        };
    }
}
