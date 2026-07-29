<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LoggerInterface;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Repository\DomainRepository;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductDomainModel;
use Semitexa\Orm\Tests\Fixture\Mapping\HydratableProductMapper;

/**
 * A result cut at the default bound must say so.
 *
 * `findBy()` and `findAll()` cap at {@see DomainRepository::DEFAULT_LIMIT} so an
 * unsuspecting caller cannot pull a whole table into memory. That bound is
 * right. What was wrong is that it applied in silence: a truncated list is
 * indistinguishable from a complete one by looking at it, so it does not
 * surface as an error — it surfaces as wrong data on a screen. A consumer
 * project lost correctness on five screens this way.
 *
 * The warning is deliberately narrow. It fires only when the caller never chose
 * a limit, because a caller who passes one knows it exists and expects to
 * receive exactly that many. Warning a paginated screen that asked for 50 and
 * got 50 would be noise, and noise is how a warning ends up ignored.
 */
final class SilentTruncationTest extends TestCase
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    protected function setUp(): void
    {
        $this->records = [];
        $records = &$this->records;

        StaticLoggerBridge::set(new class ($records) implements LoggerInterface {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            /** @param array<string, mixed> $context */
            private function record(string $level, string $message, array $context): void
            {
                $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }

            public function error(string $message, array $context = []): void
            {
                $this->record('error', $message, $context);
            }

            public function critical(string $message, array $context = []): void
            {
                $this->record('critical', $message, $context);
            }

            public function warning(string $message, array $context = []): void
            {
                $this->record('warning', $message, $context);
            }

            public function info(string $message, array $context = []): void
            {
                $this->record('info', $message, $context);
            }

            public function notice(string $message, array $context = []): void
            {
                $this->record('notice', $message, $context);
            }

            public function debug(string $message, array $context = []): void
            {
                $this->record('debug', $message, $context);
            }
        });
    }

    protected function tearDown(): void
    {
        StaticLoggerBridge::reset();
    }

    /** @return list<array<string, mixed>> */
    private static function rows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => 'product-' . $i,
                'tenantId' => 'tenant-1',
                'name' => 'Product ' . $i,
                'categoryId' => 'category-1',
                'deletedAt' => null,
            ];
        }

        return $rows;
    }

    private function repository(FakeDatabaseAdapter $adapter): DomainRepository
    {
        $registry = new MapperRegistry();
        $registry->build(
            mapperClasses: [HydratableProductMapper::class],
            domainModelClasses: [HydratableProductDomainModel::class],
        );

        return new DomainRepository(
            resourceModelClass: HydratableProductResourceModel::class,
            domainModelClass: HydratableProductDomainModel::class,
            adapter: $adapter,
            mapperRegistry: $registry,
            hydrator: new ResourceModelHydrator(),
            relationLoader: new ResourceModelRelationLoader($adapter, new ResourceModelHydrator()),
        );
    }

    /**
     * The adapter answers by exact SQL, so the expected query is spelled out.
     *
     * That is a feature here: it pins that an omitted limit really does become
     * `LIMIT 1000` in the statement, not merely a slice taken afterwards.
     */
    private static function selectWithLimit(int $limit): string
    {
        return 'SELECT * FROM `hydratable_products` WHERE `tenantId` = :tenant_scope '
            . 'AND `deletedAt` IS NULL LIMIT ' . $limit;
    }

    private const SELECT_UNBOUNDED = 'SELECT * FROM `hydratable_products` '
        . 'WHERE `tenantId` = :tenant_scope AND `deletedAt` IS NULL';

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    private function truncationWarnings(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $r): bool => str_contains($r['message'], 'truncated'),
        ));
    }

    #[Test]
    public function the_default_limit_is_the_one_that_bit_the_consumer(): void
    {
        // Pinned by value: the warning logic keys on the result size matching it
        // exactly, so a change here without a change there would silence the
        // detection rather than adjust it.
        self::assertSame(1000, DomainRepository::DEFAULT_LIMIT);
    }

    #[Test]
    public function a_result_filling_the_unrequested_bound_is_reported(): void
    {
        $adapter = new FakeDatabaseAdapter([
            self::selectWithLimit(DomainRepository::DEFAULT_LIMIT) => self::rows(DomainRepository::DEFAULT_LIMIT),
        ]);

        $this->repository($adapter)->forTenant('tenant-1')->findAll();

        $warnings = $this->truncationWarnings();

        self::assertCount(1, $warnings, 'a full result at the default bound must be reported');
        self::assertSame('warning', $warnings[0]['level']);
    }

    #[Test]
    public function the_report_says_which_model_and_how_to_opt_out(): void
    {
        // "Probably truncated" is unactionable on its own: the reader needs to
        // know what was cut and what to do instead.
        $adapter = new FakeDatabaseAdapter([
            self::selectWithLimit(DomainRepository::DEFAULT_LIMIT) => self::rows(DomainRepository::DEFAULT_LIMIT),
        ]);

        $this->repository($adapter)->forTenant('tenant-1')->findAll();
        $context = $this->truncationWarnings()[0]['context'];

        self::assertSame(1000, $context['limit']);
        self::assertArrayHasKey('model', $context);
        self::assertStringContainsString('null', (string) $context['note'], 'the unbounded escape hatch is named');
    }

    #[Test]
    public function a_short_result_is_not_reported(): void
    {
        $adapter = new FakeDatabaseAdapter([
            self::selectWithLimit(DomainRepository::DEFAULT_LIMIT) => self::rows(3),
        ]);

        $this->repository($adapter)->forTenant('tenant-1')->findAll();

        self::assertSame([], $this->truncationWarnings());
    }

    #[Test]
    public function a_caller_who_chose_the_limit_is_not_warned(): void
    {
        // The distinction the whole design rests on. A paginated screen asking
        // for exactly this many and getting exactly this many is working, and
        // telling it otherwise is the noise that gets warnings ignored.
        $adapter = new FakeDatabaseAdapter([
            self::selectWithLimit(5) => self::rows(5),
        ]);

        $this->repository($adapter)->forTenant('tenant-1')->findAll(5);

        self::assertSame([], $this->truncationWarnings());
    }

    #[Test]
    public function an_unbounded_fetch_is_neither_capped_nor_reported(): void
    {
        // null means the caller accepted responsibility for the size.
        $adapter = new FakeDatabaseAdapter([
            self::SELECT_UNBOUNDED => self::rows(DomainRepository::DEFAULT_LIMIT),
        ]);

        $rows = $this->repository($adapter)->forTenant('tenant-1')->findAll(null);

        self::assertCount(DomainRepository::DEFAULT_LIMIT, $rows);
        self::assertSame([], $this->truncationWarnings());
    }
}
