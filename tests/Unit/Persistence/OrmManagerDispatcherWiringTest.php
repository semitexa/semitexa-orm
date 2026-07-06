<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * Track R · Dispatcher Wiring (origin-half) — proves the GATE-1 "partial origin" trap
 * is closed: EVERY default production path to an OrmManager carries the dispatcher.
 *
 * The framework registers a lazy default resolver once at bootstrap
 * (ContainerFactory::setDefaultEventDispatcherResolver). This test stands in for that
 * bootstrap by setting the same seam to a capturing dispatcher, then proves both default
 * construction paths — the explicit ConnectionRegistry::manager() instance AND the bare
 * `new OrmManager()` repository fallback — resolve a non-null dispatcher, that the
 * resolved dispatcher is actually threaded into the write engine (so a real write emits),
 * that an explicitly injected dispatcher still wins (no double-wire), and that absent the
 * resolver dispatch stays the silent no-op it was before this brick.
 */
final class OrmManagerDispatcherWiringTest extends TestCase
{
    protected function tearDown(): void
    {
        // The resolver is process-global static state — never leak it to other tests.
        OrmManager::setDefaultEventDispatcherResolver(null);
        parent::tearDown();
    }

    #[Test]
    public function the_explicit_connection_registry_default_path_carries_the_dispatcher(): void
    {
        $captured = $this->capturingDispatcher();
        OrmManager::setDefaultEventDispatcherResolver(static fn (): EventDispatcherInterface => $captured);

        $manager = (new ConnectionRegistry())->manager('default');

        $this->assertSame(
            $captured,
            $manager->getEventDispatcher(),
            'ConnectionRegistry::manager() — the explicit default path GATE-1 cited — must carry the dispatcher',
        );
    }

    #[Test]
    public function the_bare_new_orm_manager_repository_fallback_carries_the_dispatcher(): void
    {
        $captured = $this->capturingDispatcher();
        OrmManager::setDefaultEventDispatcherResolver(static fn (): EventDispatcherInterface => $captured);

        // This is exactly the `$this->orm ??= new OrmManager()` lazy fallback GATE-1 flagged
        // (mail/media/scheduler/workflow repositories). It must NOT be a partial-origin hole.
        $this->assertSame(
            $captured,
            (new OrmManager())->getEventDispatcher(),
            'the bare new OrmManager() fallback must also carry the dispatcher',
        );
    }

    #[Test]
    public function the_resolved_dispatcher_is_threaded_into_the_write_engine(): void
    {
        $captured = $this->capturingDispatcher();
        OrmManager::setDefaultEventDispatcherResolver(static fn (): EventDispatcherInterface => $captured);

        // sqlite-memory config builds a real (server-less) adapter so the engine can be
        // constructed without a DB server — we only assert what dispatcher reached it.
        $manager = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        $engine = $manager->getAggregateWriteEngine();

        $events = (new \ReflectionProperty(AggregateWriteEngine::class, 'events'))->getValue($engine);
        // The engine holds a LAZY resolver on purpose: it is memoized, and a
        // dispatcher captured at construction would freeze pre-bootstrap null
        // in CLI workers (the scheduler:work auto-publish regression). The
        // wiring contract is therefore: resolving the engine's closure yields
        // the same dispatcher the OrmManager resolves.
        $this->assertInstanceOf(\Closure::class, $events, 'the engine must carry the lazy resolver, not a captured dispatcher');
        $this->assertSame(
            $captured,
            $events(),
            'the dispatcher the OrmManager resolves must be the one the AggregateWriteEngine emits through',
        );
    }

    #[Test]
    public function an_explicitly_injected_dispatcher_wins_over_the_resolver(): void
    {
        $resolverDispatcher = $this->capturingDispatcher();
        $explicit = $this->capturingDispatcher();
        OrmManager::setDefaultEventDispatcherResolver(static fn (): EventDispatcherInterface => $resolverDispatcher);

        // P2's ctor param / direct tests still take precedence — guards against double-wiring.
        $manager = new OrmManager(events: $explicit);

        $this->assertSame($explicit, $manager->getEventDispatcher());
        $this->assertNotSame($resolverDispatcher, $manager->getEventDispatcher());
    }

    #[Test]
    public function without_a_resolver_default_paths_stay_a_silent_no_op(): void
    {
        OrmManager::setDefaultEventDispatcherResolver(null);

        // No bootstrapped container (pure unit context) → dispatch is null, exactly as before.
        $this->assertNull((new OrmManager())->getEventDispatcher());
        $this->assertNull((new ConnectionRegistry())->manager('default')->getEventDispatcher());
    }

    private function capturingDispatcher(): EventDispatcherInterface
    {
        return new class implements EventDispatcherInterface {
            /** @var list<object> */
            public array $captured = [];

            public function create(string $eventClass, array $payload): object
            {
                throw new \RuntimeException('create() is not used by the engine dispatch path');
            }

            public function dispatch(object $event): void
            {
                $this->captured[] = $event;
            }

            public function addPostDispatchHook(callable $hook): void {}
        };
    }
}
