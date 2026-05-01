<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Domain\Model\ResourceMetadata;

#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::WorkerStartBeforeContainer->value,
    priority: 0,
    requiresContainer: false,
)]
final class ResetMetadataCacheListener implements ServerLifecycleListenerInterface
{
    public function handle(ServerLifecycleContext $context): void
    {
        ResourceModelMetadataRegistry::reset();
        ResourceMetadata::reset();
    }
}
