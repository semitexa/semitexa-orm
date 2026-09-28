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
        return new RowChange(
            resourceModelClass: $metadata->className,
            tableName: $metadata->tableName,
            primaryKeyColumn: $primaryKeyColumn,
            primaryKeyValue: (string) $primaryKeyValue,
            operation: $operation,
            before: $before,
            after: $after,
        );
    }
}
