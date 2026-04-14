<?php

declare(strict_types=1);

namespace Semitexa\Orm\Metadata;

final class ResourceModelMetadataRegistry
{
    /** @var array<class-string, ResourceModelMetadata> */
    private static array $cache = [];
    private static ?self $default = null;

    public function __construct(
        private readonly ?ResourceModelMetadataExtractor $extractor = null,
        private readonly ?ResourceModelMetadataValidator $validator = null,
    ) {}

    public static function reset(): void
    {
        self::$cache = [];
        self::$default = null;
    }

    /**
     * @param class-string $resourceModelClass
     */
    public function for(string $resourceModelClass): ResourceModelMetadata
    {
        if (isset(self::$cache[$resourceModelClass])) {
            return self::$cache[$resourceModelClass];
        }

        $extractor = $this->extractor ?? new ResourceModelMetadataExtractor();
        $validator = $this->validator ?? new ResourceModelMetadataValidator();

        $metadata = $extractor->extract($resourceModelClass);
        $validator->validate($metadata);

        return self::$cache[$resourceModelClass] = $metadata;
    }

    public static function default(): self
    {
        return self::$default ??= new self();
    }
}
