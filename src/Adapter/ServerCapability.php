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
}
