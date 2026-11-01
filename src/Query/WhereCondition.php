<?php

declare(strict_types=1);

namespace Semitexa\Orm\Query;

use Semitexa\Orm\Adapter\SqlIdentifier;

/**
 * One staged WHERE condition of an UPDATE or DELETE builder (WhereTrait):
 * how it joins the one before it, and the SQL it renders to. One named
 * constructor per kind, each with the fields that kind has, instead of an
 * array whose every key was mixed to the analyser.
 */
final readonly class WhereCondition
{
    /** @param list<string> $placeholders */
    private function __construct(
        public string $connector,
        private string $kind,
        private string $column = '',
        private string $operator = '',
        private string $param = '',
        private array $placeholders = [],
        private string $sql = '',
    ) {}

    /** `column <op> :param` (also LIKE) */
    public static function compare(string $connector, string $column, string $operator, string $param): self
    {
        return new self($connector, 'compare', $column, $operator, $param);
    }

    /** `column IS NULL` / `column IS NOT NULL` */
    public static function null(string $column, string $operator): self
    {
        return new self('AND', 'null', $column, $operator);
    }

    /**
     * `column IN (:a, :b)` / `NOT IN`; an empty list matches nothing (IN) or
     * excludes nothing (NOT IN), the same predicates ResourceModelQuery uses.
     *
     * @param list<string> $placeholders `:name` each
     */
    public static function in(string $column, string $operator, array $placeholders): self
    {
        return new self('AND', 'in', $column, $operator, '', $placeholders);
    }

    public static function between(string $column, string $from, string $to): self
    {
        return new self('AND', 'between', $column, '', '', [$from, $to]);
    }

    /** A fragment taken verbatim, parenthesized so an OR in it cannot bind looser than the conditions ANDed with it. */
    public static function raw(string $sql): self
    {
        return new self('AND', 'raw', sql: $sql);
    }

    public function render(): string
    {
        if ($this->kind === 'raw') {
            return '(' . $this->sql . ')';
        }
        // A qualified column (alias.column) for relation filters, quoted by
        // SqlIdentifier like every builder (VULN-004).
        $col = SqlIdentifier::quoteQualified($this->column);

        return match ($this->kind) {
            'null' => "{$col} {$this->operator}",
            'in' => $this->placeholders === []
                ? ($this->operator === 'IN' ? '1 = 0' : '1 = 1')
                : "{$col} {$this->operator} (" . implode(', ', $this->placeholders) . ')',
            'between' => "{$col} BETWEEN :{$this->placeholders[0]} AND :{$this->placeholders[1]}",
            default => "{$col} {$this->operator} :{$this->param}",
        };
    }
}
