<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\SqlIdentifier;
use Semitexa\Orm\Metadata\ResourceModelMetadata;

/**
 * Authority for one read, carried through every eager-loading level.
 * Keep it on the call stack: a relation loader can serve several coroutines.
 */
final readonly class TenantReadScope
{
    private function __construct(
        private mixed $tenantValue,
        private bool $bypass,
    ) {}

    public static function from(mixed $tenantValue, ?SystemScopeToken $systemScopeToken): self
    {
        return new self($tenantValue, $systemScopeToken !== null);
    }

    /**
     * Use the target's metadata: its tenant column need not match the root's.
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    public function conditionFor(ResourceModelMetadata $metadata): array
    {
        if ($metadata->tenantPolicy === null || $this->bypass) {
            return [null, []];
        }

        if ($this->tenantValue === null) {
            throw new \LogicException(sprintf(
                'Query for tenant-scoped resource model %s requires tenant context. Call forTenant() or withoutTenantScope().',
                $metadata->className,
            ));
        }

        $column = $metadata->tenantColumn();
        if ($column === null) {
            throw new \LogicException(sprintf('Tenant metadata is missing for %s.', $metadata->className));
        }

        return [
            sprintf('%s = :tenant_scope', SqlIdentifier::quote($column->columnName)),
            ['tenant_scope' => $this->tenantValue],
        ];
    }
}
