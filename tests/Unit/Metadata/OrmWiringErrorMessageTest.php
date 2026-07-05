<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Exception\MissingMapperException;
use Semitexa\Orm\Metadata\ResourceModelMetadataExtractor;

/**
 * The errors a developer hits while wiring a model/mapper must state the FIX,
 * not just the problem — otherwise they dig through the ORM to learn the
 * convention.
 */
final class OrmWiringErrorMessageTest extends TestCase
{
    #[Test]
    public function a_missing_mapper_error_shows_how_to_create_one(): void
    {
        try {
            (new MapperRegistry())->definitionFor('App\\Model\\Widget', 'App\\Domain\\Widget');
            self::fail('an unregistered pair must throw');
        } catch (MissingMapperException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('App\\Model\\Widget', $msg, 'names the resource model');
            self::assertStringContainsString('App\\Domain\\Widget', $msg, 'names the domain model');
            self::assertStringContainsString('#[AsMapper(', $msg, 'gives the fix');
            self::assertStringContainsString('ResourceModelMapperInterface', $msg, 'names the interface to implement');
        }
    }

    #[Test]
    public function a_missing_from_table_error_shows_the_attribute_shape(): void
    {
        try {
            (new ResourceModelMetadataExtractor())->extract(ResourceWithoutFromTable::class);
            self::fail('a resource without #[FromTable] must throw');
        } catch (\InvalidArgumentException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString(ResourceWithoutFromTable::class, $msg, 'names the class');
            self::assertStringContainsString("#[FromTable(name:", $msg, 'shows the attribute shape');
        }
    }
}

/** A resource model deliberately missing its #[FromTable] attribute. */
final class ResourceWithoutFromTable
{
    public function __construct(public string $id = '')
    {
    }
}
