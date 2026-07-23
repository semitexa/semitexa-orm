<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service\Queue;

use Semitexa\Core\Queue\QueueTransportFactoryInterface;
use Semitexa\Core\Queue\QueueTransportInterface;

/**
 * Discovered by QueueTransportRegistry::registerOptionalDatabaseTransport()
 * via class_exists — installing semitexa/orm makes the 'database' transport
 * available without any core→orm dependency.
 */
final class DatabaseTransportFactory implements QueueTransportFactoryInterface
{
    public function create(): QueueTransportInterface
    {
        return new DatabaseQueueTransport();
    }
}
