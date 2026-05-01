<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Enum;

enum RelationWritePolicy: string
{
    case ReferenceOnly = 'reference_only';
    case CascadeOwned = 'cascade_owned';
    case SyncPivotOnly = 'sync_pivot_only';
}
