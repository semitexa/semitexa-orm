<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Contract;

use Semitexa\Orm\Domain\Model\SchemaDiff;


interface SchemaComparatorInterface
{
    /**
     * @param array<string, TableDefinition> $codeSchema
     */
    public function compare(array $codeSchema): SchemaDiff;
}
