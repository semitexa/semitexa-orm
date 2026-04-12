<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

enum MySqlType: string implements DatabaseType
{
    case Varchar    = 'varchar';
    case Char       = 'char';
    case Text       = 'text';
    case MediumText = 'mediumtext';
    case LongText   = 'longtext';
    case TinyInt    = 'tinyint';
    case SmallInt   = 'smallint';
    case Int        = 'int';
    case Bigint     = 'bigint';
    case Float      = 'float';
    case Double     = 'double';
    case Decimal    = 'decimal';
    case Boolean    = 'boolean';
    case Datetime   = 'datetime';
    case Timestamp  = 'timestamp';
    case Date       = 'date';
    case Time       = 'time';
    case Year       = 'year';
    case Json       = 'json';
    case Blob       = 'blob';
    case Binary     = 'binary';

    public function toSql(?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        return match ($this) {
            self::Varchar    => 'VARCHAR(' . ($length ?? 255) . ')',
            self::Char       => 'CHAR(' . ($length ?? 1) . ')',
            self::Text       => 'TEXT',
            self::MediumText => 'MEDIUMTEXT',
            self::LongText   => 'LONGTEXT',
            self::TinyInt    => 'TINYINT',
            self::SmallInt   => 'SMALLINT',
            self::Int        => 'INT',
            self::Bigint     => 'BIGINT',
            self::Float      => 'FLOAT',
            self::Double     => 'DOUBLE',
            self::Decimal    => 'DECIMAL(' . ($precision ?? 10) . ',' . ($scale ?? 0) . ')',
            self::Boolean    => 'TINYINT(1)',
            self::Datetime   => 'DATETIME',
            self::Timestamp  => 'TIMESTAMP',
            self::Date       => 'DATE',
            self::Time       => 'TIME',
            self::Year       => 'YEAR',
            self::Json       => 'JSON',
            self::Blob       => 'BLOB',
            self::Binary     => 'BINARY(' . ($length ?? 16) . ')',
        };
    }

    public function canonicalName(?int $length = null, ?int $precision = null, ?int $scale = null): string
    {
        return match ($this) {
            self::Varchar    => 'varchar(' . ($length ?? 255) . ')',
            self::Char       => 'char(' . ($length ?? 1) . ')',
            self::Text       => 'text',
            self::MediumText => 'mediumtext',
            self::LongText   => 'longtext',
            self::TinyInt    => 'tinyint',
            self::SmallInt   => 'smallint',
            self::Int        => 'int',
            self::Bigint     => 'bigint',
            self::Float      => 'float',
            self::Double     => 'double',
            self::Decimal    => 'decimal(' . ($precision ?? 10) . ',' . ($scale ?? 0) . ')',
            self::Boolean    => 'tinyint(1)',
            self::Datetime   => 'datetime',
            self::Timestamp  => 'timestamp',
            self::Date       => 'date',
            self::Time       => 'time',
            self::Year       => 'year',
            self::Json       => 'json',
            self::Blob       => 'blob',
            self::Binary     => 'binary(' . ($length ?? 16) . ')',
        };
    }
}
