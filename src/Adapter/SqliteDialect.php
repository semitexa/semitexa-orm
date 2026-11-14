<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

final class SqliteDialect implements SqlDialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    /** The ANSI quote, which SyncEngine already emits on the SQLite path. */
    public function identifierQuote(): string
    {
        return SqlIdentifier::DOUBLE_QUOTE;
    }

    public function usesConnectionPool(): bool
    {
        return false;
    }
}
