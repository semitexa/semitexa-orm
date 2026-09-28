<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Persistence;

use Semitexa\Core\Attribute\WorkerState;
use Semitexa\Core\Support\CoroutineLocal;
use Semitexa\Core\Support\Row;
use Semitexa\Orm\Exception\ReplicatedTableWriteException;

/**
 * Refuses SQL that writes a #[Replicated] table outside the two paths that
 * keep replication whole: the ORM write engine, which captures every write in
 * its own transaction, and the replication applier (semitexa/ledger), which
 * merges changes from other nodes. Anything else — raw `UPDATE %s`, a query
 * builder, a seed upsert — would change the row on this node and never reach
 * the others.
 *
 * Checked in the adapters that actually run SQL, where the table name is the
 * real one: in the code most writes name their table through a variable, which
 * no static rule can follow.
 *
 * The replicated tables are registered at process start (server and console)
 * from discovery. Nothing registered, nothing refused: the check costs one
 * empty-array test on every statement.
 */
final class ReplicatedWriteGuard
{
    private const PERMIT_KEY = 'orm.replicated_write_permit';

    /**
     * Statements that write, and the table they write first. Multi-table
     * forms (UPDATE a JOIN b, DELETE t FROM …) are judged by that first table.
     */
    private const WRITE = '/^\s*(?:INSERT(?:\s+IGNORE)?(?:\s+INTO)?|REPLACE(?:\s+INTO)?|UPDATE(?:\s+IGNORE)?|DELETE(?:\s+IGNORE)?\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+(?:[`"]?\w+[`"]?\.)?[`"]?(\w+)[`"]?/i';

    /** @var array<string, true> lower-cased table name => true */
    #[WorkerState('The #[Replicated] tables of this process, registered once at start from discovery; never request data.')]
    private static array $tables = [];

    public static function register(string $table): void
    {
        self::$tables[strtolower($table)] = true;
    }

    /** @return list<string> */
    public static function registered(): array
    {
        return array_keys(self::$tables);
    }

    /** Forget every registration (tests). */
    public static function reset(): void
    {
        self::$tables = [];
    }

    /**
     * Run $write with replicated tables writable — for the write engine and the
     * replication applier only. Per coroutine, and nestable.
     *
     * @template T
     * @param callable(): T $write
     * @return T
     */
    public static function permit(callable $write): mixed
    {
        $depth = Row::asInt(CoroutineLocal::get(self::PERMIT_KEY, 0));
        CoroutineLocal::set(self::PERMIT_KEY, $depth + 1);
        try {
            return $write();
        } finally {
            CoroutineLocal::set(self::PERMIT_KEY, $depth);
        }
    }

    /**
     * @throws ReplicatedTableWriteException when $sql writes a replicated table outside permit()
     */
    public static function check(string $sql): void
    {
        if (self::$tables === []) {
            return;
        }
        if (preg_match(self::WRITE, $sql, $match) !== 1 || !isset(self::$tables[strtolower($match[1])])) {
            return;
        }
        if (Row::asInt(CoroutineLocal::get(self::PERMIT_KEY, 0)) > 0) {
            return;
        }

        throw ReplicatedTableWriteException::for($match[1], $sql);
    }
}
