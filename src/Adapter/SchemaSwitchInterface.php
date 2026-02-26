<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Switches the active schema (database) on the current connection.
 * Used by SeparateSchemaStrategy for tenant isolation within one server.
 */
interface SchemaSwitchInterface
{
    public function useSchema(string $schema): void;
}
