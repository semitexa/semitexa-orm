<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

/**
 * `copyWith()` for a `final readonly` resource model — return a copy with some
 * columns replaced.
 *
 * Every resource used to hand-roll the identical body
 * (`array_merge(get_object_vars($this), $overrides)` plus an `updated_at`
 * refresh). This trait removes that copy-paste. The `updated_at` bump is guarded
 * by {@see array_key_exists}, so a resource WITHOUT that column no longer breaks
 * (the old unconditional `$overrides += ['updated_at' => …]` would pass an
 * unknown constructor argument), and an explicit `updated_at` override still
 * wins.
 */
trait HasCopyWith
{
    /**
     * @param array<string, mixed> $overrides property name => new value
     */
    public function copyWith(array $overrides): static
    {
        $vars = get_object_vars($this);

        if (array_key_exists('updated_at', $vars) && !array_key_exists('updated_at', $overrides)) {
            $overrides['updated_at'] = new \DateTimeImmutable();
        }

        return new static(...array_merge($vars, $overrides));
    }
}
