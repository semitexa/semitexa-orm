<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

enum MySqlType: string
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
}
