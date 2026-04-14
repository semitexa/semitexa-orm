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
     * Resolve the DatabaseAdapter for a given resource model class.
     *
     * Reads the #[Connection] attribute from the class to determine
     * which named connection to use.
     */
    /**
     * @param class-string $resourceModelClass
     */
    public function adapterFor(string $resourceModelClass): DatabaseAdapterInterface
    {
        $connectionName = $this->resolveConnectionName($resourceModelClass);

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
     * based on the resource model's #[Connection] attribute.
     */
    /**
     * @param class-string $resourceModelClass
     * @param class-string $domainModelClass
     */
    public function repository(string $resourceModelClass, string $domainModelClass): DomainRepository
    {
        $connectionName = $this->resolveConnectionName($resourceModelClass);
        $manager = $this->manager($connectionName);

        return $manager->repository($resourceModelClass, $domainModelClass);
    }

    /**
     * Resolve the connection name for a resource model class.
     *
     * Reads the #[Connection('name')] attribute. Returns 'default' if no attribute is present.
     *
     * @param class-string $resourceModelClass
     */
    public function resolveConnectionName(string $resourceModelClass): string
    {
        if (isset($this->connectionNameCache[$resourceModelClass])) {
            return $this->connectionNameCache[$resourceModelClass];
        }

        $ref = new \ReflectionClass($resourceModelClass);
        $attrs = $ref->getAttributes(Connection::class);

        $name = $attrs !== [] ? $attrs[0]->newInstance()->name : 'default';

        return $this->connectionNameCache[$resourceModelClass] = $name;
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
