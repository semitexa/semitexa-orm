<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

/**
 * A column of a resource model, named by the metadata rather than by a caller.
 *
 * The constructor is private on purpose. It used to be public, and
 * `new ColumnRef($model, 'whatever', $request->get('sort'))` was valid PHP that
 * nothing downstream rejected: assertColumnBelongsToCurrentResourceModel()
 * compares the CLASS, not the name, so a hand-built ref reached countBy() and
 * produced
 *   SELECT `name` , (SELECT 1) AS x -- ` AS __g ... GROUP BY `name` , (SELECT 1) AS x -- `
 * SqlIdentifier now escapes that at the sink, which protects callers that do
 * not exist yet. This closes the same hole at the source, so a ColumnRef can
 * only ever name a column the model actually declares — ::for() throws on one
 * it does not.
 *
 * Nothing in the workspace constructed one directly: all uses went through
 * ::for(), or through HasColumnReferences::column().
 */
final readonly class ColumnRef
{
    /**
     * @param class-string $resourceModelClass
     */
    private function __construct(
        public string $resourceModelClass,
        public string $propertyName,
        public string $columnName,
    ) {}

    /**
     * @param class-string $resourceModelClass
     */
    public static function for(string $resourceModelClass, string $propertyName): self
    {
        $metadata = ResourceModelMetadataRegistry::default()->for($resourceModelClass);
        $column = $metadata->column($propertyName);

        return new self(
            resourceModelClass: $resourceModelClass,
            propertyName: $column->propertyName,
            columnName: $column->columnName,
        );
    }
}
