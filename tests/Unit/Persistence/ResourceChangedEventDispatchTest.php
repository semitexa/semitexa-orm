<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Persistence;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Domain\Enum\ResourceChangeOperation;
use Semitexa\Orm\Domain\Event\ResourceChangedEvent;
use Semitexa\Orm\Query\UpdateQuery;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableCategoryDomainModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableProductMapper;
use Semitexa\Orm\Tests\Fixture\Persistence\PersistableReviewDomainModel;

/**
 * Track R · P2 — proves the AggregateWriteEngine dispatches exactly one data-less,
 * scope-keyed ResourceChangedEvent per engine-routed insert/update/delete, that the
 * dispatch is additive (no-op without a dispatcher) and isolated (a throwing
 * listener cannot corrupt the write), and that a GATE-1 bypass path (raw UpdateQuery)
 * emits NO event.
 */
final class ResourceChangedEventDispatchTest extends TestCase
{
    #[Test]
    public function insert_dispatches_one_resource_changed_event_with_scope_key_and_operation(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $dispatcher = $this->capturingDispatcher();
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator(), null, $dispatcher);

        $engine->insert($this->validDomainModel(id: ''), ValidProductResourceModel::class, $this->buildRegistry());

        $this->assertCount(1, $dispatcher->captured);
        $event = $dispatcher->captured[0];
        $this->assertInstanceOf(ResourceChangedEvent::class, $event);
        $this->assertSame('products', $event->resourceKey, 'resourceKey defaults to the table name (P1)');
        $this->assertSame(ResourceChangeOperation::Insert, $event->operation);
    }

    #[Test]
    public function update_dispatches_one_resource_changed_event(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $dispatcher = $this->capturingDispatcher();
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator(), null, $dispatcher);

        $engine->update($this->validDomainModel(), ValidProductResourceModel::class, $this->buildRegistry());

        $this->assertCount(1, $dispatcher->captured);
        $this->assertSame('products', $dispatcher->captured[0]->resourceKey);
        $this->assertSame(ResourceChangeOperation::Update, $dispatcher->captured[0]->operation);
    }

    #[Test]
    public function delete_dispatches_one_resource_changed_event(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $dispatcher = $this->capturingDispatcher();
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator(), null, $dispatcher);

        $engine->delete($this->validDomainModel(), ValidProductResourceModel::class, $this->buildRegistry());

        $this->assertCount(1, $dispatcher->captured);
        $this->assertSame('products', $dispatcher->captured[0]->resourceKey);
        $this->assertSame(ResourceChangeOperation::Delete, $dispatcher->captured[0]->operation);
    }

    #[Test]
    public function engine_writes_correctly_with_no_dispatcher_bound(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        // No dispatcher (default null) — dispatch must be a silent no-op.
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator());

        $persisted = $engine->insert($this->validDomainModel(id: ''), ValidProductResourceModel::class, $this->buildRegistry());

        // The write is unaffected by the absence of a dispatcher (root + 2 reviews).
        $this->assertCount(3, $adapter->executed);
        $this->assertInstanceOf(PersistableProductDomainModel::class, $persisted);
        $this->assertNotSame('', $persisted->id);
    }

    #[Test]
    public function a_throwing_listener_does_not_corrupt_the_write(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $throwing = new class implements EventDispatcherInterface {
            public function create(string $eventClass, array $payload): object
            {
                throw new \RuntimeException('listener factory should not be called');
            }

            public function dispatch(object $event): void
            {
                throw new \RuntimeException('boom: a misbehaving listener throws');
            }

            public function addPostDispatchHook(callable $hook): void {}
        };
        $engine = new AggregateWriteEngine($adapter, new ResourceModelHydrator(), null, $throwing);

        // Must NOT propagate the listener throw — the write already committed.
        $persisted = $engine->insert($this->validDomainModel(id: ''), ValidProductResourceModel::class, $this->buildRegistry());

        $this->assertCount(3, $adapter->executed);
        $this->assertInstanceOf(PersistableProductDomainModel::class, $persisted);
    }

    #[Test]
    public function a_bypass_write_path_raw_update_query_emits_no_event(): void
    {
        // GATE-1 demonstration: raw ResourceModelQuery write paths (UpdateQuery here)
        // reach the adapter WITHOUT the engine, so they dispatch NO ResourceChangedEvent.
        // This is expected and is exactly the gap GATE-1 must audit.
        $adapter = new FakeDatabaseAdapter([]);
        $dispatcher = $this->capturingDispatcher();

        $query = new UpdateQuery('products', $adapter);
        $query->execute(['id' => 'product-1', 'name' => 'Renamed'], 'id');

        $this->assertCount(1, $adapter->executed, 'the raw write reached the DB');
        $this->assertCount(0, $dispatcher->captured, 'but no ResourceChangedEvent was emitted (bypass)');
    }

    /**
     * @return object{captured: list<ResourceChangedEvent>}&EventDispatcherInterface
     */
    private function capturingDispatcher(): EventDispatcherInterface
    {
        return new class implements EventDispatcherInterface {
            /** @var list<ResourceChangedEvent> */
            public array $captured = [];

            public function create(string $eventClass, array $payload): object
            {
                throw new \RuntimeException('create() is not used by the engine dispatch path');
            }

            public function dispatch(object $event): void
            {
                if ($event instanceof ResourceChangedEvent) {
                    $this->captured[] = $event;
                }
            }

            public function addPostDispatchHook(callable $hook): void {}
        };
    }

    private function buildRegistry(): MapperRegistry
    {
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [PersistableProductMapper::class],
            domainModelClasses: [PersistableProductDomainModel::class],
        );

        return $registry;
    }

    private function validDomainModel(string $id = 'product-1'): PersistableProductDomainModel
    {
        return new PersistableProductDomainModel(
            id: $id,
            tenantId: 'tenant-1',
            name: 'Product 1',
            categoryId: 'category-1',
            category: new PersistableCategoryDomainModel(
                id: 'category-1',
                name: 'Category 1',
            ),
            reviews: [
                new PersistableReviewDomainModel(id: 'review-1', productId: 'product-1', rating: 5),
                new PersistableReviewDomainModel(id: 'review-2', productId: 'product-1', rating: 4),
            ],
        );
    }
}
