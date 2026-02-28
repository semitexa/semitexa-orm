<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tenant;

use Semitexa\Core\Tenant\TenantContextInterface;

/**
 * Optional extension for TenantScopeInterface implementations that need to
 * inject a tenant identifier column into INSERT/UPDATE data (same-storage isolation).
 *
 * Scopes that isolate tenants at the connection or schema level
 * (ConnectionSwitchStrategy, SeparateSchemaStrategy) do NOT implement this
 * interface because they have no per-row tenant column.
 */
interface ColumnInjectingScope
{
    /**
     * Inject tenant-identifying columns into the dehydrated row data before
     * it is sent to an INSERT or UPDATE query.
     *
     * Implementations should silently no-op when the context carries no tenant
     * (e.g. CLI, tests without a resolved tenant).
     *
     * @param array<string, mixed> $data Dehydrated row — modified in place
     */
    public function injectColumns(array &$data, TenantContextInterface $context): void;
}
