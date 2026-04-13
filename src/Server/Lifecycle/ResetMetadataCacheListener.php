<?php

declare(strict_types=1);

namespace Semitexa\Orm\Server\Lifecycle;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;

#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartBeforeContainer->value,
    priority: 0,
    requiresContainer: false,
)]
final class ResetMetadataCacheListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        TableModelMetadataRegistry::reset();
    }
}
