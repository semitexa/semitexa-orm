<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

enum Operator: string
{
    case Equals = '=';
    case NotEquals = '!=';
    case GreaterThan = '>';
    case GreaterThanOrEquals = '>=';
    case LessThan = '<';
    case LessThanOrEquals = '<=';
    case Like = 'LIKE';
    case NotLike = 'NOT LIKE';
}
