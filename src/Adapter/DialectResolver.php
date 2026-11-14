<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/** The one way to ask an adapter its dialect (tk-orm-dialect-layer). */
final class DialectResolver
{
    public static function for(DatabaseAdapterInterface $adapter): SqlDialect
    {
        // An adapter that does not say is a test double or a third-party adapter; MySQL has
        // been the default every check fell back to.
        return $adapter instanceof DialectAwareInterface ? $adapter->dialect() : new MysqlDialect();
    }

    /** From a PDO driver name, for a wrapper that holds a raw connection. */
    public static function forDriver(string $driver): SqlDialect
    {
        return match ($driver) {
            'sqlite' => new SqliteDialect(),
            default => new MysqlDialect(),
        };
    }
}
