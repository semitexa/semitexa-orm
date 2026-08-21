<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * pop() waited its full timeout and no connection was returned. Extends the
 * bare \RuntimeException the pool used to throw (via DatabaseException), so
 * existing catch sites keep working while new callers can react specifically
 * — typically by shedding load, not by retrying into the same empty pool.
 */
final class PoolExhaustedException extends DatabaseException
{
}
