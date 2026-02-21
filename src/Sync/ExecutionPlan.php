<?php

declare(strict_types=1);

namespace Semitexa\Orm\Sync;

class ExecutionPlan
{
    /** @var DdlOperation[] */
    private array $operations = [];

    public function addOperation(DdlOperation $operation): void
    {
        $this->operations[] = $operation;
    }

    /**
     * @return DdlOperation[]
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    /**
     * @return DdlOperation[]
     */
    public function getSafeOperations(): array
    {
        return array_values(array_filter(
            $this->operations,
            fn(DdlOperation $op) => !$op->isDestructive,
        ));
    }

    /**
     * @return DdlOperation[]
     */
    public function getDestructiveOperations(): array
    {
        return array_values(array_filter(
            $this->operations,
            fn(DdlOperation $op) => $op->isDestructive,
        ));
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    public function hasDestructiveOperations(): bool
    {
        return $this->getDestructiveOperations() !== [];
    }

    /**
     * @return string[]
     */
    public function toSqlStatements(bool $includeDestructive = false): array
    {
        $statements = [];

        foreach ($this->operations as $op) {
            if ($op->isDestructive && !$includeDestructive) {
                continue;
            }
            $statements[] = $op->sql;
        }

        return $statements;
    }

    public function getSummary(): string
    {
        $safe = count($this->getSafeOperations());
        $destructive = count($this->getDestructiveOperations());
        $lines = [];

        if ($safe > 0) {
            $lines[] = "Safe operations: {$safe}";
        }
        if ($destructive > 0) {
            $lines[] = "Destructive operations: {$destructive} (require --allow-destructive)";
        }
        if ($lines === []) {
            $lines[] = 'No changes needed.';
        }

        return implode("\n", $lines);
    }
}
