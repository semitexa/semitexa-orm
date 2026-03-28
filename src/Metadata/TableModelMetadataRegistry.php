<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

final class TableModelMetadataRegistry
{
    /** @var array<class-string, TableModelMetadata> */
    private static array $cache = [];
    private static ?self $default = null;

    public function __construct(
        private readonly ?TableModelMetadataExtractor $extractor = null,
        private readonly ?TableModelMetadataValidator $validator = null,
    ) {}

    /**
     * @param class-string $tableModelClass
     */
    public function for(string $tableModelClass): TableModelMetadata
    {
        if (isset(self::$cache[$tableModelClass])) {
            return self::$cache[$tableModelClass];
        }

        $extractor = $this->extractor ?? new TableModelMetadataExtractor();
        $validator = $this->validator ?? new TableModelMetadataValidator();

        $metadata = $extractor->extract($tableModelClass);
        $validator->validate($metadata);

        return self::$cache[$tableModelClass] = $metadata;
    }

    public static function default(): self
    {
        return self::$default ??= new self();
    }
}
