<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Decorator that records every query executed through the wrapped adapter.
 *
 * Usage:
 *   $logging = new LoggingAdapter($adapter);
 *   // ... run queries ...
 *   $log = $logging->getQueryLog(); // QueryLogEntry[]
 *   $logging->clearQueryLog();
 */
class LoggingAdapter implements DatabaseAdapterInterface
{
    /** @var QueryLogEntry[] */
    private array $log = [];

    public function __construct(
        private readonly DatabaseAdapterInterface $inner,
    ) {}

    public function execute(string $sql, array $params = []): QueryResult
    {
        $start  = hrtime(true);
        $result = $this->inner->execute($sql, $params);
        $this->log[] = new QueryLogEntry($sql, $params, $this->elapsedMs($start));

        return $result;
    }

    public function query(string $sql): QueryResult
    {
        $start  = hrtime(true);
        $result = $this->inner->query($sql);
        $this->log[] = new QueryLogEntry($sql, [], $this->elapsedMs($start));

        return $result;
    }

    public function supports(ServerCapability $capability): bool
    {
        return $this->inner->supports($capability);
    }

    public function getServerVersion(): string
    {
        return $this->inner->getServerVersion();
    }

    /** @deprecated */
    public function lastInsertId(): string
    {
        return $this->inner->lastInsertId();
    }

    /**
     * Return all recorded log entries.
     *
     * @return QueryLogEntry[]
     */
    public function getQueryLog(): array
    {
        return $this->log;
    }

    public function clearQueryLog(): void
    {
        $this->log = [];
    }

    private function elapsedMs(int $startNs): float
    {
        return (hrtime(true) - $startNs) / 1_000_000;
    }
}
