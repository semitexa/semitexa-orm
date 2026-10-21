<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Queue;

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

    private ?DatabaseAdapterInterface $adapter = null;

    private ?OrmManager $orm = null;

    private string $consumerId = '';

    public function publish(string $queueName, string $payload): void
    {
        $this->insert($queueName, $payload, time());
    }

    /**
     * queued_at is when the message may first be claimed: a delayed one is
     * written with it in the future, and the claim passes it by until then.
     */
    public function publishDelayed(string $queueName, string $payload, int $delaySeconds): void
    {
        // queued_at keeps whole seconds and the claim compares whole seconds,
        // so the second is rounded up: a retry may run a little late, never early.
        $this->insert($queueName, $payload, $delaySeconds > 0 ? time() + $delaySeconds + 1 : time());
    }

    private function insert(string $queueName, string $payload, int $availableAt): void
    {
        $this->adapter()->execute(
            'INSERT INTO queue_messages
                (id, queue_name, payload, status, attempt_count, max_attempts, queued_at)
             VALUES
                (UNHEX(REPLACE(:id, :dash, :empty)), :queue_name, :payload, :status, 0, :max_attempts, :queued_at)',
            [
                'id' => Uuid7::generate(),
                'dash' => '-',
                'empty' => '',
                'queue_name' => $this->normalizeQueue($queueName),
                'payload' => $payload,
                'status' => 'queued',
                'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
                'queued_at' => date('Y-m-d H:i:s', $availableAt),
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
        $now = date('Y-m-d H:i:s');

        $this->adapter()->execute(
            "UPDATE queue_messages
             SET status = 'processing',
                 lease_owner = :owner,
                 lease_expires_at = :lease_until,
                 attempt_count = attempt_count + 1
             WHERE id = (
                 SELECT id FROM (
                     SELECT id FROM queue_messages
                     WHERE queue_name = :queue_name
                       AND ((status = 'queued' AND queued_at <= :now_due) OR (status = 'processing' AND lease_expires_at < :now_stale))
                       AND attempt_count < max_attempts
                     ORDER BY queued_at ASC, id ASC
                     LIMIT 1
                 ) AS sub
             )",
            [
                'owner' => $this->consumerId(),
                'lease_until' => date('Y-m-d H:i:s', time() + self::LEASE_SECONDS),
                'queue_name' => $queue,
                'now_due' => $now,
                'now_stale' => $now,
            ],
        );

        $claimed = $this->adapter()->execute(
            "SELECT id, payload, attempt_count, max_attempts FROM queue_messages
             WHERE lease_owner = :owner AND status = 'processing' AND queue_name = :queue_name
             LIMIT 1",
            ['owner' => $this->consumerId(), 'queue_name' => $queue],
        )->rows[0] ?? null;

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

    private function ack(string $id): void
    {
        $this->adapter()->execute('DELETE FROM queue_messages WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $claimed
     */
    private function nack(array $claimed, \Throwable $e): void
    {
        $message = Row::of($claimed);
        $exhausted = $message->int('attempt_count') >= $message->int('max_attempts');

        $this->adapter()->execute(
            'UPDATE queue_messages
             SET status = :status, lease_owner = NULL, lease_expires_at = NULL, last_error = :last_error
             WHERE id = :id',
            [
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
            "SELECT MIN(queued_at) AS oldest FROM queue_messages WHERE queue_name = :queue_name AND status = 'queued' AND queued_at <= :now",
            ['queue_name' => $this->normalizeQueue($queue), 'now' => date('Y-m-d H:i:s')],
        )->rows[0] ?? null;
        $oldestAt = $row === null ? '' : Row::of($row)->string('oldest');
        $oldest = $oldestAt === '' ? false : strtotime($oldestAt);

        return $oldest === false ? null : max(0, time() - $oldest);
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

        return array_map(fn (array $row): DeadLetter => $this->deadLetterOf($queue, Row::of($row)), $rows);
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
                "UPDATE queue_messages SET status = 'queued', attempt_count = 0, last_error = NULL, lease_owner = NULL, lease_expires_at = NULL, queued_at = :now
                 WHERE id = UNHEX(:id) AND queue_name = :queue_name AND status = 'failed'",
                ['id' => $row->string('id'), 'queue_name' => $queue, 'now' => date('Y-m-d H:i:s')],
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
                 VALUES (UNHEX(REPLACE(:new_id, :dash, :empty)), :queue_name, :payload, :status, 0, :max_attempts, :queued_at)',
                [
                    'new_id' => Uuid7::generate(),
                    'dash' => '-',
                    'empty' => '',
                    'queue_name' => $queue,
                    'payload' => $retry,
                    'status' => 'queued',
                    'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
                    'queued_at' => date('Y-m-d H:i:s'),
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
            $queuedAt !== '' ? new \DateTimeImmutable($queuedAt) : null,
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

    private function adapter(): DatabaseAdapterInterface
    {
        return $this->adapter ??= $this->orm()->getAdapter();
    }

    private function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
