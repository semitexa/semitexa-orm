<?php

declare(strict_types=1);

namespace Semitexa\Orm\Domain\Model;

use Semitexa\Orm\Metadata\ColumnMetadata;

/**
 * One resolved constructor parameter in a {@see HydrationPlan}. Built once per
 * class (from reflection + metadata) and reused for every hydrated row, so the
 * per-row path never touches reflection again.
 *
 * `kind` selects the per-row behaviour:
 *   - COLUMN          — read `column`/`columnDef` from the DB row (with the
 *                       resolved nullability/default for a missing column).
 *   - RELATION_STATE  — a `RelationState`-typed relation param → a fresh
 *                       RelationState::notLoaded() per row.
 *   - LITERAL         — a constant value (a relation/param default, or null);
 *                       the value is carried in `literal`.
 */
final class HydrationParameter
{
    public const COLUMN         = 0;
    public const RELATION_STATE = 1;
    public const LITERAL        = 2;

    private function __construct(
        public readonly int $kind,
        public readonly string $name = '',
        public readonly ?ColumnMetadata $column = null,
        public readonly ?ColumnDefinition $columnDef = null,
        public readonly bool $allowsNull = false,
        public readonly bool $hasDefault = false,
        public readonly mixed $literal = null,
    ) {}

    public static function column(
        string $name,
        ColumnMetadata $column,
        ColumnDefinition $columnDef,
        bool $allowsNull,
        bool $hasDefault,
        mixed $default,
    ): self {
        return new self(
            kind: self::COLUMN,
            name: $name,
            column: $column,
            columnDef: $columnDef,
            allowsNull: $allowsNull,
            hasDefault: $hasDefault,
            literal: $default,
        );
    }

    public static function relationState(): self
    {
        return new self(kind: self::RELATION_STATE);
    }

    public static function literal(mixed $value): self
    {
        return new self(kind: self::LITERAL, literal: $value);
    }
}
