<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Queue;

use Semitexa\Core\Environment;
use Semitexa\Core\Queue\DeadLetter;
use Semitexa\Core\Queue\DelayedQueueTransportInterface;
use Semitexa\Core\Queue\QueueInspectorInterface;
use Semitexa\Core\Queue\StoppableQueueTransportInterface;
use Semitexa\Core\Support\Row;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;

/**
 * Broker-less queue transport backed by the 'queue_messages' table — the
 * claim-lease pattern proven by media claimNext, generalized: any package
 * queue (media:work, events) runs without NATS. At-least-once delivery: a
 * crashed consumer's lease expires and the message is re-claimed; a message
 * that keeps failing parks as status='failed' after max_attempts.
 *
 * The adapter is resolved lazily per call so constructing the transport (e.g.
 * during registry initialization) never touches the database.
 */
final class DatabaseQueueTransport implements DelayedQueueTransportInterface, QueueInspectorInterface, StoppableQueueTransportInterface
{
    private bool $running = true;

    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const LEASE_SECONDS = 300;
    private const IDLE_SLEEP_SECONDS = 1.0;
    /** Rows a claim tries before it gives up for this pass: others may take the first. */
    private const CLAIM_CANDIDATES = 5;
    /** A queue that always has work still has its dead workers' rows failed this often. */
    private const EXHAUSTED_SWEEP_SECONDS = 60;

    private int $exhaustedSweptAt = 0;
    private ?string $driver = null;

    /** @var array<string, int> per queue */
    private array $deadLettersPrunedAt = [];
    private const DEAD_LETTER_PRUNE_SECONDS = 3600;

    private ?DatabaseAdapterInterface $adapter = null;

    private ?OrmManager $orm = null;

    private string $consumerId = '';

    public function publish(string $queueName, string $payload): void
    {
        $this->insert($queueName, $payload, 0);
    }

    /**
     * queued_at is when the message may first be claimed: a delayed one is
     * written with it in the future, and the claim passes it by until then.
     */
    public function publishDelayed(string $queueName, string $payload, int $delaySeconds): void
    {
        // queued_at keeps whole seconds and the claim compares whole seconds,
        // so the second is rounded up: a retry may run a little late, never early.
        $this->insert($queueName, $payload, $delaySeconds > 0 ? $delaySeconds + 1 : 0);
    }

    private function insert(string $queueName, string $payload, int $delaySeconds): void
    {
        // Through the caller's transaction when there is one: on its own
        // connection the message outlived the caller's rollback and could be
        // consumed before its commit, and on SQLite the second connection
        // waited out busy_timeout on the caller's write lock (10 s, the worker
        // blocked) and then failed the caller's write.
        $this->orm()->getQueryAdapter()->execute(
            'INSERT INTO queue_messages
                (id, queue_name, payload, status, attempt_count, max_attempts, queued_at)
             VALUES
                (UNHEX(REPLACE(:id, :dash, :empty)), :queue_name, :payload, :status, 0, :max_attempts, ' . $this->sqlNow($delaySeconds) . ')',
            [
                'id' => Uuid7::generate(),
                'dash' => '-',
                'empty' => '',
                'queue_name' => $this->normalizeQueue($queueName),
                'payload' => $payload,
                'status' => 'queued',
                'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
            ],
        );
    }

    public function consume(string $queueName, callable $callback): void
    {
        while ($this->running) {
            if (!$this->consumeOne($queueName, $callback)) {
                $this->idleSleep();
            }
        }
    }

    /**
     * consume() returns after the message in hand (SIGTERM, QueueStopSignals).
     * A stopped transport stays stopped: a signal that came before consume()
     * began must not be forgotten.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Claim and process a single message. Public seam for tests and for
     * drain-style callers that want bounded runs instead of a worker loop.
     *
     * @param callable(string):void $callback
     * @return bool whether a message was claimed
     */
    public function consumeOne(string $queueName, callable $callback): bool
    {
        $queue = $this->normalizeQueue($queueName);
        // The database's clock, not this worker's: a worker whose clock ran
        // ahead took over leases others still held, and ran the job twice.
        $now = $this->sqlNow();

        // Two steps, not one UPDATE over a sub-select: that sub-select is a
        // locking read on MySQL, and a message published inside a caller's
        // still-open transaction (it joins it) held every consumer of the
        // queue until that transaction ended, up to innodb_lock_wait_timeout.
        // A plain SELECT reads committed rows without waiting; the guarded
        // UPDATE then takes one, and only the consumer it changed goes on.
        // The id travels as hex: bound back raw, it is text, which SQLite
        // never finds equal to the stored blob (nothing was ever claimed).
        // A few rounds: other consumers may take every candidate of one.
        for ($round = 0, $taken = 0; $round < 3 && $taken !== 1; $round++) {
            $candidates = $this->adapter()->execute(
                "SELECT HEX(id) AS id FROM queue_messages
                 WHERE queue_name = :queue_name
                   AND ((status = 'queued' AND queued_at <= {$now}) OR (status = 'processing' AND lease_expires_at < {$now}))
                   AND attempt_count < max_attempts
                 ORDER BY queued_at ASC, id ASC
                 LIMIT " . self::CLAIM_CANDIDATES,
                ['queue_name' => $queue],
            )->rows;
            if ($candidates === []) {
                break;
            }
            $taken = $this->claimOneOf($candidates, $now);
        }

        $claimed = $this->adapter()->execute(
            "SELECT HEX(id) AS id, payload, attempt_count, max_attempts FROM queue_messages
             WHERE lease_owner = :owner AND status = 'processing' AND queue_name = :queue_name
             LIMIT 1",
            ['owner' => $this->consumerId(), 'queue_name' => $queue],
        )->rows[0] ?? null;

        if ($claimed === null || time() - $this->exhaustedSweptAt >= self::EXHAUSTED_SWEEP_SECONDS) {
            $this->exhaustedSweptAt = time();
            $this->failExhaustedLeases($queue, $now);
        }
        // On a timer, not only when idle: a queue that is never idle was never
        // pruned. Batches until one comes back short, at most twenty an hour.
        if (time() - ($this->deadLettersPrunedAt[$queue] ?? 0) >= self::DEAD_LETTER_PRUNE_SECONDS) {
            $this->deadLettersPrunedAt[$queue] = time();
            for ($batch = 0; $batch < 20 && $this->pruneDeadLetters($queue) === 500; $batch++) {
            }
        }
        if ($claimed === null) {
            return false;
        }

        $message = Row::of($claimed);

        try {
            $callback($message->string('payload'));
            $this->ack($message->string('id'));
        } catch (\Throwable $e) {
            $this->nack($claimed, $e);
        }

        return true;
    }

    /**
     * The first of $candidates this consumer manages to take: the UPDATE
     * re-checks the row, so of two consumers on one candidate one changes it.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return int 1 when one was taken, 0 when others took them all
     */
    private function claimOneOf(array $candidates, string $now): int
    {
        foreach ($candidates as $candidate) {
            $taken = $this->adapter()->execute(
                "UPDATE queue_messages
                 SET status = 'processing',
                     lease_owner = :owner,
                     lease_expires_at = " . $this->sqlNow(self::LEASE_SECONDS) . ",
                     attempt_count = attempt_count + 1
                 WHERE id = UNHEX(:id)
                   AND ((status = 'queued' AND queued_at <= {$now}) OR (status = 'processing' AND lease_expires_at < {$now}))
                   AND attempt_count < max_attempts",
                [
                    'owner' => $this->consumerId(),
                    'id' => Row::of($candidate)->string('id'),
                ],
            )->rowCount;
            if ($taken === 1) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * A worker that died on the last attempt (out of memory, a fatal error, a
     * kill on recycle) left its row `processing` with no attempt left: the
     * claim passes it by, and it was neither retried nor listed nor deleted.
     * Once its lease runs out it is failed, a dead letter like any other.
     * Done when the queue has nothing to claim, not on every message.
     */
    private function failExhaustedLeases(string $queue, string $now): void
    {
        // By id, as the claim: an UPDATE over a range is a locking scan on
        // MySQL, and it waited on a message still inside a caller's transaction.
        $stuck = $this->adapter()->execute(
            "SELECT HEX(id) AS id FROM queue_messages
             WHERE queue_name = :queue_name AND status = 'processing'
               AND lease_expires_at < {$now} AND attempt_count >= max_attempts
             LIMIT 100",
            ['queue_name' => $queue],
        )->rows;
        foreach ($stuck as $row) {
            $this->adapter()->execute(
                "UPDATE queue_messages
                 SET status = 'failed', lease_owner = NULL, lease_expires_at = NULL,
                     last_error = 'Its lease ran out on the last attempt: the worker stopped in the middle of it.'
                 WHERE id = UNHEX(:id) AND status = 'processing' AND lease_expires_at < {$now} AND attempt_count >= max_attempts",
                ['id' => Row::of($row)->string('id')],
            );
        }
    }

    /**
     * Dead letters of $queue (its `.failed` copies and its own failed rows)
     * older than QUEUE_DEAD_LETTER_RETENTION_DAYS (14, as NATS keeps them; 0
     * keeps them for good): nothing removed them, and the table only grew.
     * Dated by queued_at, the time of the last try. A batch of at most 500.
     *
     * @return int how many were removed
     */
    public function pruneDeadLetters(string $queueName): int
    {
        $days = (int) Environment::getEnvValue('QUEUE_DEAD_LETTER_RETENTION_DAYS', '14');
        if ($days <= 0) {
            return 0;
        }
        $queue = $this->normalizeQueue($queueName);
        $ids = $this->adapter()->execute(
            "SELECT HEX(id) AS id FROM queue_messages
             WHERE (queue_name = :failed_queue OR (queue_name = :queue_name AND status = 'failed')) AND queued_at < " . $this->sqlNow(-$days * 86400) . "
             LIMIT 500",
            ['failed_queue' => $queue . '.failed', 'queue_name' => $queue],
        )->rows;
        $removed = 0;
        foreach ($ids as $row) {
            // The test again: a dead letter retried meanwhile is queued, not failed.
            $removed += $this->adapter()->execute(
                "DELETE FROM queue_messages WHERE id = UNHEX(:id) AND (queue_name = :failed_queue OR (queue_name = :queue_name AND status = 'failed'))",
                ['id' => Row::of($row)->string('id'), 'failed_queue' => $queue . '.failed', 'queue_name' => $queue],
            )->rowCount;
        }

        return $removed;
    }

    /**
     * Only while this consumer still holds the lease: one that ran past it
     * deleted the row another consumer had reclaimed and was working on. A
     * row failed meanwhile because its lease ran out on the last attempt is
     * this job, which did finish: it goes, rather than stay a dead letter
     * that a retry would run a second time.
     */
    private function ack(string $id): void
    {
        $this->adapter()->execute(
            "DELETE FROM queue_messages WHERE id = UNHEX(:id) AND (lease_owner = :owner OR (status = 'failed' AND lease_owner IS NULL))",
            ['id' => $id, 'owner' => $this->consumerId()],
        );
    }

    /**
     * @param array<string, mixed> $claimed
     */
    private function nack(array $claimed, \Throwable $e): void
    {
        $message = Row::of($claimed);
        $exhausted = $message->int('attempt_count') >= $message->int('max_attempts');

        $attempt = max(1, $message->int('attempt_count'));
        // Not straight back: queued_at kept, a throwing callback spent every
        // attempt within a second. 5 s, 10 s, 20 s…, at most the lease. A
        // failed row keeps its queued_at: a dead letter dated in the future
        // read as one not yet due.
        $retryIn = $exhausted ? null : min(self::LEASE_SECONDS, 5 * (2 ** min(10, $attempt - 1)));
        // Only while this consumer still holds the lease: a consumer that ran
        // past it set back to queued a row another consumer was working on.
        $this->adapter()->execute(
            'UPDATE queue_messages
             SET status = :status, lease_owner = NULL, lease_expires_at = NULL, last_error = :last_error, queued_at = ' . ($retryIn === null ? 'queued_at' : $this->sqlNow($retryIn)) . '
             WHERE id = UNHEX(:id) AND (lease_owner = :owner OR (:exhausted_too = 1 AND status = \'failed\' AND lease_owner IS NULL))',
            [
                'owner' => $this->consumerId(),
                // A last attempt that ran past its lease was failed by the
                // sweep meanwhile: its real error replaces "lease ran out".
                'exhausted_too' => $exhausted ? 1 : 0,
                'status' => $exhausted ? 'failed' : 'queued',
                // substr, not mb_substr: byte-accurate for the VARCHAR(512)
                // column and free of an undeclared ext-mbstring dependency.
                'last_error' => substr($e::class . ': ' . $e->getMessage(), 0, 512),
                'id' => $message->string('id'),
            ],
        );
    }

    public function depth(string $queue): int
    {
        $row = $this->adapter()->execute(
            "SELECT COUNT(*) AS n FROM queue_messages WHERE queue_name = :queue_name AND status = 'queued'",
            ['queue_name' => $this->normalizeQueue($queue)],
        )->rows[0] ?? null;

        return $row === null ? 0 : Row::of($row)->int('n');
    }

    public function oldestAgeSeconds(string $queue): ?int
    {
        $row = $this->adapter()->execute(
            // A delayed retry is not waiting yet: its queued_at is in the future.
            "SELECT MIN(queued_at) AS oldest, " . $this->sqlNow() . " AS now FROM queue_messages WHERE queue_name = :queue_name AND status = 'queued' AND queued_at <= " . $this->sqlNow(),
            ['queue_name' => $this->normalizeQueue($queue)],
        )->rows[0] ?? null;
        $oldestAt = $row === null ? '' : Row::of($row)->string('oldest');
        $oldest = $oldestAt === '' ? false : strtotime($oldestAt . ' UTC');
        $now = strtotime(($row === null ? '' : Row::of($row)->string('now')) . ' UTC');

        return $oldest === false || $now === false ? null : max(0, $now - $oldest);
    }

    /**
     * Two kinds: what QueueWorker dead-lettered to `<queue>.failed` after the
     * job's retries, and what this transport gave up on itself (status
     * `failed`: the callback threw max_attempts times — a worker that crashed).
     */
    public function deadLetters(string $queue, int $limit = 20): array
    {
        $queue = $this->normalizeQueue($queue);
        $rows = $this->adapter()->execute(
            "SELECT HEX(id) AS id, queue_name, payload, status, last_error, attempt_count, queued_at FROM queue_messages
             WHERE queue_name = :failed_queue OR (queue_name = :queue_name AND status = 'failed')
             ORDER BY queued_at DESC, id DESC
             LIMIT " . max(1, min(1000, $limit)),
            ['failed_queue' => $queue . '.failed', 'queue_name' => $queue],
        )->rows;

        return array_values(array_map(fn (array $row): DeadLetter => $this->deadLetterOf($queue, Row::of($row)), $rows));
    }

    /**
     * Without a locking read (SQLite has none): the guarded DELETE or UPDATE
     * is what decides, and only the caller whose statement changed the row
     * goes on — two retries of one letter queue it once.
     */
    public function retryDeadLetter(string $queue, string $id): bool
    {
        $queue = $this->normalizeQueue($queue);
        $row = $this->findDeadLetter($this->adapter(), $queue, $id);
        if ($row === null) {
            return false;
        }

        if ($row->string('queue_name') !== $queue . '.failed') {
            // Given up on by the transport: queue it again where it is.
            return $this->adapter()->execute(
                "UPDATE queue_messages SET status = 'queued', attempt_count = 0, last_error = NULL, lease_owner = NULL, lease_expires_at = NULL, queued_at = " . $this->sqlNow() . "
                 WHERE id = UNHEX(:id) AND queue_name = :queue_name AND status = 'failed'",
                ['id' => $row->string('id'), 'queue_name' => $queue],
            )->rowCount === 1;
        }

        $retry = $this->deadLetterOf($queue, $row)->retryPayload();

        return (bool) $this->orm()->getTransactionManager()->run(function (DatabaseAdapterInterface $db) use ($queue, $row, $retry): bool {
            $taken = $db->execute(
                'DELETE FROM queue_messages WHERE id = UNHEX(:id) AND queue_name = :failed_queue',
                ['id' => $row->string('id'), 'failed_queue' => $queue . '.failed'],
            )->rowCount;
            if ($taken !== 1) {
                return false;
            }
            $db->execute(
                'INSERT INTO queue_messages (id, queue_name, payload, status, attempt_count, max_attempts, queued_at)
                 VALUES (UNHEX(REPLACE(:new_id, :dash, :empty)), :queue_name, :payload, :status, 0, :max_attempts, ' . $this->sqlNow() . ')',
                [
                    'new_id' => Uuid7::generate(),
                    'dash' => '-',
                    'empty' => '',
                    'queue_name' => $queue,
                    'payload' => $retry,
                    'status' => 'queued',
                    'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
                ],
            );

            return true;
        });
    }

    /** Deleted only while it is still a dead letter: never a job a concurrent retry just queued. */
    public function forgetDeadLetter(string $queue, string $id): bool
    {
        $queue = $this->normalizeQueue($queue);
        if (preg_match('/^[0-9a-fA-F]{32}$/', $id) !== 1) {
            return false;
        }

        return $this->adapter()->execute(
            "DELETE FROM queue_messages WHERE id = UNHEX(:id) AND (queue_name = :failed_queue OR (queue_name = :queue_name AND status = 'failed'))",
            ['id' => $id, 'failed_queue' => $queue . '.failed', 'queue_name' => $queue],
        )->rowCount === 1;
    }

    /** A dead letter of this queue by its hex id — never a live message, never another queue's. */
    private function findDeadLetter(DatabaseAdapterInterface $db, string $queue, string $id): ?Row
    {
        if (preg_match('/^[0-9a-fA-F]{32}$/', $id) !== 1) {
            return null;
        }
        $row = $db->execute(
            "SELECT HEX(id) AS id, queue_name, payload, status, last_error, attempt_count, queued_at FROM queue_messages
             WHERE id = UNHEX(:id) AND (queue_name = :failed_queue OR (queue_name = :queue_name AND status = 'failed'))",
            ['id' => $id, 'failed_queue' => $queue . '.failed', 'queue_name' => $queue],
        )->rows[0] ?? null;

        return $row === null ? null : Row::of($row);
    }

    private function deadLetterOf(string $queue, Row $row): DeadLetter
    {
        $letter = DeadLetter::fromStored($row->string('id'), $queue, $row->string('payload'));
        if ($row->string('queue_name') === $queue . '.failed') {
            return $letter;
        }
        $queuedAt = $row->string('queued_at');

        return new DeadLetter(
            $letter->id,
            $queue,
            $letter->target,
            $row->int('attempt_count'),
            $row->string('last_error'),
            // Written in UTC (see sqlNow()); read in PHP's zone, a dead letter
            // in Berlin said it failed an hour before it did.
            $queuedAt !== '' ? new \DateTimeImmutable($queuedAt, new \DateTimeZone('UTC')) : null,
            $letter->payload,
            true,
        );
    }

    private function idleSleep(): void
    {
        if (class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::sleep(self::IDLE_SLEEP_SECONDS);
            return;
        }
        usleep((int) (self::IDLE_SLEEP_SECONDS * 1_000_000));
    }

    private function consumerId(): string
    {
        if ($this->consumerId === '') {
            $hostname = gethostname();
            $id = ($hostname !== false ? $hostname : 'unknown-host')
                . ':' . getmypid() . ':' . spl_object_id($this);
            // lease_owner is VARCHAR(128); a silently truncated id would let
            // two consumers collide on claims. Long hostnames (POSIX allows
            // 255 chars) get a deterministic hash-suffixed cap instead.
            if (strlen($id) > 128) {
                // 95 prefix + ':' + 32 hash chars = exactly 128.
                $id = substr($id, 0, 95) . ':' . substr(hash('sha256', $id), 0, 32);
            }
            $this->consumerId = $id;
        }

        return $this->consumerId;
    }

    private function normalizeQueue(string $queue): string
    {
        return strtolower($queue ?: 'default');
    }

    /**
     * "Now" (plus $seconds), as SQL the database evaluates, in whole seconds as
     * queued_at keeps them. UTC both ways: MySQL sessions are pinned to
     * +00:00 (MySqlSessionTimeZone) and SQLite's 'now' is UTC.
     */
    private function sqlNow(int $seconds = 0): string
    {
        if (($this->driver ??= $this->orm()->getDriver()) === 'sqlite') {
            return $seconds === 0 ? "datetime('now')" : sprintf("datetime('now', '%+d seconds')", $seconds);
        }

        return $seconds === 0 ? 'NOW()' : sprintf('(NOW() + INTERVAL %d SECOND)', $seconds);
    }

    private function adapter(): DatabaseAdapterInterface
    {
        return $this->adapter ??= $this->orm()->getAdapter();
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
