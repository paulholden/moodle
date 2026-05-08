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

use advanced_testcase;
use core_reportbuilder\local\report\column;

/**
 * Unit tests for the report field deduplication helper
 *
 * @package     core_reportbuilder
 * @covers      \core_reportbuilder\local\helpers\report_fields_dedup
 * @copyright   2026 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_fields_dedup_test extends advanced_testcase {

    /**
     * Build a single-field column with the given index, returning the column instance
     *
     * @param int $index
     * @param string $sql
     * @param string $alias
     * @param array $params
     * @return column
     */
    private function make_column(int $index, string $sql, string $alias, array $params = []): column {
        $col = (new column('test', null, 'entity'))
            ->add_field($sql, $alias, $params)
            ->set_index($index);
        return $col;
    }

    /**
     * No duplicates means every column's fields are emitted unchanged and the alias map is empty
     */
    public function test_no_duplicates(): void {
        $columns = [
            $this->make_column(1, 'u.firstname', 'firstname'),
            $this->make_column(2, 'u.lastname', 'lastname'),
        ];

        $result = report_fields_dedup::get_unique_fields($columns);

        $this->assertSame([
            'u.firstname AS c1_firstname',
            'u.lastname AS c2_lastname',
        ], $result['fields']);
        $this->assertSame([], $result['aliasmap']);
    }

    /**
     * Identical SQL across all columns collapses to one SELECT field with later aliases mapped onto the first
     */
    public function test_all_duplicates(): void {
        $columns = [
            $this->make_column(1, 'u.firstname', 'firstname'),
            $this->make_column(2, 'u.firstname', 'firstname'),
            $this->make_column(3, 'u.firstname', 'firstname'),
        ];

        $result = report_fields_dedup::get_unique_fields($columns);

        $this->assertSame(['u.firstname AS c1_firstname'], $result['fields']);
        $this->assertSame([
            'c2_firstname' => 'c1_firstname',
            'c3_firstname' => 'c1_firstname',
        ], $result['aliasmap']);
    }

    /**
     * Aggregated columns are never collapsed against raw-field columns sharing the same source SQL
     */
    public function test_aggregated_not_collapsed(): void {
        $raw = $this->make_column(1, 'u.id', 'id');
        $aggregated = $this->make_column(2, 'u.id', 'id')
            ->set_aggregation('count');

        $result = report_fields_dedup::get_unique_fields([$raw, $aggregated]);

        $this->assertCount(2, $result['fields']);
        $this->assertSame('u.id AS c1_id', $result['fields'][0]);
        // Second field is the aggregation-wrapped SQL, distinct from the raw expression.
        $this->assertStringContainsString('AS c2_id', $result['fields'][1]);
        $this->assertNotSame('u.id AS c2_id', $result['fields'][1]);
        $this->assertSame([], $result['aliasmap']);
    }

    /**
     * Parameterised columns diverge once parameters are renamed, so they aren't collapsed across columns
     */
    public function test_parameterised_columns_not_collapsed(): void {
        $param = database::generate_param_name();
        $columns = [
            $this->make_column(1, "CASE WHEN u.id = :{$param} THEN 1 ELSE 0 END", 'flag', [$param => 1]),
            $this->make_column(2, "CASE WHEN u.id = :{$param} THEN 1 ELSE 0 END", 'flag', [$param => 1]),
        ];

        $result = report_fields_dedup::get_unique_fields($columns);

        // Two distinct fields, since the parameter name was renamed to p1_/p2_ before equality testing.
        $this->assertCount(2, $result['fields']);
        $this->assertStringContainsString(":p1_{$param}", $result['fields'][0]);
        $this->assertStringContainsString(":p2_{$param}", $result['fields'][1]);
        $this->assertSame([], $result['aliasmap']);
    }

    /**
     * Mixed shape: some columns share SQL, others don't, parameterised and raw both appear
     */
    public function test_mixed_columns(): void {
        $param = database::generate_param_name();
        $columns = [
            $this->make_column(1, 'u.firstname', 'firstname'),
            $this->make_column(2, 'u.lastname', 'lastname'),
            $this->make_column(3, 'u.firstname', 'firstname'),
            $this->make_column(4, "CASE WHEN u.id = :{$param} THEN 1 END", 'flag', [$param => 1]),
            $this->make_column(5, 'u.lastname', 'lastname'),
        ];

        $result = report_fields_dedup::get_unique_fields($columns);

        $this->assertCount(3, $result['fields']);
        $this->assertSame('u.firstname AS c1_firstname', $result['fields'][0]);
        $this->assertSame('u.lastname AS c2_lastname', $result['fields'][1]);
        $this->assertStringContainsString(":p4_{$param}", $result['fields'][2]);
        $this->assertSame([
            'c3_firstname' => 'c1_firstname',
            'c5_lastname' => 'c2_lastname',
        ], $result['aliasmap']);
    }

    /**
     * Aliases inside a sort or group-by fragment are rewritten to their canonical equivalents
     */
    public function test_rewrite_aliases(): void {
        $aliasmap = [
            'c2_firstname' => 'c1_firstname',
            'c5_lastname' => 'c2_lastname',
        ];

        // Plain alias.
        $this->assertSame('c1_firstname',
            report_fields_dedup::rewrite_aliases('c2_firstname', $aliasmap));

        // Multiple aliases inside a SQL expression.
        $this->assertSame('COALESCE(c1_firstname, c2_lastname)',
            report_fields_dedup::rewrite_aliases('COALESCE(c2_firstname, c5_lastname)', $aliasmap));

        // Untouched aliases pass through unchanged.
        $this->assertSame('c1_firstname, c9_other',
            report_fields_dedup::rewrite_aliases('c1_firstname, c9_other', $aliasmap));

        // Empty alias map is a no-op.
        $this->assertSame('c2_firstname',
            report_fields_dedup::rewrite_aliases('c2_firstname', []));
    }

    /**
     * Rehydration copies canonical alias values into the rewritten alias keys, leaving the rest of the row untouched
     */
    public function test_rehydrate_row(): void {
        $aliasmap = [
            'c2_firstname' => 'c1_firstname',
            'c3_firstname' => 'c1_firstname',
        ];

        $row = [
            'c1_firstname' => 'Alice',
            'c4_other' => 'kept',
        ];

        $rehydrated = report_fields_dedup::rehydrate_row($row, $aliasmap);

        $this->assertSame([
            'c1_firstname' => 'Alice',
            'c4_other' => 'kept',
            'c2_firstname' => 'Alice',
            'c3_firstname' => 'Alice',
        ], $rehydrated);

        // Empty alias map is a no-op.
        $this->assertSame($row, report_fields_dedup::rehydrate_row($row, []));

        // Missing canonical key is silently skipped (defensive against partial result sets).
        $this->assertSame(['c4_other' => 'kept'], report_fields_dedup::rehydrate_row(
            ['c4_other' => 'kept'],
            ['c2_firstname' => 'c1_firstname'],
        ));
    }
}
