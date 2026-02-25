<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

enum ForeignKeyAction: string
{
    case Restrict  = 'RESTRICT';
    case Cascade   = 'CASCADE';
    case SetNull   = 'SET NULL';
    case NoAction  = 'NO ACTION';
}
