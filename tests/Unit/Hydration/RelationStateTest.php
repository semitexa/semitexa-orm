<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Hydration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Hydration\RelationState;

final class RelationStateTest extends TestCase
{
    #[Test]
    public function not_loaded_state_is_explicit(): void
    {
        $state = RelationState::notLoaded();

        $this->assertFalse($state->isLoaded());
        $this->assertTrue($state->isNotLoaded());
        $this->assertNull($state->valueOrNull());
    }

    #[Test]
    public function loaded_scalar_value_is_accessible(): void
    {
        $state = RelationState::loaded('value');

        $this->assertTrue($state->isLoaded());
        $this->assertSame('value', $state->value());
        $this->assertSame('value', $state->valueOrNull());
    }

    #[Test]
    public function loaded_empty_collection_is_distinct_from_not_loaded(): void
    {
        $state = RelationState::loadedEmptyCollection();

        $this->assertTrue($state->isLoaded());
        $this->assertSame([], $state->value());
        $this->assertSame([], $state->valueOrNull());
    }

    #[Test]
    public function loaded_null_is_distinct_from_not_loaded(): void
    {
        $state = RelationState::loadedNull();

        $this->assertTrue($state->isLoaded());
        $this->assertNull($state->value());
        $this->assertNull($state->valueOrNull());
    }

    #[Test]
    public function reading_value_from_not_loaded_relation_fails_loudly(): void
    {
        $state = RelationState::notLoaded();

        $this->expectException(\LogicException::class);

        $state->value();
    }
}
