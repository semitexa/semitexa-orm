<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\ManyToMany;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Domain\Enum\RelationWritePolicy;
use Semitexa\Orm\Domain\Model\RelationState;
use Semitexa\Orm\Exception\InvalidRelationDeclarationException;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;

/**
 * An owned relation is written from its property, so the property must be
 * able to say "not loaded". `array $tags = []` could not: a model read
 * without its tags and saved back deleted every pivot row (the Playground's
 * articles lost their tags on any repository update).
 */
final class OwnedRelationTypingTest extends TestCase
{
    /** @var array<string, mixed> the registry's static state before the test, put back after it */
    private array $registryBefore = [];

    protected function setUp(): void
    {
        foreach (['cache', 'default'] as $name) {
            $this->registryBefore[$name] = (new \ReflectionProperty(ResourceModelMetadataRegistry::class, $name))->getValue();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->registryBefore as $name => $value) {
            (new \ReflectionProperty(ResourceModelMetadataRegistry::class, $name))->setValue(null, $value);
        }
    }

    #[Test]
    public function an_owned_relation_typed_as_a_plain_array_is_refused(): void
    {
        $this->expectException(InvalidRelationDeclarationException::class);
        $this->expectExceptionMessage('must be typed to hold');
        (new ResourceModelMetadataRegistry())->for(PlainArrayOwnedFixture::class);
    }

    #[Test]
    public function a_union_with_relation_state_hydrates_as_not_loaded_and_still_takes_a_list(): void
    {
        (new ResourceModelMetadataRegistry())->for(UnionOwnedFixture::class);

        $hydrated = (new ResourceModelHydrator())->hydrate(['id' => 'a-1'], UnionOwnedFixture::class);
        self::assertInstanceOf(RelationState::class, $hydrated->tags);
        self::assertFalse($hydrated->tags->isLoaded(), 'not loaded is not "none"');

        self::assertSame(['t-1'], (new UnionOwnedFixture('a-2', ['t-1']))->tags, 'a caller still writes a plain list');
    }
}

#[FromTable(name: 'typing_plain')]
final readonly class PlainArrayOwnedFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id = '',
        #[ManyToMany(target: UnionOwnedFixture::class, pivotTable: 'typing_pivot', foreignKey: 'a_id', relatedKey: 'b_id', writePolicy: RelationWritePolicy::SyncPivotOnly)]
        public array $tags = [],
    ) {}
}

#[FromTable(name: 'typing_union')]
final readonly class UnionOwnedFixture
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id = '',
        #[ManyToMany(target: UnionOwnedFixture::class, pivotTable: 'typing_pivot', foreignKey: 'a_id', relatedKey: 'b_id', writePolicy: RelationWritePolicy::SyncPivotOnly)]
        public array|RelationState $tags = [],
    ) {}
}
