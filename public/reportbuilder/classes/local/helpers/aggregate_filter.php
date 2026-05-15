<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace core_reportbuilder\local\helpers;

use lang_string;
use core_reportbuilder\local\aggregation\base as aggregation_base;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;

/**
 * Helper class for aggregate filter related methods
 *
 * Aggregate filters allow filtering on aggregated column values using HAVING clauses. They use a three-part unique
 * identifier format: entity:columnname:aggregation (e.g., user:username:count)
 *
 * @package     core_reportbuilder
 * @copyright   2025 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aggregate_filter {

    /**
     * Determine whether a unique identifier represents an aggregate filter (three-part format)
     *
     * @param string $uniqueidentifier
     * @return bool
     */
    public static function is_aggregate_identifier(string $uniqueidentifier): bool {
        return substr_count($uniqueidentifier, ':') === 2;
    }

    /**
     * Parse a three-part aggregate filter identifier into its components
     *
     * @param string $uniqueidentifier e.g., "user:username:count"
     * @return array{entity: string, column: string, aggregation: string}|null
     */
    public static function parse_identifier(string $uniqueidentifier): ?array {
        if (!self::is_aggregate_identifier($uniqueidentifier)) {
            return null;
        }

        [$entity, $column, $aggregation] = explode(':', $uniqueidentifier, 3);

        return [
            'entity' => $entity,
            'column' => $column,
            'aggregation' => $aggregation,
        ];
    }

    /**
     * Build an aggregate filter identifier from its components
     *
     * @param string $entity
     * @param string $column
     * @param string $aggregation
     * @return string
     */
    public static function build_identifier(string $entity, string $column, string $aggregation): string {
        return "{$entity}:{$column}:{$aggregation}";
    }

    /**
     * Return the filter class appropriate for the given column type
     *
     * @param int $columntype One of the column::TYPE_* constants
     * @return string Fully qualified filter class name
     */
    public static function get_filter_class_for_type(int $columntype): string {
        return match ($columntype) {
            column::TYPE_INTEGER, column::TYPE_FLOAT => \core_reportbuilder\local\filters\number::class,
            column::TYPE_TIMESTAMP => \core_reportbuilder\local\filters\date::class,
            column::TYPE_BOOLEAN => \core_reportbuilder\local\filters\boolean_select::class,
            default => \core_reportbuilder\local\filters\text::class,
        };
    }

    /**
     * Create a dynamic filter instance for an aggregated column
     *
     * @param column $column The column with aggregation set
     * @return filter|null The filter instance, or null if column has no aggregation
     */
    public static function create_aggregate_filter(column $column): ?filter {
        $aggregation = $column->get_aggregation();
        if ($aggregation === null) {
            return null;
        }

        // Determine the output type of the aggregation and the appropriate filter class.
        $aggregatedtype = $aggregation::get_column_type($column->get_type());
        $filterclass = self::get_filter_class_for_type($aggregatedtype);

        // Build the unique name for the filter using the aggregation class name.
        $aggregationname = $aggregation::get_class_name();
        $filtername = $column->get_name() . ':' . $aggregationname;

        // Build the default label: "ColumnName (Aggregation)".
        $aggregationlabel = $aggregation::get_name();
        $columnheader = $column->get_title();
        $header = new lang_string('aggregatefilterheader', 'core_reportbuilder', [
            'column' => $columnheader,
            'aggregation' => $aggregationlabel,
        ]);

        // Create the filter instance with the aggregation expression as field SQL.
        $fieldsql = $column->get_aggregation_sql_expression();

        $filter = new filter(
            filterclass: $filterclass,
            name: $filtername,
            header: $header,
            entityname: $column->get_entity_name(),
            fieldsql: $fieldsql,
        );

        // Inherit the column's joins so the HAVING clause has access to the same tables.
        foreach ($column->get_joins() as $join) {
            $filter->add_join($join);
        }

        // Mark this filter as targeting an aggregated column.
        $filter->set_is_aggregate();

        return $filter;
    }

    /**
     * Try to resolve an aggregate filter from active columns
     *
     * Given a three-part unique identifier, find the matching active column with the expected aggregation
     * and create a dynamic filter instance
     *
     * @param string $uniqueidentifier Three-part identifier (entity:column:aggregation)
     * @param column[] $activecolumns Active columns with their aggregations set
     * @return filter|null The resolved filter, or null if no matching aggregated column was found
     */
    public static function resolve_aggregate_filter(string $uniqueidentifier, array $activecolumns): ?filter {
        $parsed = self::parse_identifier($uniqueidentifier);
        if ($parsed === null) {
            return null;
        }

        $columnidentifier = $parsed['entity'] . ':' . $parsed['column'];
        $expectedaggregation = $parsed['aggregation'];

        // Find the matching active column.
        foreach ($activecolumns as $column) {
            if ($column->get_unique_identifier() !== $columnidentifier) {
                continue;
            }

            $aggregation = $column->get_aggregation();
            if ($aggregation === null) {
                return null;
            }

            // Check the aggregation matches.
            if ($aggregation::get_class_name() !== $expectedaggregation) {
                return null;
            }

            return self::create_aggregate_filter($column);
        }

        return null;
    }

    /**
     * Return aggregate filter instances for all aggregated columns, excluding those already active
     *
     * @param column[] $activecolumns Active columns with their aggregations set
     * @param string[] $existingidentifiers Identifiers of already-active conditions/filters to exclude
     * @return filter[] Aggregate filter instances keyed by unique identifier
     */
    public static function get_aggregate_filters(array $activecolumns, array $existingidentifiers = []): array {
        $filters = [];

        foreach ($activecolumns as $column) {
            if ($column->get_aggregation() === null) {
                continue;
            }

            $filter = self::create_aggregate_filter($column);
            if ($filter === null) {
                continue;
            }

            $identifier = $filter->get_unique_identifier();
            if (in_array($identifier, $existingidentifiers)) {
                continue;
            }

            $filters[$identifier] = $filter;
        }

        return $filters;
    }
}
