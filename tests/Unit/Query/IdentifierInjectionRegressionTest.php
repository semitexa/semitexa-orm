<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\RelationRef;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\Orm\Query\SystemScopeToken;
use Semitexa\Orm\Tests\Fixture\Hydration\FakeDatabaseAdapter;
use Semitexa\Orm\Tests\Fixture\Hydration\HydratableProductResourceModel;

/**
 * The defect an external scanner reported on 2026-09-18, and the two locks put
 * on it.
 *
 * It was refuted once, on the grounds that every call site passes an identifier
 * that came from metadata. They do. The question that actually decides it is
 * whether a caller can pass something else, and ColumnRef's constructor was
 * public: `new ColumnRef($model, 'name', $request->get('sort'))` was valid PHP,
 * assertColumnBelongsToCurrentResourceModel() compares the CLASS and waved it
 * through, and countBy() emitted
 *   SELECT `name` , (SELECT 1) AS x -- ` AS __g ... GROUP BY `name` , (SELECT 1) AS x -- `
 * with no exception. Nothing in the ecosystem did that — but this is framework
 * API, and a consumer wiring a sortable grid from a query parameter writes
 * exactly that constructor call.
 *
 * Two locks, and this test holds both:
 *   at the source — the constructor is private, so the name comes from metadata;
 *   at the sink   — SqlIdentifier escapes it anyway, for callers that do not
 *                   exist yet and for the paths that never had a ColumnRef.
 * The second is the one that matters, so it is proven by BYPASSING the first.
 */
final class IdentifierInjectionRegressionTest extends TestCase
{
    private const PAYLOAD = 'name` , (SELECT 1) AS x -- ';

    #[Test]
    public function a_column_reference_cannot_be_built_with_a_name_of_the_callers_choosing(): void
    {
        self::assertTrue(
            (new ReflectionClass(ColumnRef::class))->getConstructor()?->isPrivate(),
            'ColumnRef::__construct must stay private — ::for() is the only door, and it '
            . 'validates the property against the model metadata.',
        );
    }

    #[Test]
    public function the_same_holds_for_relation_references(): void
    {
        self::assertTrue(
            (new ReflectionClass(RelationRef::class))->getConstructor()?->isPrivate(),
            'RelationRef::__construct must stay private — its foreignKey, relatedKey and '
            . 'pivotTable are interpolated into SQL by the relation loader.',
        );
    }

    /**
     * Deliberately defeats the private constructor, because the point is the
     * OTHER lock: even handed a hostile name, the query builder must not let it
     * out of the identifier quotes.
     */
    #[Test]
    public function a_hostile_column_name_cannot_escape_the_identifier_quotes(): void
    {
        // newInstanceArgs() respects the private constructor, which is the
        // first lock doing its job. Build it the only way that is left, so the
        // second lock is what the rest of this test measures.
        $reflection = new ReflectionClass(ColumnRef::class);
        $hostile = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'resourceModelClass' => HydratableProductResourceModel::class,
            'propertyName'       => 'name',
            'columnName'         => self::PAYLOAD,
        ] as $property => $value) {
            $reflection->getProperty($property)->setValue($hostile, $value);
        }

        $adapter = new FakeDatabaseAdapter([]);
        $this->queryOver($adapter)
            ->withoutTenantScope(SystemScopeToken::issue())
            ->countBy($hostile);

        self::assertCount(1, $adapter->executed);
        $sql = $adapter->executed[0]['sql'];

        // The payload's own backtick must have been doubled, so what follows it
        // stays inside the identifier instead of becoming SQL.
        self::assertStringContainsString('`name`` , (SELECT 1) AS x -- `', $sql);
        self::assertStringNotContainsString('`name` , (SELECT 1)', $sql);

        // And the statement still has the shape it is supposed to have: exactly
        // one FROM, and no stray subquery spliced into the projection.
        self::assertSame(1, substr_count($sql, ' FROM '));
    }

    #[Test]
    public function an_ordinary_column_still_produces_exactly_the_sql_it_did_before(): void
    {
        $adapter = new FakeDatabaseAdapter([]);
        $this->queryOver($adapter)
            ->withoutTenantScope(SystemScopeToken::issue())
            ->countBy(ColumnRef::for(HydratableProductResourceModel::class, 'name'));

        // Byte-for-byte what the unescaped sprintf emitted: the sweep moved the
        // quoting, it did not change the statements.
        self::assertSame(
            'SELECT `name` AS __g, COUNT(*) AS __c FROM `hydratable_products`'
            . ' WHERE `deletedAt` IS NULL GROUP BY `name` ORDER BY __c DESC, `name` ASC',
            $adapter->executed[0]['sql'],
        );
    }

    private function queryOver(FakeDatabaseAdapter $adapter): ResourceModelQuery
    {
        $hydrator = new ResourceModelHydrator();

        return new ResourceModelQuery(
            HydratableProductResourceModel::class,
            $adapter,
            $hydrator,
            new ResourceModelRelationLoader($adapter, $hydrator),
        );
    }
}
