<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Application\Service\Schema\SchemaCollector;
use Semitexa\Orm\Domain\Enum\ForeignKeyAction;
use Semitexa\Orm\Domain\Model\RelationDefinition;
use Semitexa\Orm\Tests\Fixture\Schema\RelationCustomerModel;
use Semitexa\Orm\Tests\Fixture\Schema\RelationOrderModel;

/**
 * A relation's declared foreign-key actions reach the foreign key the schema
 * builds. They were read off an array whose hand-written shape had lost them
 * and whose reader called them strings.
 */
final class SchemaCollectorRelationsTest extends TestCase
{
    /** @param list<string> $classes */
    private function collect(array $classes): SchemaCollector
    {
        $discovery = $this->createMock(ClassDiscovery::class);
        $discovery->method('findClassesWithAttribute')->willReturn($classes);

        return new SchemaCollector(classDiscovery: $discovery);
    }

    #[Test]
    public function a_relation_is_a_typed_definition_carrying_its_actions(): void
    {
        $tables = $this->collect([RelationOrderModel::class, RelationCustomerModel::class])->collect();

        self::assertEquals(
            ['customer' => new RelationDefinition('belongs_to', RelationCustomerModel::class, 'customer_id', ForeignKeyAction::Cascade, ForeignKeyAction::NoAction)],
            $tables['relation_orders']->getRelations(),
        );
    }

    #[Test]
    public function the_foreign_key_takes_the_actions_the_relation_declares(): void
    {
        $tables = $this->collect([RelationOrderModel::class, RelationCustomerModel::class])->collect();

        $fks = $tables['relation_orders']->getForeignKeys();
        self::assertCount(1, $fks);
        self::assertSame(
            ['customer_id', 'relation_customers', 'id', ForeignKeyAction::Cascade, ForeignKeyAction::NoAction],
            [$fks[0]->column, $fks[0]->referencedTable, $fks[0]->referencedColumn, $fks[0]->onDelete, $fks[0]->onUpdate],
        );
    }

    #[Test]
    public function a_discovered_class_that_does_not_load_is_an_error_not_a_crash(): void
    {
        $collector = $this->collect(['Semitexa\\Orm\\Tests\\Fixture\\Schema\\NoSuchModel', RelationCustomerModel::class]);
        $tables = $collector->collect();

        self::assertArrayHasKey('relation_customers', $tables);
        self::assertSame(["Class 'Semitexa\\Orm\\Tests\\Fixture\\Schema\\NoSuchModel' carries #[FromTable] but cannot be loaded."], $collector->getErrors());
    }
}
