<?php

declare(strict_types=1);

namespace Semitexa\Orm\Application\Service;

use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleContext;
use Semitexa\Core\Server\Lifecycle\ServerLifecycleListenerInterface;
use Semitexa\Core\Server\Lifecycle\ServerLifecyclePhase;
use Semitexa\Orm\OrmManager;

/**
 * The same wiring as {@see WireDefaultEventDispatcherListener}, for a CLI process.
 *
 * A command writes rows exactly as a request does, but none of the worker
 * lifecycle runs for it — so AggregateWriteEngine found no dispatcher and its
 * change signal was a silent no-op. One skill announced its write through the
 * web console and said nothing at all from a terminal or the Telegram bot, with
 * no error anywhere to explain the difference. Two classes rather than one
 * because #[AsServerLifecycleListener] is not repeatable.
 */
#[AsServerLifecycleListener(
    phase: ServerLifecyclePhase::ConsoleStartAfterContainer->value,
    priority: 0,
    requiresContainer: true,
)]
final class WireConsoleEventDispatcherListener implements ServerLifecycleListenerInterface
{
    #[InjectAsReadonly]
    protected EventDispatcherInterface $dispatcher;

    public function handle(ServerLifecycleContext $context): void
    {
        if (!isset($this->dispatcher)) {
            return;
        }

        OrmManager::setDefaultEventDispatcherResolver(
            fn (): EventDispatcherInterface => $this->dispatcher,
        );
    }
}
