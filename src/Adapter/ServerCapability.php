<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

enum ServerCapability: string
{
    case AtomicDdl = 'atomic_ddl';
    case CheckConstraints = 'check';
    case DefaultExpressions = 'default_expr';
    case InvisibleColumns = 'invisible_col';
    case JsonTableFunc = 'json_table';
    case WindowFunctions = 'window_func';
    case DescendingIndexes = 'desc_index';

    /**
     * Minimum MySQL version required for each capability.
     * Single source of truth — used by MysqlAdapter and SingleConnectionAdapter.
     *
     * @return array<string, string>
     */
    public static function minimumVersions(): array
    {
        return [
            self::AtomicDdl->value         => '8.0.0',
            self::CheckConstraints->value   => '8.0.16',
            self::DefaultExpressions->value => '8.0.13',
            self::InvisibleColumns->value   => '8.0.23',
            self::JsonTableFunc->value      => '8.0.4',
            self::WindowFunctions->value    => '8.0.0',
            self::DescendingIndexes->value  => '8.0.0',
        ];
    }

    /**
     * Whether this capability is supported by SQLite.
     *
     * SQLite support varies by version. This reflects capabilities
     * available in SQLite 3.38.0+ (widely available as of 2024).
     */
    public function isSupportedBySqlite(): bool
    {
        return match ($this) {
            self::AtomicDdl => true,          // SQLite has transactional DDL
            self::CheckConstraints => true,   // Supported since early versions
            self::DefaultExpressions => true, // Supported (limited expressions)
            self::InvisibleColumns => false,  // Not supported in SQLite
            self::JsonTableFunc => true,      // json_each() available, json_table() since 3.45.0
            self::WindowFunctions => true,    // Supported since 3.25.0
            self::DescendingIndexes => true,  // Supported (but ignored until 3.30.0, works since)
        };
    }
}
