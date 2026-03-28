<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Persistence;

final readonly class PersistableProductDomainModel
{
    /**
     * @param list<PersistableReviewDomainModel> $reviews
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $name,
        public string $categoryId,
        public ?PersistableCategoryDomainModel $category = null,
        public array $reviews = [],
    ) {}
}
