<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Persistence;

use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;
use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;
use Semitexa\Orm\Domain\Event\ResourceChangedEvent;
use Semitexa\Orm\Domain\Model\ResourceMetadata;
use Semitexa\Orm\Metadata\ResourceModelMetadata;
use Semitexa\Orm\Query\RawSqlScanner;
use Semitexa\Orm\Query\ResourceModelQuery;

/**
 * Deletes every row a query matches, in one statement, and says how many went
 * ({@see \Semitexa\Orm\Repository\DomainRepository::deleteWhere()}).
 *
 * The rows are the ones the same query would read: its conditions, its tenant
 * scope (fail-closed: a tenant-scoped model with no scope throws before any SQL
 * runs) and its soft-delete filter. A soft-delete model is soft-deleted, with
 * the timestamp the single-row path writes and its version bumped;
 * onlySoftDeleted() purges what is already deleted.
 *
 * Refused, with the reason, whatever a single statement cannot do the way a
 * one-by-one delete() does: cascade or unlink owned relations, capture a
 * #[Replicated] row, or honour a read-only part of the query.
 */
final class BulkResourceDelete
{
    /**
     * @param EventDispatcherInterface|(\Closure(): ?EventDispatcherInterface)|null $events
     * @param TransactionManager|(\Closure(): ?TransactionManager)|null $transactions
     */
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly EventDispatcherInterface|\Closure|null $events = null,
        private readonly TransactionManager|\Closure|null $transactions = null,
    ) {}

    /** @return int the rows deleted (or soft-deleted), as the database reports them */
    public function run(ResourceModelQuery $query): int
    {
        $plan = $query->deletion();
        $metadata = $plan['metadata'];
        $this->refuseWhatOneStatementCannotDo($metadata, $plan);

        $table = SqlIdentifier::quote($metadata->tableName);
        $where = $plan['where'];
        $params = $plan['params'];
        if ($plan['limit'] !== null) {
            // A batch through the primary key, one shape for DELETE and UPDATE on
            // every dialect. The derived table lets MySQL read the table it writes.
            $primaryKey = $metadata->primaryKeyProperty
                ?? throw new \LogicException(sprintf('deleteWhere() with limit() needs a primary key, and %s declares none.', $metadata->className));
            $pk = SqlIdentifier::quote($metadata->column($primaryKey)->columnName);
            // The batch is chosen by a read that locks nothing: a row another
            // transaction moves out of the match meanwhile is still in it. So the
            // write checks the conditions again on the row it locks, under names of
            // their own, since a native prepare refuses a name bound twice.
            [$recheck, $recheckParams] = self::renamed(substr($where, strlen(' WHERE ')), $params, '__outer');
            $where = sprintf(' WHERE %1$s IN (SELECT %1$s FROM (SELECT %1$s FROM %2$s%3$s LIMIT %4$d) AS bulk_batch) AND (%5$s)', $pk, $table, $where, $plan['limit'], $recheck);
            $params += $recheckParams;
        }

        if ($metadata->softDelete !== null && !$plan['onlySoftDeleted']) {
            // A name no query parameter takes: those are tenant_scope and generated ones.
            $params['__bulk_deleted_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $assignments = [sprintf('%s = :__bulk_deleted_at', SqlIdentifier::quote($metadata->softDelete->columnName))];
            if ($metadata->versionProperty !== null) {
                $version = SqlIdentifier::quote($metadata->column($metadata->versionProperty)->columnName);
                $assignments[] = sprintf('%1$s = %1$s + 1', $version);
            }
            $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . $where;
        } else {
            $sql = 'DELETE FROM ' . $table . $where;
        }

        $transactions = $this->transactions instanceof \Closure ? ($this->transactions)() : $this->transactions;
        // On the caller's transaction when there is one: its connection, and its commit.
        $rows = $transactions === null
            ? $this->adapter->execute($sql, $params)->rowCount
            : $transactions->run(static fn (DatabaseAdapterInterface $adapter): int => $adapter->execute($sql, $params)->rowCount);

        if ($rows > 0) {
            $this->signal($metadata->className, $transactions);
        }

        return $rows;
    }

    /**
     * The same conditions under other parameter names: `:name` becomes
     * `:name{$suffix}` wherever it is a parameter, outside quotes and comments.
     *
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function renamed(string $sql, array $params, string $suffix): array
    {
        $out = '';
        $length = strlen($sql);
        $i = 0;
        while ($i < $length) {
            $start = $i;
            if (RawSqlScanner::advancePastQuotedOrCommentRegion($sql, $i, $length)) {
                $out .= substr($sql, $start, $i - $start);
                continue;
            }
            if ($sql[$i] === ':' && preg_match('/\G:([A-Za-z0-9_]+)/', $sql, $m, 0, $i) === 1 && array_key_exists($m[1], $params)) {
                $out .= ':' . $m[1] . $suffix;
                $i += strlen($m[0]);
                continue;
            }
            $out .= $sql[$i];
            $i++;
        }

        $renamed = [];
        foreach ($params as $name => $value) {
            $renamed[$name . $suffix] = $value;
        }

        return [$out, $renamed];
    }

    /** @param array{ownConditions: int, readOnlyParts: list<string>, onlySoftDeleted: bool, includeSoftDeleted: bool} $plan */
    private function refuseWhatOneStatementCannotDo(ResourceModelMetadata $metadata, array $plan): void
    {
        $refuse = static fn (string $why): never => throw new \LogicException(sprintf('deleteWhere() on %s: %s', $metadata->className, $why));

        // The tenant and soft-delete conditions are the scope, not a choice of rows:
        // without one of the caller's own, this would empty the whole scope.
        if ($plan['ownConditions'] === 0) {
            $refuse('the query has no condition of its own. Add where*() for the rows to delete.');
        }
        if ($plan['readOnlyParts'] !== []) {
            $refuse(implode(', ', $plan['readOnlyParts']) . ' cannot shape a delete. limit() can, as a batch size.');
        }
        if ($metadata->replicated) {
            $refuse('a #[Replicated] row is captured one by one. Fetch the rows and delete() each.');
        }
        foreach ($metadata->relations() as $relation) {
            if ($relation->writePolicy !== RelationWritePolicy::ReferenceOnly) {
                $refuse(sprintf('relation %s is written with its owner (%s), which one statement cannot do. Fetch the rows and delete() each.', $relation->propertyName, $relation->writePolicy->value ?? 'none declared'));
            }
        }
        if ($metadata->softDelete === null && ($plan['onlySoftDeleted'] || $plan['includeSoftDeleted'])) {
            // Ignored by a read of such a model, so onlySoftDeleted() would delete the live rows.
            $refuse('the model has no soft delete, so onlySoftDeleted() and includeSoftDeleted() mean nothing here.');
        }
        if ($metadata->softDelete !== null && $plan['includeSoftDeleted']) {
            $refuse('includeSoftDeleted() mixes live rows (soft-deleted) with deleted ones (purged). Use one query for each: a plain one, or onlySoftDeleted().');
        }
    }

    /**
     * One data-less {@see ResourceChangedEvent}, as a single delete() sends: after
     * the caller's commit when inside a transaction. Best-effort, as there: the
     * rows are already gone.
     *
     * @param class-string $resourceModelClass
     */
    private function signal(string $resourceModelClass, ?TransactionManager $transactions): void
    {
        try {
            $dispatcher = $this->events instanceof \Closure ? ($this->events)() : $this->events;
            if ($dispatcher === null) {
                return;
            }
            $event = new ResourceChangedEvent(ResourceMetadata::for($resourceModelClass)->getResourceKey(), ResourceChangeOperation::Delete);
            if ($transactions !== null && $transactions->isActive()) {
                $transactions->setEventDispatcher($dispatcher);
                $transactions->bufferEvent($event);

                return;
            }
            $dispatcher->dispatch($event);
        } catch (\Throwable) {
            // Swallowed as AggregateWriteEngine swallows it: invalidation is lossy-tolerant.
        }
    }
}
