<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

enum MySqlType: string
{
    case Varchar = 'varchar';
    case Text = 'text';
    case Int = 'int';
    case Bigint = 'bigint';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Datetime = 'datetime';
    case Timestamp = 'timestamp';
    case Date = 'date';
    case Json = 'json';
}
