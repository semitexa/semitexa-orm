<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Analysis\TraitUsage;

use Semitexa\Orm\Metadata\HasCopyWith;

/**
 * Exists so PHPStan analyses HasCopyWith: a trait nothing in the analysed paths
 * uses is reported as unused and NOT analysed at all. Shaped like the readonly
 * resource models that use it, updated_at included.
 */
final readonly class UsesHasCopyWith
{
    use HasCopyWith;

    public function __construct(
        public string $id,
        public \DateTimeImmutable $updated_at,
    ) {}
}
