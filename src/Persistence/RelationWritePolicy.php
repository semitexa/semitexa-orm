<?php

declare(strict_types=1);

namespace Semitexa\Orm\Persistence;

enum RelationWritePolicy: string
{
    case ReferenceOnly = 'reference_only';
    case CascadeOwned = 'cascade_owned';
    case SyncPivotOnly = 'sync_pivot_only';
}
