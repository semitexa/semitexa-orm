<?php

declare(strict_types=1);

namespace Semitexa\Orm\Connection;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Attribute\Connection;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Transaction\TransactionManager;

final class ConnectionRegistry
{
    /** @var array<string, OrmManager> */
    private array $managers = [];

    /** @var array<class-string, string> */
    private array $connectionNameCache = [];

    /**
     * Get or create the OrmManager for a named connection.
     *
     * Lazy-creates on first access; cached for the lifetime of this registry.
     * The 'default' connection reads unprefixed DB_* env vars.
     * Named connections read DB_{NAME}_* env vars (e.g., DB_ANALYTICS_*).
     */
    public function manager(string $name = 'default'): OrmManager
    {
        if (!isset($this->managers[$name])) {
            $config = ConnectionConfig::fromEnvironment($name);
            $this->managers[$name] = new OrmManager(config: $config, connectionName: $name);
        }

        return $this->managers[$name];
    }

    /**
     * Register an externally created OrmManager for a named connection.
     */
    public function register(string $name, OrmManager $manager): void
    {
        $this->managers[$name] = $manager;
    }

    /**
     * Resolve the DatabaseAdapter for a given table model class.
     *
     * Reads the #[Connection] attribute from the class to determine
     * which named connection to use.
     */
    public function adapterFor(string $tableModelClass): DatabaseAdapterInterface
    {
        $connectionName = $this->resolveConnectionName($tableModelClass);

        return $this->manager($connectionName)->getAdapter();
    }

    /**
     * Get the TransactionManager for a named connection.
     */
    public function transactionManagerFor(string $name = 'default'): TransactionManager
    {
        return $this->manager($name)->getTransactionManager();
    }

    /**
     * Create a DomainRepository that automatically uses the correct connection
     * based on the table model's #[Connection] attribute.
     */
    public function repository(string $tableModelClass, string $domainModelClass): DomainRepository
    {
        $connectionName = $this->resolveConnectionName($tableModelClass);
        $manager = $this->manager($connectionName);

        return $manager->repository($tableModelClass, $domainModelClass);
    }

    /**
     * Resolve the connection name for a table model class.
     *
     * Reads the #[Connection('name')] attribute. Returns 'default' if no attribute is present.
     *
     * @param class-string $tableModelClass
     */
    public function resolveConnectionName(string $tableModelClass): string
    {
        if (isset($this->connectionNameCache[$tableModelClass])) {
            return $this->connectionNameCache[$tableModelClass];
        }

        $ref = new \ReflectionClass($tableModelClass);
        $attrs = $ref->getAttributes(Connection::class);

        $name = $attrs !== [] ? $attrs[0]->newInstance()->name : 'default';

        return $this->connectionNameCache[$tableModelClass] = $name;
    }

    /**
     * Check whether a named connection has been initialized.
     */
    public function has(string $name): bool
    {
        return isset($this->managers[$name]);
    }

    /**
     * Get all initialized connection names.
     *
     * @return list<string>
     */
    public function getInitializedConnections(): array
    {
        return array_keys($this->managers);
    }

    /**
     * Shutdown all managed connections and pools.
     */
    public function shutdown(): void
    {
        foreach ($this->managers as $manager) {
            $manager->shutdown();
        }
        $this->managers = [];
    }
}
