<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Connection;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Connection\ConnectionRegistry;
use Semitexa\Orm\Tests\Fixture\Metadata\AnalyticsEventTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductTableModel;

require_once __DIR__ . '/../../Fixture/Metadata/ValidProductTableModel.php';
require_once __DIR__ . '/../../Fixture/Metadata/AnalyticsEventTableModel.php';

final class ConnectionRegistryTest extends TestCase
{
    #[Test]
    public function resolves_default_connection_for_model_without_attribute(): void
    {
        $registry = new ConnectionRegistry();

        $this->assertSame('default', $registry->resolveConnectionName(ValidProductTableModel::class));
    }

    #[Test]
    public function resolves_named_connection_from_attribute(): void
    {
        $registry = new ConnectionRegistry();

        $this->assertSame('analytics', $registry->resolveConnectionName(AnalyticsEventTableModel::class));
    }

    #[Test]
    public function caches_resolved_connection_name(): void
    {
        $registry = new ConnectionRegistry();

        $first = $registry->resolveConnectionName(AnalyticsEventTableModel::class);
        $second = $registry->resolveConnectionName(AnalyticsEventTableModel::class);

        $this->assertSame('analytics', $first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function manager_returns_same_instance_for_same_name(): void
    {
        $registry = new ConnectionRegistry();

        $first = $registry->manager('default');
        $second = $registry->manager('default');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function has_returns_false_for_uninitialized_connection(): void
    {
        $registry = new ConnectionRegistry();

        $this->assertFalse($registry->has('analytics'));
    }

    #[Test]
    public function has_returns_true_after_manager_call(): void
    {
        $registry = new ConnectionRegistry();
        $registry->manager('default');

        $this->assertTrue($registry->has('default'));
    }

    #[Test]
    public function shutdown_clears_all_managers(): void
    {
        $registry = new ConnectionRegistry();
        $registry->manager('default');
        $this->assertTrue($registry->has('default'));

        $registry->shutdown();

        $this->assertFalse($registry->has('default'));
        $this->assertSame([], $registry->getInitializedConnections());
    }
}
