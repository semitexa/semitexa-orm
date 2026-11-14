<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

final class MysqlDialect implements SqlDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function identifierQuote(): string
    {
        return SqlIdentifier::BACKTICK;
    }

    public function usesConnectionPool(): bool
    {
        return true;
    }

    public function upsertsWithOnConflict(): bool
    {
        return false;
    }

    /**
     * UTC_TIMESTAMP(), not NOW(): NOW() is in the session's time zone, and only the
     * adapter's own sessions are pinned to +00:00 (MySqlSessionTimeZone); the contract
     * says UTC for any session (review, orm#115). Queue columns are DATETIME, zone-free.
     */
    public function now(int $seconds = 0): string
    {
        return $seconds === 0 ? 'UTC_TIMESTAMP()' : sprintf('(UTC_TIMESTAMP() + INTERVAL %d SECOND)', $seconds);
    }
}
