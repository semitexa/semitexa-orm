<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

/** @api */
enum Direction: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
