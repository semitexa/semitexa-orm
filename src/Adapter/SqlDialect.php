<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * What differs by database, asked in one place (tk-orm-dialect-layer).
 *
 * The dialect was decided in some 23 places with `instanceof SqliteAdapter`, and a
 * transaction's SingleConnectionAdapter defeats that check: InsertQuery sent SQLite
 * MySQL's ON DUPLICATE KEY inside a transaction. PostgreSQL is planned, which would
 * make every such check a place to patch. Call sites move onto this interface one PR
 * at a time; each one adds only the answers it needs. Version-gated features stay on
 * DatabaseAdapterInterface::supports().
 */
interface SqlDialect
{
    /** The PDO driver name: 'mysql', 'sqlite'. */
    public function name(): string;

    /** The character this database quotes an identifier with. */
    public function identifierQuote(): string;

    /** Whether connections come from a pool (MySQL) or are one file handle (SQLite). */
    public function usesConnectionPool(): bool;
}
