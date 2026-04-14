<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Persistence;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductResourceModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidReviewResourceModel;

#[AsMapper(resourceModel: ValidProductResourceModel::class, domainModel: PersistableProductDomainModel::class)]
final class PersistableProductMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): object
    {
        $resourceModel instanceof ValidProductResourceModel || throw new \InvalidArgumentException('Unexpected resource model.');

        return new PersistableProductDomainModel(
            id: $resourceModel->id,
            tenantId: $resourceModel->tenantId,
            name: $resourceModel->name,
            categoryId: $resourceModel->categoryId,
            category: $resourceModel->category instanceof ValidCategoryResourceModel
                ? new PersistableCategoryDomainModel($resourceModel->category->id, $resourceModel->category->name)
                : null,
            reviews: array_map(
                static fn (ValidReviewResourceModel $review): PersistableReviewDomainModel => new PersistableReviewDomainModel(
                    id: $review->id,
                    productId: $review->productId,
                    rating: $review->rating,
                ),
                $resourceModel->reviews,
            ),
        );
    }

    public function toSourceModel(object $domainModel): object
    {
        $domainModel instanceof PersistableProductDomainModel || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ValidProductResourceModel(
            id: $domainModel->id,
            tenantId: $domainModel->tenantId,
            name: $domainModel->name,
            categoryId: $domainModel->categoryId,
            deletedAt: null,
            category: $domainModel->category === null
                ? null
                : new ValidCategoryResourceModel(
                    id: $domainModel->category->id,
                    name: $domainModel->category->name,
                ),
            reviews: array_map(
                static fn (PersistableReviewDomainModel $review): ValidReviewResourceModel => new ValidReviewResourceModel(
                    id: $review->id,
                    productId: $review->productId,
                    rating: $review->rating,
                ),
                $domainModel->reviews,
            ),
        );
    }
}
