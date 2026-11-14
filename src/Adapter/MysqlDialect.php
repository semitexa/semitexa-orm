<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

final class MysqlDialect implements SqlDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function identifierQuote(): string
    {
        return SqlIdentifier::BACKTICK;
    }

    public function usesConnectionPool(): bool
    {
        return true;
    }
}
