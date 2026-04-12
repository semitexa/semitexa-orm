<?php

declare(strict_types=1);

namespace Semitexa\Orm\Adapter;

/**
 * Marker interface for database column type enums.
 *
 * Allows ColumnDefinition and schema-related code to work with
 * database-specific type enums (MySqlType, SqliteType, etc.)
 * in a driver-agnostic way.
 */
interface DatabaseType
{
    /**
     * Return the raw SQL type string for DDL generation.
     *
     * The implementing type enum knows how to render itself
     * given optional length, precision, and scale parameters.
     */
    public function toSql(?int $length = null, ?int $precision = null, ?int $scale = null): string;

    /**
     * Return the canonical type name for schema comparison.
     *
     * This is the normalized form used when comparing
     * code definitions against live database state.
     */
    public function canonicalName(?int $length = null, ?int $precision = null, ?int $scale = null): string;
}
