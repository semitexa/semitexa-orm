<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * SQLSTATE 23xxx: unique/foreign-key/not-null violation. NOT transient — the
 * data conflicts with the schema, and retrying the same statement can only
 * fail the same way. Callers use this to branch (e.g. "already exists")
 * without string-matching driver messages.
 */
final class ConstraintViolationException extends DatabaseException
{
}
