<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Attribute;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Attribute\TenantScoped;

/**
 * 'separate_db', 'separate_schema' and 'connection_switch' were accepted and
 * ignored: such a model read and wrote the shared table.
 */
final class TenantScopedStrategyTest extends TestCase
{
    #[Test]
    public function a_strategy_the_orm_does_not_have_is_refused_and_column_is_same_storage(): void
    {
        self::assertSame('same_storage', (new TenantScoped())->strategy);
        self::assertSame('same_storage', (new TenantScoped(strategy: 'column', column: 'tenant_id'))->strategy);

        foreach (['separate_db', 'separate_schema', 'connection_switch', 'typo'] as $strategy) {
            try {
                new TenantScoped(strategy: $strategy);
                self::fail("'{$strategy}' was accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString("strategy: '{$strategy}'", $e->getMessage());
            }
        }
    }
}
