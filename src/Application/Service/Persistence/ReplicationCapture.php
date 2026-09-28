<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Persistence;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Domain\Contract\ReplicationCaptureInterface;
use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;
use Semitexa\Orm\Domain\Model\RowChange;
use Semitexa\Orm\Metadata\ResourceModelMetadata;

/**
 * The write engine's half of replication: for a #[Replicated] root it locks and
 * reads the row before the write, reads it back after, and hands both to the
 * registered capture — all on the write's own transaction.
 *
 * The capture is registered per process (the ledger does it for the server and
 * for console commands), the same way the ORM's default event dispatcher is.
 * With none registered, replicated resources are written like any other.
 */
final class ReplicationCapture
{
    /** @var (\Closure(): ?ReplicationCaptureInterface)|null */
    private static ?\Closure $resolver = null;

    /**
     * Register the capture for this process. Pass null to clear (tests).
     *
     * @param (\Closure(): ?ReplicationCaptureInterface)|null $resolver
     */
    public static function setResolver(?\Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * The registered resolver, so a test that replaces it can put it back.
     *
     * @return (\Closure(): ?ReplicationCaptureInterface)|null
     */
    public static function resolver(): ?\Closure
    {
        return self::$resolver;
    }

    public static function active(ResourceModelMetadata $metadata): ?ReplicationCaptureInterface
    {
        if (!$metadata->replicated || self::$resolver === null) {
            return null;
        }

        return (self::$resolver)();
    }

    /**
     * The row as stored, or null when there is none. $lock takes the row lock
     * that orders concurrent writers of one row — and so their clocks.
     *
     * @return array<string, mixed>|null
     */
    public static function readRow(
        ResourceModelMetadata $metadata,
        string $primaryKeyColumn,
        mixed $primaryKeyValue,
        DatabaseAdapterInterface $adapter,
        bool $lock,
    ): ?array {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :__pk LIMIT 1%s',
            SqlIdentifier::quote($metadata->tableName),
            SqlIdentifier::quote($primaryKeyColumn),
            $lock && $adapter->supports(ServerCapability::LockingReads) ? ' FOR UPDATE' : '',
        );

        return $adapter->execute($sql, ['__pk' => $primaryKeyValue])->rows[0] ?? null;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public static function change(
        ResourceModelMetadata $metadata,
        string $primaryKeyColumn,
        mixed $primaryKeyValue,
        ResourceChangeOperation $operation,
        ?array $before,
        ?array $after,
    ): RowChange {
        // #[PrimaryKey(strategy: 'uuid')] generates a UUIDv7 only for an empty
        // key; a caller can still supply any value. On a replicated row that
        // value names the row on every node, so it must be one no other node
        // can produce: a UUIDv7.
        if (!self::isUuidV7($primaryKeyValue)) {
            throw new \InvalidArgumentException(sprintf(
                '#[Replicated] %s needs a UUIDv7 primary key; got %s. Leave the key empty to have one generated.',
                $metadata->className,
                is_string($primaryKeyValue) && mb_check_encoding($primaryKeyValue, 'UTF-8')
                    ? "'{$primaryKeyValue}'"
                    : get_debug_type($primaryKeyValue),
            ));
        }

        return new RowChange(
            resourceModelClass: $metadata->className,
            tableName: $metadata->tableName,
            primaryKeyColumn: $primaryKeyColumn,
            primaryKeyValue: $primaryKeyValue,
            operation: $operation,
            before: $before,
            after: $after,
        );
    }

    /**
     * A UUIDv7, as text or as its 16 raw bytes (BINARY(16) keys).
     *
     * @phpstan-assert-if-true =string $value
     */
    public static function isUuidV7(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        if (strlen($value) === 16) {
            return (ord($value[6]) >> 4) === 7 && (ord($value[8]) & 0xC0) === 0x80;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
