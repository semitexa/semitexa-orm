<?php

declare(strict_types=1);

/**
 * Local module-structure extension for `packages/semitexa-orm`.
 *
 * STRICTLY ADDITIVE. This file may declare framework-level ORM primitives
 * that are valid only inside semitexa-orm itself. It cannot weaken any
 * global rule (production-pollution, *Interface.php discipline,
 * *Exception.php discipline, singular Attribute/, ...) — the loader rejects
 * any such attempt at load time.
 *
 * SCOPE: this extension applies only to `packages/semitexa-orm/src/`.
 *        It does NOT make Adapter/, Trait/, Repository/, Query/, Metadata/,
 *        or root OrmManager.php valid in any other package, and it does
 *        NOT apply to `src/modules/*`.
 *
 * Authorized in this revision (matches the approved primitive set):
 *   - Adapter/        — public ORM adapter subsystem (Mysql/Sqlite + pools + types + DTOs)
 *   - Trait/          — public ORM entity behavior traits
 *   - Repository/     — public ORM repository abstraction (DomainRepository + interface + paged result)
 *   - Query/          — public ORM query DSL (CRUD builders + condition vocabulary + WhereTrait/Interface)
 *   - Metadata/       — public ORM reflection-extracted metadata subsystem
 *   - OrmManager.php  — public ORM runtime entry point (root file)
 *
 * NOT yet authorised (remain ai:verify violations + open Architecture
 * Questions — do not silently legalize): Connection/, Hydration/, Mapping/,
 * Persistence/, Schema/, Sync/, Tenant/, Transaction/, Uuid/.
 *
 * Companion human docs: packages/semitexa-orm/docs/MODULE_STRUCTURE.md.
 */

use Semitexa\Dev\Application\Service\Ai\Verify\Structure\LocalModuleStructureExtension;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureRule;

$pascalCasePhp = '/^[A-Z][A-Za-z0-9]*\.php$/';

return new LocalModuleStructureExtension(
    package: 'orm',
    topLevelDirectories: [
        'Adapter',
        'Trait',
        'Repository',
        'Query',
        'Metadata',
    ],
    topLevelFiles: [
        'OrmManager.php',
    ],
    pathRules: [
        // Adapter/ — public ORM adapter subsystem. Flat directory with mixed
        // shape: interfaces (ConnectionPoolInterface, DatabaseAdapterInterface,
        // SchemaSwitchInterface, TenantSwitchingConnectionPoolInterface),
        // concrete adapters (MysqlAdapter, SqliteAdapter, LoggingAdapter),
        // connection-pool implementations (ConnectionPool, SingleConnectionPool,
        // NullConnectionPool), driver-type vocabulary (DatabaseType, MySqlType,
        // SqliteType, ServerCapability), result/log DTOs (QueryResult,
        // QueryLogEntry). Documented in packages/semitexa-docs/docs/MODULE_STRUCTURE.md
        // §451-470 as the canonical FQCN home for `Semitexa\Orm\Adapter\*`.
        // Leaf — no sub-directories.
        'Adapter' => new ModuleStructureRule(
            path: 'Adapter',
            allowedFilePatterns: [$pascalCasePhp],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-orm-only: public ORM adapter subsystem. PascalCase PHP files; no subdirectories. Documented as canonical FQCN in MODULE_STRUCTURE.md §451-470.',
        ),

        // Trait/ — public ORM entity behavior traits. Currently 6 files,
        // enumerated explicitly to keep this layer tight (the user-facing
        // surface is small and rarely changes; new traits should be a
        // deliberate addition with discussion).
        'Trait' => new ModuleStructureRule(
            path: 'Trait',
            allowedFiles: [
                'FilterableTrait.php',
                'HasTimestamps.php',
                'HasUuid.php',
                'HasUuidV7.php',
                'Seedable.php',
                'SoftDeletes.php',
            ],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-orm-only: public ORM entity behavior traits. Enumerated; new traits require explicit addition here.',
        ),

        // Repository/ — public ORM repository abstraction. Three files:
        // DomainRepository (base class), RepositoryInterface (port),
        // PaginatedResult (paged-result DTO). Patterns cover all current
        // and likely future additions in this leaf.
        'Repository' => new ModuleStructureRule(
            path: 'Repository',
            allowedFilePatterns: [
                '/^[A-Z][A-Za-z0-9]*Repository\.php$/',
                '/^[A-Z][A-Za-z0-9]*Interface\.php$/',
                '/^[A-Z][A-Za-z0-9]*Result\.php$/',
            ],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-orm-only: public ORM repository abstraction. *Repository.php / *Interface.php / *Result.php only. Does NOT re-enable Domain/Repository for any other package.',
        ),

        // Query/ — public ORM query DSL. Mix of *Query.php builders,
        // condition vocabulary enums (Direction, Operator), value tokens
        // (SystemScopeToken), WhereCapableInterface, WhereTrait. PascalCase
        // PHP is the realistic floor; the basenames are too varied to
        // enumerate without future churn.
        'Query' => new ModuleStructureRule(
            path: 'Query',
            allowedFilePatterns: [$pascalCasePhp],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-orm-only: public ORM query DSL. PascalCase PHP files; no subdirectories.',
        ),

        // Metadata/ — public ORM reflection-extracted metadata subsystem.
        // 13 files: *Metadata DTOs, *Ref value objects, *Registry/Extractor/
        // Validator services, RelationKind enum, Has*References traits.
        // Flat; PascalCase floor.
        'Metadata' => new ModuleStructureRule(
            path: 'Metadata',
            allowedFilePatterns: [$pascalCasePhp],
            mode: ModuleStructureRule::MODE_LEAF_FILES_ONLY,
            rationale: 'semitexa-orm-only: public ORM metadata/reflection subsystem. PascalCase PHP files; no subdirectories.',
        ),
    ],
    docPath: 'packages/semitexa-orm/docs/MODULE_STRUCTURE.md',
    reason: 'semitexa-orm defines framework-level ORM primitives (adapters, traits, repository abstractions, query DSL, metadata subsystem, runtime entry point) that are public API of the ORM but must NOT be valid in ordinary packages.',
);
