<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Schema;

use Semitexa\Orm\Application\Service\Schema\AbstractSchemaComparator;
use Semitexa\Orm\Domain\Model\ColumnDefinition;
use Semitexa\Orm\Domain\Model\DbColumnState;
use Semitexa\Orm\Domain\Model\DbIndexState;
use Semitexa\Orm\Domain\Model\IndexDefinition;
use Semitexa\Orm\Domain\Model\SchemaDiff;

/**
 * Minimal concrete comparator: supplies the one abstract hook and exposes the
 * protected shared methods, so the base can be exercised without a database.
 */
final class FkAwareComparatorStub extends AbstractSchemaComparator
{
    /**
     * @param array<string, bool> $fkIndexNames
     */
    public function __construct(array $fkIndexNames)
    {
        $this->fkIndexNames = $fkIndexNames;
    }

    /**
     * @param IndexDefinition[] $codeIndexes
     * @param DbIndexState[]    $dbIndexes
     */
    public function runCompareIndexes(string $table, array $codeIndexes, array $dbIndexes, SchemaDiff $diff): void
    {
        $this->compareIndexes($table, $codeIndexes, $dbIndexes, $diff);
    }

    /**
     * @param string[] $columns
     */
    public function runGenerateIndexName(string $table, array $columns, bool $unique): string
    {
        return $this->generateIndexName($table, $columns, $unique);
    }

    /** @param array<string, mixed> $row */
    public static function cell(array $row, string $column): string
    {
        return self::text($row, $column);
    }

    /** @param array<string, mixed> $row */
    public static function cellOrNull(array $row, string $column): ?string
    {
        return self::textOrNull($row, $column);
    }

    public function compare(array $codeSchema): SchemaDiff
    {
        return new SchemaDiff();
    }

    protected function compareColumn(ColumnDefinition $code, DbColumnState $db): array
    {
        return [];
    }
}
