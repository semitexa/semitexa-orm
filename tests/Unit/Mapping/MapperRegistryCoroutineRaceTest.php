<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Mapping;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\OrmManager;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;
use Swoole\Coroutine;

/**
 * Pins the coroutine-safety of OrmManager::getMapperRegistry().
 *
 * MapperRegistry::build() walks the classmap through ClassDiscovery, whose
 * autoloads are file IO — a coroutine SUSPENSION point under SWOOLE_HOOK_ALL.
 * The old implementation memoized the EMPTY registry before build(), so a
 * concurrent coroutine on the same manager could observe a half-built registry
 * and die with MissingMapperException — seen live as intermittent 500s on the
 * first concurrent burst after a worker boot (reproduced at ~1-4 failures per
 * 8-request cold burst).
 *
 * The discovery stub suspends mid-"discovery" (Coroutine::sleep) to force the
 * interleaving deterministically: coroutine A parks inside build(), coroutine B
 * asks the same manager for a mapper. With build-first/memoize-last, B either
 * builds its own complete registry or gets A's finished one — never an empty
 * shell.
 */
final class MapperRegistryCoroutineRaceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
        self::assertSame(-1, Coroutine::getCid(), 'Precondition: test body must run outside a coroutine.');
    }

    #[Test]
    public function concurrent_first_access_never_observes_a_half_built_registry(): void
    {
        $discovery = new class extends ClassDiscovery {
            public function findClassesWithAttribute(string $attributeClass): array
            {
                if ($attributeClass === AsMapper::class) {
                    // The suspension point that build() hits in production via
                    // hooked autoload file IO — forced here deterministically.
                    Coroutine::sleep(0.01);

                    return [HydratableProductMapper::class];
                }

                return [];
            }
        };

        $orm = new OrmManager($discovery);
        $failure = null;
        $resolved = false;

        Coroutine\run(static function () use ($orm, &$failure, &$resolved): void {
            // A: enters getMapperRegistry() first and parks inside build().
            Coroutine::create(static function () use ($orm): void {
                $orm->getMapperRegistry();
            });

            // B: hits the same manager while A is still building.
            Coroutine::create(static function () use ($orm, &$failure, &$resolved): void {
                Coroutine::sleep(0.002); // let A reach its suspension point first

                try {
                    $orm->getMapperRegistry()->definitionFor(
                        HydratableProductResourceModel::class,
                        HydratableProductDomainModel::class,
                    );
                    $resolved = true;
                } catch (\Throwable $e) {
                    $failure = $e;
                }
            });
        });

        self::assertNull(
            $failure,
            'Concurrent getMapperRegistry() observed a half-built registry: '
                . ($failure !== null ? $failure->getMessage() : ''),
        );
        self::assertTrue($resolved, 'The racing coroutine never resolved the mapper definition.');
    }
}
