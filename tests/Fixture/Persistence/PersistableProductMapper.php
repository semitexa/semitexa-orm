<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Persistence;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidCategoryTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidProductTableModel;
use Semitexa\Orm\Tests\Fixture\Metadata\ValidReviewTableModel;

#[AsMapper(tableModel: ValidProductTableModel::class, domainModel: PersistableProductDomainModel::class)]
final class PersistableProductMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): object
    {
        $tableModel instanceof ValidProductTableModel || throw new \InvalidArgumentException('Unexpected table model.');

        return new PersistableProductDomainModel(
            id: $tableModel->id,
            tenantId: $tableModel->tenantId,
            name: $tableModel->name,
            categoryId: $tableModel->categoryId,
            category: $tableModel->category instanceof ValidCategoryTableModel
                ? new PersistableCategoryDomainModel($tableModel->category->id, $tableModel->category->name)
                : null,
            reviews: array_map(
                static fn (ValidReviewTableModel $review): PersistableReviewDomainModel => new PersistableReviewDomainModel(
                    id: $review->id,
                    productId: $review->productId,
                    rating: $review->rating,
                ),
                $tableModel->reviews,
            ),
        );
    }

    public function toTableModel(object $domainModel): object
    {
        $domainModel instanceof PersistableProductDomainModel || throw new \InvalidArgumentException('Unexpected domain model.');

        return new ValidProductTableModel(
            id: $domainModel->id,
            tenantId: $domainModel->tenantId,
            name: $domainModel->name,
            categoryId: $domainModel->categoryId,
            deletedAt: null,
            category: $domainModel->category === null
                ? null
                : new ValidCategoryTableModel(
                    id: $domainModel->category->id,
                    name: $domainModel->category->name,
                ),
            reviews: array_map(
                static fn (PersistableReviewDomainModel $review): ValidReviewTableModel => new ValidReviewTableModel(
                    id: $review->id,
                    productId: $review->productId,
                    rating: $review->rating,
                ),
                $domainModel->reviews,
            ),
        );
    }
}
