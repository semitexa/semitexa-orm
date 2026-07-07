<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * An optimistically-locked UPDATE matched no row: either a concurrent writer
 * bumped the #[Version] column after this aggregate was read (lost-update
 * prevented), or the row no longer exists. Re-read and retry the operation.
 */
final class StaleAggregateException extends \RuntimeException
{
}
