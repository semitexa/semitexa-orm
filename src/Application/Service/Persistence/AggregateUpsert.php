<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Persistence;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;
use Semitexa\Orm\Metadata\ColumnMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Query\InsertQuery;

/**
 * What AggregateWriteEngine::upsert() does (orm-gap-1-repository-upsert), kept out of the
 * engine, whose size is a recorded outlier.
 *
 * - The key must be a declared #[Index(unique: true)], with the tenant column added on a
 *   tenant-scoped write, so the row it names can only be this tenant's.
 * - Inside the engine's transaction the row is read by its key under a lock (FOR UPDATE
 *   where the database has it), then UPDATEd by that key, or INSERTed when there is none.
 *   No ON DUPLICATE KEY: MySQL fires it on whichever unique key collides first, the
 *   primary key or an index the model never declared, and updates THAT row, another
 *   tenant's included, while a read-back by the key finds this tenant's row untouched
 *   (Fable's review, orm#110). An UPDATE by the key touches no other row; an INSERT that
 *   collides on another key fails, and the transaction rolls it back. The same SQL on
 *   every database. Two first writes of one key at once: one of them fails, loudly.
 * - The update leaves the primary key, the key, the tenant column and created_at as they
 *   are, and bumps a #[Version], so a stale update() holder is still refused. The
 *   caller's primary key is never written: the key decides the row, the database (or a
 *   fresh uuid) the id.
 * - Refused: owned relations, #[Replicated], #[SoftDelete], a key through the primary
 *   key, a primary key the caller assigns (strategy other than auto or uuid), a null key
 *   value, and an engine with no transaction.
 */
final class AggregateUpsert
{
    /**
     * @param list<string> $keyProperties
     * @return non-empty-list<string> the column names of the unique index the upsert collides on
     */
    public static function conflictColumns(ResourceModelMetadata $metadata, array $keyProperties, ?ColumnMetadata $tenantColumn): array
    {
        $refuse = static fn (string $why): \LogicException => new \LogicException(sprintf('upsert() on %s: %s', $metadata->className, $why));
        if ($keyProperties === []) {
            throw $refuse('name the key it collides on, e.g. upsert($model, Resource::column(\'slug\')).');
        }
        if ($metadata->replicated) {
            throw $refuse('#[Replicated] models are not upserted yet: the capture needs the row as it was.');
        }
        if ($metadata->softDelete !== null) {
            throw $refuse('#[SoftDelete] models are not upserted yet: a soft-deleted row would be revived silently.');
        }
        foreach ($metadata->relations() as $relation) {
            if ($relation->writePolicy !== null && $relation->writePolicy !== RelationWritePolicy::ReferenceOnly) {
                throw $refuse("it owns the relation \${$relation->propertyName}; insert() or update() write owned relations.");
            }
        }

        $strategy = $metadata->primaryKeyProperty !== null ? $metadata->column($metadata->primaryKeyProperty)->primaryKeyStrategy : null;
        if ($strategy !== null && !in_array($strategy, ['auto', 'uuid'], true)) {
            throw $refuse("its primary key is assigned by the caller ({$strategy}); upsert lets the key decide the row, so the id must be the database's or a fresh uuid.");
        }

        $columns = [];
        foreach ($keyProperties as $property) {
            if ($property === $metadata->primaryKeyProperty) {
                throw $refuse('the key is the primary key; insert() or update() say which is meant.');
            }
            $columns[] = $metadata->column($property)->columnName;
        }
        if ($tenantColumn !== null && !in_array($tenantColumn->columnName, $columns, true)) {
            $columns[] = $tenantColumn->columnName;
        }
        $columns = array_values(array_unique($columns));

        $wanted = $columns;
        sort($wanted);
        foreach ($metadata->uniqueIndexes as $index) {
            sort($index);
            if ($index === $wanted) {
                return $columns;
            }
        }

        throw $refuse(sprintf(
            '(%s) is no #[Index(unique: true)] it declares%s; without one the statement would only insert.',
            implode(', ', $columns),
            $tenantColumn !== null ? ' (the tenant column is part of the key on a tenant-scoped write)' : '',
        ));
    }

    /** The read-back throws on a wrong collision, and only a transaction undoes the write (review, orm#110). */
    public static function requireTransaction(ResourceModelMetadata $metadata, ?TransactionManager $transactions): void
    {
        if ($transactions === null) {
            throw new \LogicException(sprintf('upsert() on %s needs a transaction manager; use the repository OrmManager builds.', $metadata->className));
        }
    }

    /**
     * Writes the row and returns it as stored.
     *
     * @param array<string, mixed> $row the dehydrated resource model
     * @param non-empty-list<string> $conflict
     * @return array<string, mixed>
     */
    public static function write(DatabaseAdapterInterface $adapter, ResourceModelMetadata $metadata, array $row, array $conflict): array
    {
        $where = [];
        $keyParams = [];
        foreach ($conflict as $i => $column) {
            if (($row[$column] ?? null) === null) {
                // A unique index lets NULLs repeat, and `= NULL` finds no row (review, orm#110).
                throw new \LogicException(sprintf('upsert() on %s: the key column %s is null; a null key names no row.', $metadata->className, $column));
            }
            $where[] = SqlIdentifier::quote($column) . ' = :__k' . $i;
            $keyParams['__k' . $i] = $row[$column];
        }
        $table = SqlIdentifier::quote($metadata->tableName);
        $byKey = ' WHERE ' . implode(' AND ', $where);
        $select = 'SELECT * FROM ' . $table . $byKey . ' LIMIT 1';
        $existing = $adapter->execute($select . ($adapter->supports(ServerCapability::LockingReads) ? ' FOR UPDATE' : ''), $keyParams)->rows;

        $primaryKey = $metadata->primaryKeyProperty;
        $pkColumn = $primaryKey !== null ? $metadata->column($primaryKey)->columnName : null;
        if ($existing === []) {
            if ($primaryKey !== null && $pkColumn !== null && $metadata->column($primaryKey)->primaryKeyStrategy === 'auto') {
                unset($row[$pkColumn]); // never the caller's id; the read-back returns the database's
            }
            (new InsertQuery($metadata->tableName, $adapter))->execute($row);
        } else {
            $versionColumn = $metadata->versionProperty !== null ? $metadata->column($metadata->versionProperty)->columnName : null;
            $kept = [...$conflict, 'created_at', ...array_filter([$pkColumn, $versionColumn])];
            $set = [];
            $params = $keyParams;
            foreach (array_keys($row) as $i => $column) {
                if (!in_array($column, $kept, true)) {
                    $set[] = SqlIdentifier::quote($column) . ' = :__u' . $i;
                    $params['__u' . $i] = $row[$column];
                }
            }
            if ($versionColumn !== null) {
                $set[] = SqlIdentifier::quote($versionColumn) . ' = ' . SqlIdentifier::quote($versionColumn) . ' + 1';
            }
            if ($set !== []) {
                $adapter->execute('UPDATE ' . $table . ' SET ' . implode(', ', $set) . $byKey, $params);
            }
        }

        $stored = $adapter->execute($select, $keyParams)->rows;
        if ($stored === []) {
            throw new \LogicException(sprintf('upsert() on %s wrote no row under (%s).', $metadata->className, implode(', ', $conflict)));
        }

        return $stored[0];
    }
}
