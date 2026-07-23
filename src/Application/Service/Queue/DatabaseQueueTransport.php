<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Queue;

use Semitexa\Core\Queue\QueueTransportInterface;
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
final class DatabaseQueueTransport implements QueueTransportInterface
{
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const LEASE_SECONDS = 300;
    private const IDLE_SLEEP_SECONDS = 1.0;

    private ?DatabaseAdapterInterface $adapter = null;

    private string $consumerId = '';

    public function publish(string $queueName, string $payload): void
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
                'queued_at' => date('Y-m-d H:i:s'),
            ],
        );
    }

    public function consume(string $queueName, callable $callback): void
    {
        while (true) { // @phpstan-ignore while.alwaysTrue (worker loop by design, like InMemoryTransport)
            if (!$this->consumeOne($queueName, $callback)) {
                $this->idleSleep();
            }
        }
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
                       AND (status = 'queued' OR (status = 'processing' AND lease_expires_at < :now_stale))
                       AND attempt_count < max_attempts
                     ORDER BY queued_at ASC, id ASC
                     LIMIT 1
                 ) AS sub
             )",
            [
                'owner' => $this->consumerId(),
                'lease_until' => date('Y-m-d H:i:s', time() + self::LEASE_SECONDS),
                'queue_name' => $queue,
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

        try {
            $callback((string) $claimed['payload']);
            $this->ack((string) $claimed['id']);
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
        $exhausted = (int) $claimed['attempt_count'] >= (int) $claimed['max_attempts'];

        $this->adapter()->execute(
            'UPDATE queue_messages
             SET status = :status, lease_owner = NULL, lease_expires_at = NULL, last_error = :last_error
             WHERE id = :id',
            [
                'status' => $exhausted ? 'failed' : 'queued',
                // substr, not mb_substr: byte-accurate for the VARCHAR(512)
                // column and free of an undeclared ext-mbstring dependency.
                'last_error' => substr($e::class . ': ' . $e->getMessage(), 0, 512),
                'id' => (string) $claimed['id'],
            ],
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
        return $this->adapter ??= new OrmManager()->getAdapter();
    }
}
