<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Orm\Application\Service\Schema\SchemaCollector;

/**
 * A class discovery could not inspect is absent from the schema, and
 * orm:sync / orm:diff fail only on errors (tk-discovery-reports-dropped-
 * classes): one whose source declares #[FromTable] is an error, any other a
 * warning that names it.
 */
final class SchemaCollectorUninspectedTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-uninspected-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function a_resource_discovery_could_not_inspect_fails_the_schema_and_any_other_is_named(): void
    {
        file_put_contents($this->dir . '/Orders.php', "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\FromTable;\n#[FromTable(name: 'orders')]\nfinal class Orders extends Missing {}\n");
        file_put_contents($this->dir . '/Helper.php', "<?php\nnamespace App;\nfinal class Helper extends Missing {}\n");
        $discovery = $this->createStub(ClassDiscovery::class);
        $discovery->method('findClassesWithAttribute')->willReturn([]);
        $discovery->method('getClassMap')->willReturn(['App\\Orders' => $this->dir . '/Orders.php', 'App\\Helper' => $this->dir . '/Helper.php']);
        $discovery->method('unloadableClasses')->willReturn(['App\\Helper' => 'load error: Class "Missing" not found', 'App\\Orders' => 'load error: Class "Missing" not found']);

        $collector = new SchemaCollector(classDiscovery: $discovery, driver: 'sqlite');
        $collector->collect();

        self::assertSame(["Class 'App\\Orders' declares #[FromTable] but could not be inspected (load error: Class \"Missing\" not found); its table is missing from the schema."], $collector->getErrors());
        self::assertContains("Class 'App\\Helper' could not be inspected (load error: Class \"Missing\" not found); if it is a #[FromTable] resource, its table is missing from the schema.", $collector->getWarnings());
    }

    /** Another connection's resource is not this sync's error; an unreadable source is. */
    #[Test]
    public function the_report_is_scoped_to_the_connection_and_an_unreadable_source_fails_it(): void
    {
        file_put_contents($this->dir . '/Events.php', "<?php\nnamespace App;\nuse Semitexa\\Orm\\Attribute\\FromTable;\nuse Semitexa\\Orm\\Attribute\\Connection;\n#[FromTable(name: 'events')]\n#[Connection('analytics')]\nfinal class Events extends Missing {}\n");
        $discovery = $this->createStub(ClassDiscovery::class);
        $discovery->method('findClassesWithAttribute')->willReturn([]);
        $discovery->method('getClassMap')->willReturn(['App\\Events' => $this->dir . '/Events.php', 'App\\Gone' => $this->dir . '/Gone.php']);
        $discovery->method('unloadableClasses')->willReturn(['App\\Events' => 'load error', 'App\\Gone' => 'does not exist']);

        $default = new SchemaCollector(classDiscovery: $discovery, driver: 'sqlite', connectionNameFilter: 'default');
        $default->collect();
        self::assertSame(["Class 'App\\Gone' could not be inspected (does not exist) and its source could not be read; if it is a #[FromTable] resource, its table is missing from the schema."], $default->getErrors(), 'analytics is not the default sync\'s to report');
        foreach ($default->getWarnings() as $warning) {
            self::assertStringNotContainsString('App\\Events', $warning, 'nor a warning of it');
        }

        $analytics = new SchemaCollector(classDiscovery: $discovery, driver: 'sqlite', connectionNameFilter: 'analytics');
        $analytics->collect();
        self::assertContains("Class 'App\\Events' declares #[FromTable] but could not be inspected (load error); its table is missing from the schema.", $analytics->getErrors());
    }
}
