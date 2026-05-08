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

namespace core_reportbuilder\table;

use ReflectionClass;
use core_reportbuilder_generator;
use core_reportbuilder\local\helpers\report;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use core_user\reportbuilder\datasource\users;

/**
 * Integration tests for custom report SELECT-clause field deduplication
 *
 * @package     core_reportbuilder
 * @covers      \core_reportbuilder\table\custom_report_table
 * @covers      \core_reportbuilder\table\base_report_table
 * @covers      \core_reportbuilder\local\report\report_select
 * @copyright   2026 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class custom_report_table_dedup_test extends core_reportbuilder_testcase {

    /**
     * Build a custom report with the given column identifiers (in order) and return the report ID
     * and ready-to-use table instance.
     *
     * @param string[] $columnidentifiers
     * @param array<int, int> $sortdirections Sort directions keyed by zero-based column position
     * @return array{int, custom_report_table_view}
     */
    private function build_report(array $columnidentifiers, array $sortdirections = []): array {
        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $persistent = $generator->create_report(['name' => 'Dup', 'source' => users::class, 'default' => 0]);

        foreach ($columnidentifiers as $position => $identifier) {
            $col = report::add_report_column($persistent->get('id'), $identifier);
            if (isset($sortdirections[$position])) {
                report::toggle_report_column_sorting(
                    $persistent->get('id'),
                    $col->get('id'),
                    true,
                    $sortdirections[$position],
                );
            }
        }

        $table = custom_report_table_view::create($persistent->get('id'));
        $table->setup();
        return [$persistent->get('id'), $table];
    }

    /**
     * Reflect the protected {@see base_report_table::get_table_sql} method
     *
     * @param custom_report_table_view $table
     * @return string
     */
    private function get_table_sql(custom_report_table_view $table): string {
        $method = (new ReflectionClass($table))->getMethod('get_table_sql');
        return (string) $method->invoke($table);
    }

    /**
     * SQL-inspection: a report whose columns share the same underlying field expression must emit
     * that expression only once in the generated SELECT clause.
     */
    public function test_select_clause_deduplicates_repeated_field(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Apple']);

        [, $table] = $this->build_report(['user:firstname', 'user:firstname', 'user:firstname']);
        $sql = $this->get_table_sql($table);

        $this->assertMatchesRegularExpression('/^SELECT (?P<select>.*?) FROM /s', $sql, 'SELECT clause not found');
        preg_match('/^SELECT (?P<select>.*?) FROM /s', $sql, $matches);
        $select = $matches['select'];

        $this->assertSame(1, substr_count($select, '.firstname'),
            'Duplicate field expression should appear in SELECT clause only once');
    }

    /**
     * Integration: row payload shape and values are unchanged after deduplication - both columns
     * see and format the same value, even though only one alias appears in the SELECT.
     */
    public function test_format_row_rehydrates_collapsed_aliases(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Apple']);
        $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Banana']);

        [$reportid] = $this->build_report(['user:firstname', 'user:firstname']);
        $content = $this->get_custom_report_content($reportid);

        $values = array_map('array_values', $content);
        sort($values);

        $this->assertSame([
            ['Admin', 'Admin'],
            ['Alice', 'Alice'],
            ['Bob', 'Bob'],
        ], $values);
    }

    /**
     * Integration: sorting on a column whose field has been collapsed onto another column's alias
     * still produces a correctly-ordered ORDER BY referring to the canonical alias.
     */
    public function test_sorting_uses_canonical_alias(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['firstname' => 'Charlie', 'lastname' => 'Cherry']);
        $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Apple']);
        $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Banana']);

        // Two firstname columns; sort by the second one (whose alias gets collapsed onto the first).
        [$reportid, $table] = $this->build_report(
            ['user:firstname', 'user:firstname'],
            [1 => SORT_ASC],
        );

        // Indexes are zero-based, so the canonical alias is c0_firstname and the collapsed one is c1_firstname.
        $sortsql = $table->get_sql_sort();
        $this->assertStringContainsString('c0_firstname', $sortsql);
        $this->assertStringNotContainsString('c1_firstname', $sortsql);

        $content = $this->get_custom_report_content($reportid);
        $firstcolumn = array_map(static fn(array $row): string => reset($row), $content);
        $this->assertSame(['Admin', 'Alice', 'Bob', 'Charlie'], $firstcolumn);
    }

    /**
     * SQL-inspection: aggregated columns aren't collapsed against raw-field columns sharing
     * the same source field.
     */
    public function test_aggregated_column_not_collapsed_against_raw(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Apple']);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $persistent = $generator->create_report(['name' => 'AggDup', 'source' => users::class, 'default' => 0]);

        $generator->create_column(['reportid' => $persistent->get('id'), 'uniqueidentifier' => 'user:firstname']);
        $generator->create_column([
            'reportid' => $persistent->get('id'),
            'uniqueidentifier' => 'user:firstname',
            'aggregation' => \core_reportbuilder\local\aggregation\count::get_class_name(),
        ]);

        $table = custom_report_table_view::create($persistent->get('id'));
        $table->setup();
        $sql = $this->get_table_sql($table);

        // The aggregated column must remain in the SELECT alongside the raw one.
        $this->assertMatchesRegularExpression('/COUNT\s*\(/i', $sql);
    }
}
