<?php

declare(strict_types=1);

namespace Semitexa\Orm\Hydration;

enum RelationType: string
{
    case BelongsTo   = 'belongs_to';
    case HasMany     = 'has_many';
    case OneToOne    = 'one_to_one';
    case ManyToMany  = 'many_to_many';
}
