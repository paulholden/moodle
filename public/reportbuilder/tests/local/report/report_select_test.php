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

namespace core_reportbuilder\local\report;

use advanced_testcase;
use core_reportbuilder\local\helpers\database;

/**
 * Unit tests for the report_select value object
 *
 * @package     core_reportbuilder
 * @covers      \core_reportbuilder\local\report\report_select
 * @copyright   2026 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_select_test extends advanced_testcase {

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
        return (new column('test', null, 'entity'))
            ->add_field($sql, $alias, $params)
            ->set_index($index);
    }

    /**
     * No duplicates means every column's fields are emitted unchanged and rewriting is a no-op
     */
    public function test_no_duplicates(): void {
        $select = report_select::for_columns([
            $this->make_column(1, 'u.firstname', 'firstname'),
            $this->make_column(2, 'u.lastname', 'lastname'),
        ]);

        $this->assertSame([
            'u.firstname AS c1_firstname',
            'u.lastname AS c2_lastname',
        ], $select->get_select_fields());

        $this->assertSame('c1_firstname', $select->rewrite('c1_firstname'));
        $this->assertSame(['c1_firstname' => 'Alice'], $select->rehydrate(['c1_firstname' => 'Alice']));
    }

    /**
     * Identical SQL across all columns collapses to one SELECT field, and aliases for the duplicate
     * columns rewrite to the first column's canonical alias.
     */
    public function test_all_duplicates(): void {
        $select = report_select::for_columns([
            $this->make_column(1, 'u.firstname', 'firstname'),
            $this->make_column(2, 'u.firstname', 'firstname'),
            $this->make_column(3, 'u.firstname', 'firstname'),
        ]);

        $this->assertSame(['u.firstname AS c1_firstname'], $select->get_select_fields());

        // rewrite() and rehydrate() expose the alias-map behaviour without leaking the map itself.
        $this->assertSame('c1_firstname', $select->rewrite('c2_firstname'));
        $this->assertSame('c1_firstname', $select->rewrite('c3_firstname'));
        $this->assertSame([
            'c1_firstname' => 'Alice',
            'c2_firstname' => 'Alice',
            'c3_firstname' => 'Alice',
        ], $select->rehydrate(['c1_firstname' => 'Alice']));
    }

    /**
     * Aggregated columns are never collapsed against raw-field columns sharing the same source SQL
     */
    public function test_aggregated_not_collapsed(): void {
        $raw = $this->make_column(1, 'u.id', 'id');
        $aggregated = $this->make_column(2, 'u.id', 'id')->set_aggregation('count');

        $select = report_select::for_columns([$raw, $aggregated]);

        $fields = $select->get_select_fields();
        $this->assertCount(2, $fields);
        $this->assertSame('u.id AS c1_id', $fields[0]);
        // Second field is the aggregation-wrapped SQL, distinct from the raw expression.
        $this->assertStringContainsString('AS c2_id', $fields[1]);
        $this->assertNotSame('u.id AS c2_id', $fields[1]);

        // Neither alias rewrites onto the other.
        $this->assertSame('c2_id', $select->rewrite('c2_id'));
    }

    /**
     * Parameterised columns diverge once parameters are renamed, so they aren't collapsed
     */
    public function test_parameterised_columns_not_collapsed(): void {
        $param = database::generate_param_name();
        $select = report_select::for_columns([
            $this->make_column(1, "CASE WHEN u.id = :{$param} THEN 1 ELSE 0 END", 'flag', [$param => 1]),
            $this->make_column(2, "CASE WHEN u.id = :{$param} THEN 1 ELSE 0 END", 'flag', [$param => 1]),
        ]);

        $fields = $select->get_select_fields();
        $this->assertCount(2, $fields);
        $this->assertStringContainsString(":p1_{$param}", $fields[0]);
        $this->assertStringContainsString(":p2_{$param}", $fields[1]);

        $this->assertSame('c2_flag', $select->rewrite('c2_flag'));
    }

    /**
     * Mixed shape: some columns share SQL, others don't; rewriting and rehydration both work
     */
    public function test_mixed_columns(): void {
        $param = database::generate_param_name();
        $select = report_select::for_columns([
            $this->make_column(1, 'u.firstname', 'firstname'),
            $this->make_column(2, 'u.lastname', 'lastname'),
            $this->make_column(3, 'u.firstname', 'firstname'),
            $this->make_column(4, "CASE WHEN u.id = :{$param} THEN 1 END", 'flag', [$param => 1]),
            $this->make_column(5, 'u.lastname', 'lastname'),
        ]);

        $fields = $select->get_select_fields();
        $this->assertCount(3, $fields);
        $this->assertSame('u.firstname AS c1_firstname', $fields[0]);
        $this->assertSame('u.lastname AS c2_lastname', $fields[1]);
        $this->assertStringContainsString(":p4_{$param}", $fields[2]);

        $this->assertSame('c1_firstname', $select->rewrite('c3_firstname'));
        $this->assertSame('c2_lastname', $select->rewrite('c5_lastname'));
        $this->assertSame('c4_flag', $select->rewrite('c4_flag'));
        $this->assertSame('COALESCE(c1_firstname, c2_lastname)',
            $select->rewrite('COALESCE(c3_firstname, c5_lastname)'));
    }

    /**
     * Untouched aliases (and aliases referenced inside larger SQL fragments) pass through rewrite
     * unchanged when they aren't duplicates.
     */
    public function test_rewrite_passthrough(): void {
        $select = report_select::for_columns([
            $this->make_column(1, 'u.firstname', 'firstname'),
            $this->make_column(2, 'u.firstname', 'firstname'),
        ]);

        // Aliases not in the rewrite set are passed through.
        $this->assertSame('c1_firstname, c9_other',
            $select->rewrite('c1_firstname, c9_other'));
    }

    /**
     * Rehydration is a defensive copy: missing canonical keys are simply skipped rather than throwing
     */
    public function test_rehydrate_missing_canonical_silent(): void {
        $select = report_select::for_columns([
            $this->make_column(1, 'u.firstname', 'firstname'),
            $this->make_column(2, 'u.firstname', 'firstname'),
        ]);

        // The canonical c1_firstname is missing from the row; rehydration leaves the row alone
        // rather than producing an undefined key.
        $this->assertSame(['c4_other' => 'kept'],
            $select->rehydrate(['c4_other' => 'kept']));
    }

    /**
     * Group-by collection follows the per-column rule: include a column's GROUP BY fragments only
     * when at least one column is aggregated, and rewrite collapsed aliases inside the fragments.
     */
    public function test_groupby_aggregated_columns(): void {
        $first = $this->make_column(1, 'u.firstname', 'firstname');
        $second = $this->make_column(2, 'u.firstname', 'firstname');
        $aggregated = $this->make_column(3, 'u.id', 'id')->set_aggregation('count');

        $select = report_select::for_columns([$first, $second, $aggregated]);

        $groupby = $select->get_groupby_fields();
        // Both raw columns contribute their group-by SQL (which is just their alias). The duplicate
        // alias is rewritten back to the canonical one.
        $this->assertContains('c1_firstname', $groupby);
        $this->assertNotContains('c2_firstname', $groupby);
    }

    /**
     * Without any aggregated column, group-by fragments are not collected at all
     */
    public function test_groupby_empty_when_not_aggregating(): void {
        $select = report_select::for_columns([
            $this->make_column(1, 'u.firstname', 'firstname'),
            $this->make_column(2, 'u.lastname', 'lastname'),
        ]);

        $this->assertSame([], $select->get_groupby_fields());
    }

    /**
     * forcegroupby=true causes every column's group-by fragments to be collected, regardless of
     * whether any column is aggregated. Used by custom reports configured for unique-row output.
     */
    public function test_groupby_forced(): void {
        $select = report_select::for_columns([
            $this->make_column(1, 'u.firstname', 'firstname'),
            $this->make_column(2, 'u.firstname', 'firstname'),
        ], forcegroupby: true);

        $groupby = $select->get_groupby_fields();
        // The first column's alias appears once; the second's collapses onto it.
        $this->assertContains('c1_firstname', $groupby);
        $this->assertNotContains('c2_firstname', $groupby);
    }
}
