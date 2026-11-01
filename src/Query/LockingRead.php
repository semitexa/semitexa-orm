<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\ServerCapability;
use Semitexa\Orm\Application\Service\Transaction\TransactionAwareAdapter;

/**
 * What ResourceModelQuery::forUpdate() asks of the adapter and the SQL: the
 * matching rows stay locked until the transaction the read runs in ends, so a
 * check-then-write (is this night still free? then book it) cannot
 * interleave with another one (orm-gap-3-locking-read).
 *
 * Only inside TransactionManager::run(), on the transaction-aware adapter
 * (repositories and OrmManager::query() get it). Outside a transaction the
 * lock would end with the statement's own autocommit and protect nothing,
 * which is how two guests were once sold the same nights; it is refused at
 * fetch time rather than run as a plain read.
 *
 * On MySQL the read is SELECT … FOR UPDATE. Rows it finds are locked at any
 * isolation level. A probe that finds NOTHING ("no booking for this night
 * yet") is held only by InnoDB's gap lock, which exists under REPEATABLE READ
 * (MySQL's default, and what the ORM leaves in place) and SERIALIZABLE; under
 * READ COMMITTED a concurrent insert of the same key is not blocked, and only
 * a unique key on what may not be written twice stops the second write.
 * SQLite has no row locks and none
 * is added: its transactions serialize writers, so the second of two racing
 * check-then-writes fails to commit instead of writing twice. It applies to
 * fetchAll()/fetchOne()/fetch*As() and exists(); an aggregate or a page of a
 * locking query is refused, and relations loaded with withRelation() are read
 * without a lock.
 */
final class LockingRead
{
    /** " FOR UPDATE" where the server has row locks. */
    public static function clause(DatabaseAdapterInterface $adapter): string
    {
        return $adapter->supports(ServerCapability::LockingReads) ? ' FOR UPDATE' : '';
    }

    public static function assertHolds(DatabaseAdapterInterface $adapter): void
    {
        if (!$adapter instanceof TransactionAwareAdapter) {
            throw new \LogicException('forUpdate() needs the transaction-aware adapter (a repository, OrmManager::query() or getQueryAdapter()): on a pooled adapter the read runs outside the transaction and its lock ends at once.');
        }
        if (!$adapter->inTransaction()) {
            throw new \LogicException('forUpdate() outside TransactionManager::run(): the lock would end with the statement\'s own autocommit and protect nothing. Run the read and the write it guards in one transaction.');
        }
    }

    public static function refuse(string $method): never
    {
        throw new \LogicException("{$method} of a forUpdate() query: a locking read is fetch*() or exists().");
    }
}
