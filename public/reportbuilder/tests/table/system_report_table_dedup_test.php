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
use advanced_testcase;
use core\context\system as system_context;
use core_reportbuilder\system_report_dedup;
use core_reportbuilder\system_report_factory;
use core_reportbuilder\external\system_report_data_exporter;

/**
 * Integration tests for system report SELECT-clause field deduplication
 *
 * @package     core_reportbuilder
 * @covers      \core_reportbuilder\table\system_report_table
 * @covers      \core_reportbuilder\external\system_report_data_exporter
 * @copyright   2026 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class system_report_table_dedup_test extends advanced_testcase {

    /**
     * Load the dedup-fixture system report
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once("{$CFG->dirroot}/reportbuilder/tests/fixtures/system_report_dedup.php");
        parent::setUpBeforeClass();
    }

    /**
     * Build the dedup system report's table and run setup
     *
     * @return system_report_table
     */
    private function build_table(): system_report_table {
        $report = system_report_factory::create(system_report_dedup::class, system_context::instance());
        $table = system_report_table::create($report->get_report_persistent()->get('id'), []);
        $table->guess_base_url();
        $table->setup();
        return $table;
    }

    /**
     * SQL-inspection: a system report containing duplicate fields must emit each field expression
     * at most once in the generated SELECT clause.
     */
    public function test_select_clause_deduplicates_repeated_field(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $table = $this->build_table();
        $method = (new ReflectionClass($table))->getMethod('get_table_sql');
        $sql = (string) $method->invoke($table);

        $this->assertMatchesRegularExpression('/^SELECT (?P<select>.*?) FROM /s', $sql);
        preg_match('/^SELECT (?P<select>.*?) FROM /s', $sql, $matches);

        $this->assertSame(1, substr_count($matches['select'], 'u.firstname'),
            'Duplicate field expression should appear in SELECT clause only once');
    }

    /**
     * Integration: rows formatted by the system report table contain values for every column
     * alias - including those whose SELECT entry was collapsed onto another column's alias.
     */
    public function test_format_row_rehydrates_collapsed_aliases(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Apple']);

        $table = $this->build_table();
        $table->query_db(0, false);

        $rows = [];
        foreach ($table->rawdata as $record) {
            $rows[] = $table->format_row($record);
        }
        $table->close_recordset();

        // Pick the row for our generated user.
        $aliceRow = null;
        foreach ($rows as $row) {
            if (in_array('Alice', $row, true)) {
                $aliceRow = $row;
                break;
            }
        }
        $this->assertNotNull($aliceRow, 'Generated user should appear in row output');

        // Each of the three columns must hold the same firstname value, proving rehydration ran.
        $columns = array_filter($aliceRow, static fn(string $key): bool => str_starts_with($key, 'c'),
            ARRAY_FILTER_USE_KEY);
        $this->assertCount(3, $columns);
        $this->assertSame(['Alice', 'Alice', 'Alice'], array_values($columns));

        $table->close_recordset();
    }

    /**
     * Integration: the external system-report exporter returns a row payload whose shape matches
     * the un-deduplicated baseline - one cell per column, regardless of dedup.
     */
    public function test_external_exporter_payload_shape_unchanged(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Apple']);

        $report = system_report_factory::create(system_report_dedup::class, system_context::instance());
        $exporter = new system_report_data_exporter(null,
            ['report' => $report, 'page' => 0, 'perpage' => 100]);
        $export = $exporter->export($PAGE->get_renderer('core_reportbuilder'));

        $this->assertCount(3, $export->headers);
        $this->assertNotEmpty($export->rows);

        foreach ($export->rows as $row) {
            // Each row must have one value per column.
            $this->assertCount(3, $row['columns']);
            // And in particular all three values for our Alice row should match.
            if (in_array('Alice', $row['columns'], true)) {
                $this->assertSame(['Alice', 'Alice', 'Alice'], $row['columns']);
            }
        }
    }

    /**
     * Integration: sorting on a system-report column whose alias has been collapsed onto another
     * still produces a working ORDER BY referring to the canonical alias.
     */
    public function test_sorting_uses_canonical_alias(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_user(['firstname' => 'Charlie', 'lastname' => 'Cherry']);
        $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Apple']);
        $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'Banana']);

        $table = $this->build_table();

        // Force user-preference sort on the third column, which has been deduplicated onto the first.
        // The ORDER BY rewriting must point this back to the canonical alias.
        $table->set_sortdata([['sortby' => 'c2_firstthree', 'sortorder' => SORT_ASC]]);
        $table->setup();

        $sortsql = $table->get_sql_sort();
        $this->assertStringContainsString('c0_firstone', $sortsql);
        $this->assertStringNotContainsString('c2_firstthree', $sortsql);

        // SQL must execute and return ordered results without error.
        $table->query_db(0, false);
        $names = [];
        foreach ($table->rawdata as $record) {
            $row = (array) $record;
            $names[] = $row['c0_firstone'];
        }
        $table->close_recordset();

        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }
}
