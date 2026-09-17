<?php

declare(strict_types=1);

namespace Semitexa\Orm\Tests\Fixture\Metadata;

use Semitexa\Orm\Adapter\MySqlType;
use Semitexa\Orm\Attribute\Column;
use Semitexa\Orm\Attribute\FromTable;
use Semitexa\Orm\Attribute\PrimaryKey;
use Semitexa\Orm\Attribute\TenantScoped;

/**
 * `#[TenantScoped]` taking every default: strategy `same_storage`, no column.
 *
 * The attribute allows this to be WRITTEN — `column` defaults to null — and the
 * validator is what refuses it. This fixture exists so that refusal is pinned
 * by a test rather than left to be rediscovered.
 */
#[FromTable(name: 'implicit_tenant_models')]
#[TenantScoped]
final readonly class ImplicitTenantColumnResourceModel
{
    public function __construct(
        #[PrimaryKey(strategy: 'uuid')]
        #[Column(type: MySqlType::Varchar, length: 36)]
        public string $id,
    ) {}
}
