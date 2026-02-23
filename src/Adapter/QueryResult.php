<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Immutable result of a database query.
 *
 * All data is fetched from PDOStatement BEFORE the connection returns to pool.
 * This prevents coroutine-safety issues where another coroutine could
 * invalidate a PDOStatement by reusing the same PDO connection.
 */
readonly class QueryResult
{
    /**
     * @param array<int, array<string, mixed>> $rows All result rows (FETCH_ASSOC)
     * @param int $rowCount Number of affected rows (for INSERT/UPDATE/DELETE)
     * @param string $lastInsertId Last auto-increment ID from the connection
     */
    public function __construct(
        public array $rows = [],
        public int $rowCount = 0,
        public string $lastInsertId = '0',
    ) {}

    /**
     * Get all rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(): array
    {
        return $this->rows;
    }

    /**
     * Get first row or null.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /**
     * Get a single column value from the first row.
     */
    public function fetchColumn(int $columnIndex = 0): mixed
    {
        $row = $this->rows[0] ?? null;
        if ($row === null) {
            return false;
        }

        $values = array_values($row);
        return $values[$columnIndex] ?? false;
    }
}
