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

    /** A table reference, optionally schema-qualified and quoted; group 1 is the table. */
    private const TABLE = '(?:[`"]?\w+[`"]?\.)?[`"]?(\w+)[`"]?';

    /** SQLite's conflict clause on INSERT / UPDATE. */
    private const CONFLICT = 'OR\s+(?:REPLACE|IGNORE|ABORT|FAIL|ROLLBACK)';

    /**
     * The write statements and where their target sits, modifiers included.
     * Multi-table forms (UPDATE a JOIN b) are judged by the first table.
     */
    private const TARGETS = [
        '/^(?:INSERT|REPLACE)(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE|' . self::CONFLICT . '))*(?:\s+INTO)?\s+' . self::TABLE . '/i',
        '/^UPDATE(?:\s+(?:LOW_PRIORITY|IGNORE|' . self::CONFLICT . '))*\s+' . self::TABLE . '/i',
        '/^DELETE(?:\s+(?:LOW_PRIORITY|QUICK|IGNORE))*\s+FROM\s+' . self::TABLE . '/i',
        '/^TRUNCATE(?:\s+TABLE)?\s+' . self::TABLE . '/i',
    ];

    private const WRITE_VERB = '/^(?:INSERT|REPLACE|UPDATE|DELETE|TRUNCATE)\b/i';

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

        $table = self::writtenTable($sql);
        if ($table === null || Row::asInt(CoroutineLocal::get(self::PERMIT_KEY, 0)) > 0) {
            return;
        }

        throw ReplicatedTableWriteException::for($table, $sql);
    }

    /**
     * The registered table $sql writes, or null. A statement that writes but
     * whose target this parser cannot place (DELETE t FROM …, an unusual
     * dialect form) is judged conservatively: refused when it names any
     * registered table at all.
     */
    private static function writtenTable(string $sql): ?string
    {
        $statement = self::withoutLeadingComments($sql);

        // WITH … UPDATE / DELETE / INSERT: the write follows the CTEs.
        if (preg_match('/^WITH\b/i', $statement) === 1) {
            if (preg_match('/\b(?:INSERT|REPLACE|UPDATE|DELETE)\b/i', $statement, $verb, PREG_OFFSET_CAPTURE) !== 1) {
                return null; // WITH … SELECT
            }
            $statement = substr($statement, $verb[0][1]);
        }

        if (preg_match(self::WRITE_VERB, $statement) !== 1) {
            return null;
        }

        foreach (self::TARGETS as $pattern) {
            if (preg_match($pattern, $statement, $match) === 1) {
                return isset(self::$tables[strtolower($match[1])]) ? $match[1] : null;
            }
        }

        foreach (array_keys(self::$tables) as $table) {
            if (preg_match('/(?<![\w])[`"]?' . preg_quote($table, '/') . '[`"]?(?![\w])/i', $statement) === 1) {
                return $table;
            }
        }

        return null;
    }

    private static function withoutLeadingComments(string $sql): string
    {
        $rest = ltrim($sql);
        while (true) {
            if (str_starts_with($rest, '/*')) {
                $end = strpos($rest, '*/');
                $rest = $end === false ? '' : ltrim(substr($rest, $end + 2));
            } elseif (str_starts_with($rest, '--') || str_starts_with($rest, '#')) {
                $end = strpos($rest, "\n");
                $rest = $end === false ? '' : ltrim(substr($rest, $end + 1));
            } else {
                return $rest;
            }
        }
    }
}
