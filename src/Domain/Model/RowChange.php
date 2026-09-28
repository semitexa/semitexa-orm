<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Model;

use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;

/**
 * One write to a #[Replicated] row, as the database holds it: the row before
 * the write (read under the row lock) and after it (read back inside the same
 * transaction, so defaults, version bumps and the tenant column are exact).
 */
final readonly class RowChange
{
    /**
     * @param class-string              $resourceModelClass
     * @param array<string, mixed>|null $before column => value; null on insert
     * @param array<string, mixed>|null $after  column => value; null on hard delete
     */
    public function __construct(
        public string $resourceModelClass,
        public string $tableName,
        public string $primaryKeyColumn,
        public string $primaryKeyValue,
        public ResourceChangeOperation $operation,
        public ?array $before,
        public ?array $after,
    ) {}
}
