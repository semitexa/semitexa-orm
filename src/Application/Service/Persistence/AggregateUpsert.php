<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Persistence;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;
use Semitexa\Orm\Metadata\ColumnMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Query\InsertQuery;

/**
 * The statement behind AggregateWriteEngine::upsert() (orm-gap-1-repository-upsert), kept
 * out of the engine, whose size is a recorded outlier.
 *
 * - The key must be a declared #[Index(unique: true)], with the tenant column added on
 *   a tenant-scoped write, so an upsert can never reach another tenant's row; without a
 *   unique index the statement would only ever insert.
 * - One INSERT ... ON DUPLICATE KEY / ON CONFLICT (key) statement, so two writers cannot
 *   both insert. The row is then read back by its key: LAST_INSERT_ID says nothing on
 *   MySQL's update path, and the read-back gives the id, created_at and version the
 *   database holds.
 * - The update leaves the primary key, the key, the tenant column and created_at as they
 *   are, and bumps a #[Version], so a stale update() holder is still refused.
 * - The primary key is never sent: the key decides the row, the database gives the id.
 * - Refused in v1: owned relations, #[Replicated], #[SoftDelete], a key through the
 *   primary key (insert() and update() say what is meant there), a model with another
 *   unique index (MySQL cannot hold the collision to the key), a null key value, and an
 *   engine with no transaction to roll a failed read-back back.
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
        $others = [];
        $matched = false;
        foreach ($metadata->uniqueIndexes as $index) {
            $sorted = $index;
            sort($sorted);
            $sorted === $wanted ? $matched = true : $others[] = $index;
        }
        if ($matched && $others !== []) {
            // MySQL's ON DUPLICATE KEY fires on ANY unique key and cannot be held to one: a row
            // matching another index, another tenant's included, would be updated (review,
            // orm#110). The rule is the same on every database.
            throw $refuse(sprintf(
                'it declares another unique index (%s); a collision on it would update that row instead.',
                implode(', ', $others[0]),
            ));
        }
        if ($matched) {
            return $columns;
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
        foreach ($conflict as $column) {
            if (($row[$column] ?? null) === null) {
                // A unique index lets NULLs repeat: it would insert, and the read-back by
                // `= NULL` could not find the row (review, orm#110).
                throw new \LogicException(sprintf('upsert() on %s: the key column %s is null; a null key never collides.', $metadata->className, $column));
            }
        }
        $primaryKey = $metadata->primaryKeyProperty;
        $pkColumn = $primaryKey !== null ? $metadata->column($primaryKey)->columnName : null;
        if ($primaryKey !== null && $pkColumn !== null && $metadata->column($primaryKey)->primaryKeyStrategy === 'auto') {
            // Never the caller's id: on MySQL a given id collides on the primary key, another
            // tenant's row included (review, orm#110). The key decides the row; the read-back
            // returns its id. (A uuid key is a fresh one, set by the engine.)
            unset($row[$pkColumn]);
        }
        $versionColumn = $metadata->versionProperty !== null ? $metadata->column($metadata->versionProperty)->columnName : null;
        $kept = [...$conflict, 'created_at', ...array_filter([$pkColumn, $versionColumn])];
        $updateColumns = array_values(array_filter(array_keys($row), static fn (string $c): bool => !in_array($c, $kept, true)));
        $bump = $versionColumn === null ? [] : [SqlIdentifier::quote($versionColumn) . ' = ' . SqlIdentifier::quote($versionColumn) . ' + 1'];

        (new InsertQuery($metadata->tableName, $adapter))->executeUpsert($row, $conflict, $updateColumns, $bump);

        $where = [];
        $params = [];
        foreach ($conflict as $i => $column) {
            $where[] = SqlIdentifier::quote($column) . ' = :__k' . $i;
            $params['__k' . $i] = $row[$column] ?? null;
        }
        $stored = $adapter->execute(
            'SELECT * FROM ' . SqlIdentifier::quote($metadata->tableName) . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
            $params,
        )->rows;
        if ($stored === []) {
            // MySQL matched another unique key (the primary key, another index): nothing was
            // written under this key. Thrown inside the caller's transaction, so it rolls back.
            throw new \LogicException(sprintf(
                'upsert() on %s collided on a unique key other than (%s); nothing was written under that key.',
                $metadata->className,
                implode(', ', $conflict),
            ));
        }

        return $stored[0];
    }
}
