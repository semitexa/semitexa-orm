<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Sync\Fixture;

use Semitexa\Orm\Adapter\SqliteAdapter;

/**
 * A SQLite adapter that refuses the first DROP of `children`, as a drop run
 * on a connection the foreign-key switch never reached would be.
 */
final class RefuseFirstDropAdapter implements \Semitexa\Orm\Adapter\DatabaseAdapterInterface
{
    /** @var list<string> */
    public array $drops = [];
    private bool $refused = false;
    public function __construct(private readonly SqliteAdapter $inner) {}
    public function execute(string $sql, array $params = []): \Semitexa\Orm\Adapter\QueryResult
    {
        if (str_starts_with($sql, 'DROP TABLE')) {
            $this->drops[] = $sql;
            if (!$this->refused && str_contains($sql, '"children"')) {
                $this->refused = true;
                throw new \RuntimeException('FOREIGN KEY constraint failed');
            }
        }
        return $this->inner->execute($sql, $params);
    }
    public function supports(\Semitexa\Orm\Adapter\ServerCapability $capability): bool
    {
        return $this->inner->supports($capability);
    }
    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }
    public function query(string $sql): \Semitexa\Orm\Adapter\QueryResult
    {
        return $this->inner->query($sql);
    }
    public function lastInsertId(): string
    {
        return $this->inner->lastInsertId();
    }
}
