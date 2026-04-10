<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * SQLite column types mapped to SQLite type affinities.
 *
 * SQLite uses dynamic typing with five storage classes (NULL, INTEGER,
 * REAL, TEXT, BLOB) and type affinity rules. This enum provides a
 * consistent set of logical types that map to appropriate SQLite
 * type declarations while maintaining compatibility with the ORM's
 * type system.
 *
 * @see https://www.sqlite.org/datatype3.html
 */
enum SqliteType: string implements DatabaseType
{
    case Varchar  = 'varchar';
    case Char     = 'char';
    case Text     = 'text';
    case TinyInt  = 'tinyint';
    case SmallInt = 'smallint';
    case Int      = 'int';
    case Bigint   = 'bigint';
    case Float    = 'float';
    case Double   = 'double';
    case Decimal  = 'decimal';
    case Boolean  = 'boolean';
    case Datetime = 'datetime';
    case Date     = 'date';
    case Time     = 'time';
    case Json     = 'json';
    case Blob     = 'blob';
    case Binary   = 'binary';

    public function toSql(?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        return match ($this) {
            self::Varchar  => 'TEXT',
            self::Char     => 'TEXT',
            self::Text     => 'TEXT',
            self::TinyInt  => 'INTEGER',
            self::SmallInt => 'INTEGER',
            self::Int      => 'INTEGER',
            self::Bigint   => 'INTEGER',
            self::Float    => 'REAL',
            self::Double   => 'REAL',
            self::Decimal  => 'REAL',
            self::Boolean  => 'INTEGER',
            self::Datetime => 'TEXT',
            self::Date     => 'TEXT',
            self::Time     => 'TEXT',
            self::Json     => 'TEXT',
            self::Blob     => 'BLOB',
            self::Binary   => 'BLOB',
        };
    }

    public function canonicalName(?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        return match ($this) {
            self::Varchar  => 'text',
            self::Char     => 'text',
            self::Text     => 'text',
            self::TinyInt  => 'integer',
            self::SmallInt => 'integer',
            self::Int      => 'integer',
            self::Bigint   => 'integer',
            self::Float    => 'real',
            self::Double   => 'real',
            self::Decimal  => 'real',
            self::Boolean  => 'integer',
            self::Datetime => 'text',
            self::Date     => 'text',
            self::Time     => 'text',
            self::Json     => 'text',
            self::Blob     => 'blob',
            self::Binary   => 'blob',
        };
    }
}
