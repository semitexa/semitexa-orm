<?php

declare(strict_types=1);

namespace Semitexa\Orm\Exception;

/**
 * A tenant-scoped write did not resolve to a row inside the active tenant.
 *
 * The message intentionally does not distinguish a missing primary key from a
 * row owned by another tenant, avoiding a cross-tenant existence oracle.
 */
final class TenantScopeViolationException extends \RuntimeException
{
    public static function forWrite(string $resourceModelClass): self
    {
        return new self(sprintf(
            'Scoped write target for %s was not found in the active tenant.',
            $resourceModelClass,
        ));
    }
}
