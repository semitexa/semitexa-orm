<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Enum;

/**
 * The kind of write that produced a {@see \Semitexa\Orm\Domain\Event\ResourceChangedEvent}.
 *
 * Scope-only signal vocabulary: never carries row data, only which class of
 * mutation occurred so a downstream invalidation listener can react.
 */
enum ResourceChangeOperation: string
{
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
}
