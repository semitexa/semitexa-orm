<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service;

use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Repository\DomainRepository;

/**
 * The OrmManager-backed store/repository plumbing, in one place.
 *
 * A hand-written store used to repeat the same ritual: the `orm() ??= new
 * OrmManager()` fallback for callers that construct it outside DI, a memoized
 * DomainRepository per model, a `withOrmManager()` test seam that MUST also drop
 * the memo, and `getMapperRegistry()` threaded into every raw fetch. Forgetting
 * the memo reset silently bound the store to a stale DB in tests; forgetting the
 * fallback fatalled on a bare `new`. Use this trait instead:
 *
 *   #[SatisfiesServiceContract(of: NodeStoreInterface::class)]
 *   final class NodeStore
 *   {
 *       use OrmBackedStore;
 *
 *       #[InjectAsReadonly]           // required — see the note below
 *       protected OrmManager $orm;
 *
 *       public function all(): array
 *       {
 *           return $this->domainRepository(NodeResource::class)->findAll();
 *           // or, on the raw query surface:
 *           // $this->domainRepository(NodeResource::class)->query()
 *           //     ->fetchAllAs(NodeResource::class, $this->mapperRegistry());
 *       }
 *   }
 *
 * NOTE: the consuming class MUST declare the injected property itself —
 *   #[InjectAsReadonly] protected OrmManager $orm;
 * The framework forbids #[InjectAs*] inside a trait (InjectionAnalyzer enforces
 * that injection is declared on the class it targets, so wiring stays visible at
 * the class), so the trait provides only the plumbing around `$this->orm`, not
 * the property. This still removes the bulk of the boilerplate — the fallback,
 * the repository memo, the memo-resetting seam, and the mapper-registry helper.
 */
trait OrmBackedStore
{
    /** @var array<string, DomainRepository> memoized per resource|domain pair. */
    private array $ormBackedRepositories = [];

    /**
     * Test seam: plant an OrmManager and DROP the memoized repositories so they
     * rebind to it. Centralising the memo reset here is the whole point — a
     * hand-rolled `withOrmManager()` that forgot it left tests running against a
     * stale connection.
     */
    public function withOrmManager(OrmManager $orm): static
    {
        $this->orm = $orm;
        $this->ormBackedRepositories = [];

        return $this;
    }

    /**
     * Memoized DomainRepository for a resource model. The domain model defaults
     * to the resource class — the common self-mapping case; pass an explicit
     * domain class for the mapped case.
     *
     * @param class-string      $resourceClass
     * @param class-string|null $domainClass
     */
    protected function domainRepository(string $resourceClass, ?string $domainClass = null): DomainRepository
    {
        $domainClass ??= $resourceClass;

        return $this->ormBackedRepositories[$resourceClass . '|' . $domainClass]
            ??= $this->orm()->repository($resourceClass, $domainClass);
    }

    /** The mapper registry for raw `fetchAllAs()/fetchOneAs()` on `->query()`. */
    protected function mapperRegistry(): MapperRegistry
    {
        return $this->orm()->getMapperRegistry();
    }

    /**
     * The OrmManager — injected via #[InjectAsReadonly] under the container,
     * lazily built for callers that `new` this store outside DI.
     */
    protected function orm(): OrmManager
    {
        return $this->orm ??= new OrmManager();
    }
}
