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
        file_put_contents($this->dir . '/Orders.php', "<?php\nuse Semitexa\\Orm\\Attribute\\FromTable;\n#[FromTable(name: 'orders')]\nfinal class Orders extends Missing {}\n");
        file_put_contents($this->dir . '/Helper.php', "<?php\nfinal class Helper extends Missing {}\n");
        $discovery = $this->createStub(ClassDiscovery::class);
        $discovery->method('findClassesWithAttribute')->willReturn([]);
        $discovery->method('getClassMap')->willReturn(['App\\Orders' => $this->dir . '/Orders.php', 'App\\Helper' => $this->dir . '/Helper.php']);
        $discovery->method('unloadableClasses')->willReturn(['App\\Helper' => 'load error: Class "Missing" not found', 'App\\Orders' => 'load error: Class "Missing" not found']);

        $collector = new SchemaCollector(classDiscovery: $discovery, driver: 'sqlite');
        $collector->collect();

        self::assertSame(["Class 'App\\Orders' declares #[FromTable] but could not be inspected (load error: Class \"Missing\" not found); its table is missing from the schema."], $collector->getErrors());
        self::assertContains("Class 'App\\Helper' could not be inspected (load error: Class \"Missing\" not found); if it is a #[FromTable] resource, its table is missing from the schema.", $collector->getWarnings());
    }
}
