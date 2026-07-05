<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Metadata\HasCopyWith;

final class HasCopyWithTest extends TestCase
{
    #[Test]
    public function it_applies_overrides_and_refreshes_updated_at(): void
    {
        $orig = new CopyableWithTimestamp('a', 'Alpha', new \DateTimeImmutable('2020-01-01'));
        $copy = $orig->copyWith(['name' => 'Beta']);

        self::assertSame('a', $copy->id, 'untouched columns are carried over');
        self::assertSame('Beta', $copy->name, 'the override is applied');
        self::assertGreaterThan($orig->updated_at, $copy->updated_at, 'updated_at is refreshed');
    }

    #[Test]
    public function an_explicit_updated_at_override_wins(): void
    {
        $ts = new \DateTimeImmutable('2030-06-15 12:00:00');
        $copy = (new CopyableWithTimestamp('a', 'Alpha', new \DateTimeImmutable('2020-01-01')))
            ->copyWith(['updated_at' => $ts]);

        self::assertSame($ts, $copy->updated_at, 'an explicit updated_at is not overwritten');
    }

    #[Test]
    public function a_resource_without_updated_at_is_safe(): void
    {
        // The old unconditional `$overrides += ['updated_at' => …]` would pass an
        // unknown constructor argument here; the array_key_exists guard makes it
        // safe.
        $copy = (new CopyableWithoutTimestamp('a', 'Alpha'))->copyWith(['name' => 'Gamma']);

        self::assertSame('a', $copy->id);
        self::assertSame('Gamma', $copy->name);
    }
}

final readonly class CopyableWithTimestamp
{
    use HasCopyWith;

    public function __construct(
        public string $id,
        public string $name,
        public \DateTimeImmutable $updated_at,
    ) {}
}

final readonly class CopyableWithoutTimestamp
{
    use HasCopyWith;

    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
