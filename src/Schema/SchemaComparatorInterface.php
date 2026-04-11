<?php

declare(strict_types=1);

namespace Semitexa\Orm\Schema;

interface SchemaComparatorInterface
{
    /**
     * @param array<string, TableDefinition> $codeSchema
     */
    public function compare(array $codeSchema): SchemaDiff;
}
