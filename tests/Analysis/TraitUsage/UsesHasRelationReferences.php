<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Analysis\TraitUsage;

use Semitexa\Orm\Metadata\HasRelationReferences;

/**
 * Exists so PHPStan analyses HasRelationReferences: a trait nothing in the
 * analysed paths uses is reported as unused and NOT analysed at all.
 */
final class UsesHasRelationReferences
{
    use HasRelationReferences;
}
