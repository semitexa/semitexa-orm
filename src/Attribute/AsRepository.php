<?php

declare(strict_types=1);

namespace Semitexa\Orm\Attribute;

use Attribute;

/**
 * Marks a class as a repository service managed by the DI container.
 *
 * This is a semantic alias over the normal worker-scoped service lifecycle,
 * intended for persistence-facing classes.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsRepository
{
}
