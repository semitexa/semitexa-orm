<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Analysis\TraitUsage;

use Semitexa\Orm\Application\Service\OrmBackedStore;
use Semitexa\Orm\OrmManager;

/**
 * Exists so PHPStan analyses OrmBackedStore: a trait nothing in the analysed
 * paths uses is reported as unused and NOT analysed at all. Shaped like the
 * trait's documented consumer, which declares the injected $orm itself.
 */
final class UsesOrmBackedStore
{
    use OrmBackedStore;

    protected OrmManager $orm;
}
