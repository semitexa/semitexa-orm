<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\OrmBackedStore;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;

/**
 * OrmBackedStore centralises the store plumbing a hand-written store used to
 * copy-paste. The two behaviours that matter (and that hand-rolled stores got
 * wrong) are pinned here: the DomainRepository is memoized per model, and
 * withOrmManager() DROPS that memo so a re-planted OrmManager can't leave the
 * store bound to a stale repository/connection (the silent-stale-DB test bug).
 */
final class OrmBackedStoreTest extends TestCase
{
    #[Test]
    public function the_domain_repository_is_memoized_per_model(): void
    {
        $store = (new OrmBackedFixtureStore())->withOrmManager($this->sqliteOrm());

        $a = $store->repositoryFor(HydratableProductResourceModel::class);
        $b = $store->repositoryFor(HydratableProductResourceModel::class);

        self::assertInstanceOf(DomainRepository::class, $a);
        self::assertSame($a, $b, 'the repository must be built once and memoized');
    }

    #[Test]
    public function with_orm_manager_drops_the_memo_so_the_repo_rebinds(): void
    {
        $store = new OrmBackedFixtureStore();
        $store->withOrmManager($this->sqliteOrm());
        $first = $store->repositoryFor(HydratableProductResourceModel::class);

        $store->withOrmManager($this->sqliteOrm()); // a fresh manager
        $second = $store->repositoryFor(HydratableProductResourceModel::class);

        self::assertNotSame(
            $first,
            $second,
            'withOrmManager() must drop the memo — the stale-repo footgun this trait removes',
        );
    }

    #[Test]
    public function orm_lazily_builds_a_manager_for_callers_outside_di(): void
    {
        // A store `new`'d without DI (no injected $orm) still works — orm() falls
        // back to a fresh OrmManager instead of an uninitialized-property fatal.
        $store = new OrmBackedFixtureStore();

        self::assertInstanceOf(OrmManager::class, $store->exposedOrm());
    }

    private function sqliteOrm(): OrmManager
    {
        return new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
    }
}

final class OrmBackedFixtureStore
{
    use OrmBackedStore;

    // The consuming class declares the injected property (the trait cannot).
    protected OrmManager $orm;

    /** @param class-string $class */
    public function repositoryFor(string $class): DomainRepository
    {
        return $this->domainRepository($class);
    }

    public function exposedOrm(): OrmManager
    {
        return $this->orm();
    }
}
