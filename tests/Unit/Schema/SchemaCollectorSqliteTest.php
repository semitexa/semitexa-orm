<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Schema;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Adapter\SqliteType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\Index;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;
use Semitexa\Orm\Application\Service\Schema\SchemaCollector;

#[FromTable(name: 'sqlite_documents')]
final readonly class SqliteValidTypesResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: SqliteType::Varchar, length: 36)]
        public string $id,

        #[Column(type: SqliteType::Json)]
        public array $payload,

        #[Column(type: SqliteType::Datetime)]
        public DateTimeImmutable $createdAt,

        #[Column(type: SqliteType::Date, nullable: true)]
        public ?DateTimeImmutable $publishedOn = null,
    ) {}
}

#[FromTable(name: 'sqlite_invalid_index')]
#[Index(columns: ['slug', ''])]
final readonly class SqliteInvalidIndexResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: SqliteType::Varchar, length: 36)]
        public string $id,

        #[Column(type: SqliteType::Varchar, length: 255)]
        public string $slug,
    ) {}
}

#[FromTable(name: 'sqlite_site_notes')]
#[TenantScoped(strategy: 'column', column: 'siteId')]
final readonly class SqliteOwnTenantColumnResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: SqliteType::Varchar, length: 36)]
        public string $id,

        #[Column(name: 'site_id', type: SqliteType::Varchar, length: 64)]
        public string $siteId,
    ) {}
}

final class SchemaCollectorSqliteTest extends TestCase
{
    /** Given a second, NOT NULL tenant_id beside its own, every insert failed. */
    #[Test]
    public function a_model_that_names_its_tenant_column_gets_no_tenant_id(): void
    {
        $classDiscovery = $this->createMock(ClassDiscovery::class);
        $classDiscovery->method('findClassesWithAttribute')->willReturn([SqliteOwnTenantColumnResourceModel::class]);

        $table = (new SchemaCollector(classDiscovery: $classDiscovery, driver: 'sqlite'))->collect()['sqlite_site_notes'];

        $this->assertNotNull($table->getColumn('site_id'));
        $this->assertNull($table->getColumn('tenant_id'));
    }

    #[Test]
    public function accepts_json_and_datetime_backed_php_types_for_sqlite_columns(): void
    {
        $classDiscovery = $this->createMock(ClassDiscovery::class);
        $classDiscovery
            ->expects($this->once())
            ->method('findClassesWithAttribute')
            ->willReturn([SqliteValidTypesResourceModel::class]);

        $collector = new SchemaCollector(
            classDiscovery: $classDiscovery,
            driver: 'sqlite',
        );

        $tables = $collector->collect();

        $this->assertArrayHasKey('sqlite_documents', $tables);
        $this->assertSame([], $collector->getErrors());
    }

    #[Test]
    public function reports_invalid_index_column_lists_instead_of_silently_rewriting_them(): void
    {
        $classDiscovery = $this->createMock(ClassDiscovery::class);
        $classDiscovery
            ->expects($this->once())
            ->method('findClassesWithAttribute')
            ->willReturn([SqliteInvalidIndexResourceModel::class]);

        $collector = new SchemaCollector(
            classDiscovery: $classDiscovery,
            driver: 'sqlite',
        );

        $collector->collect();

        $this->assertContains(
            "Table 'sqlite_invalid_index': #[Index] columns must be non-empty strings.",
            $collector->getErrors(),
        );
    }
}
