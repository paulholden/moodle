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

use core_reportbuilder_generator;
use core_reportbuilder\manager;
use core_reportbuilder\local\aggregation\count;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\models\filter as filter_model;
use core_reportbuilder\local\report\column;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use core_user\reportbuilder\datasource\users;

/**
 * Unit tests for aggregate filter helper
 *
 * @package     core_reportbuilder
 * @covers      \core_reportbuilder\local\helpers\aggregate_filter
 * @copyright   2025 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class aggregate_filter_test extends core_reportbuilder_testcase {

    /**
     * Test identifying aggregate identifiers
     */
    public function test_is_aggregate_identifier(): void {
        $this->assertTrue(aggregate_filter::is_aggregate_identifier('user:username:count'));
        $this->assertTrue(aggregate_filter::is_aggregate_identifier('cohort:name:sum'));
        $this->assertFalse(aggregate_filter::is_aggregate_identifier('user:username'));
        $this->assertFalse(aggregate_filter::is_aggregate_identifier('username'));
    }

    /**
     * Test parsing aggregate identifiers
     */
    public function test_parse_identifier(): void {
        $parsed = aggregate_filter::parse_identifier('user:username:count');
        $this->assertEquals([
            'entity' => 'user',
            'column' => 'username',
            'aggregation' => 'count',
        ], $parsed);

        $this->assertNull(aggregate_filter::parse_identifier('user:username'));
    }

    /**
     * Test building aggregate identifiers
     */
    public function test_build_identifier(): void {
        $this->assertEquals('user:username:count', aggregate_filter::build_identifier('user', 'username', 'count'));
    }

    /**
     * Test filter class mapping for column types
     */
    public function test_get_filter_class_for_type(): void {
        $this->assertEquals(
            \core_reportbuilder\local\filters\number::class,
            aggregate_filter::get_filter_class_for_type(column::TYPE_INTEGER),
        );
        $this->assertEquals(
            \core_reportbuilder\local\filters\number::class,
            aggregate_filter::get_filter_class_for_type(column::TYPE_FLOAT),
        );
        $this->assertEquals(
            \core_reportbuilder\local\filters\date::class,
            aggregate_filter::get_filter_class_for_type(column::TYPE_TIMESTAMP),
        );
        $this->assertEquals(
            \core_reportbuilder\local\filters\text::class,
            aggregate_filter::get_filter_class_for_type(column::TYPE_TEXT),
        );
    }

    /**
     * Test COUNT aggregate condition filters report results
     */
    public function test_count_aggregate_condition(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create two cohorts: one with 2 members, one with 0 members.
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Cohort A']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'Cohort B']);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort1->id, $user1->id);
        cohort_add_member($cohort1->id, $user2->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Cohort members',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        // Add cohort name column.
        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        // Add user username column with COUNT aggregation.
        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:username',
            'aggregation' => count::get_class_name(),
        ]);

        // Without aggregate condition - should show both cohorts.
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(2, $content);

        // Add aggregate condition: COUNT(username) > 0 (show only non-empty cohorts).
        $condition = report::add_report_aggregate_condition(
            $report->get('id'),
            'user:username:count',
        );

        // Set condition value: greater than 0.
        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:username:count_operator' => number::GREATER_THAN,
            'user:username:count_value1' => 0,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('Cohort A', $values[0]);
        $this->assertEquals(2, $values[1]);
    }

    /**
     * Test COUNT aggregate condition with EQUAL_TO operator for zero count
     */
    public function test_count_aggregate_condition_zero(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create two cohorts: one with members, one empty.
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Full Cohort']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'Empty Cohort']);
        $user1 = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort1->id, $user1->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Cohort members',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:username',
            'aggregation' => count::get_class_name(),
        ]);

        // Add aggregate condition: COUNT(fullname) = 0 (empty cohorts only).
        report::add_report_aggregate_condition(
            $report->get('id'),
            'user:username:count',
        );

        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:username:count_operator' => number::EQUAL_TO,
            'user:username:count_value1' => 0,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('Empty Cohort', $values[0]);
    }

    /**
     * Test aggregate condition coexists with regular WHERE condition
     */
    public function test_aggregate_condition_with_regular_condition(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create cohorts in different contexts.
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Alpha']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'Beta']);
        $cohort3 = $this->getDataGenerator()->create_cohort(['name' => 'Gamma']);

        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        // Alpha: 2 members, Beta: 1 member, Gamma: 0 members.
        cohort_add_member($cohort1->id, $user1->id);
        cohort_add_member($cohort1->id, $user2->id);
        cohort_add_member($cohort2->id, $user1->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Cohort members',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:username',
            'aggregation' => count::get_class_name(),
        ]);

        // Add regular condition: cohort name contains 'a' (matches Alpha and Gamma).
        $generator->create_condition([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        // Add aggregate condition: COUNT > 0.
        report::add_report_aggregate_condition(
            $report->get('id'),
            'user:username:count',
        );

        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            // Regular condition: name contains 'lph' (only matches Alpha).
            'cohort:name_operator' => \core_reportbuilder\local\filters\text::CONTAINS,
            'cohort:name_value' => 'lph',
            // Aggregate condition: count > 0.
            'user:username:count_operator' => number::GREATER_THAN,
            'user:username:count_value1' => 0,
        ]);

        // Should return only Alpha (matches name filter AND has members).
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('Alpha', $values[0]);
    }

    /**
     * Test invalid aggregate condition is silently ignored
     */
    public function test_invalid_aggregate_condition_ignored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Test']);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Cohort members',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        $usernamecolumn = $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:username',
            'aggregation' => count::get_class_name(),
        ]);

        // Add aggregate condition.
        report::add_report_aggregate_condition(
            $report->get('id'),
            'user:username:count',
        );

        // Remove the aggregation from the column — the condition should be silently ignored.
        $usernamecolumn->set('aggregation', null)->update();

        // Reset caches so the report picks up changes.
        manager::reset_caches();

        // Report should still work — the aggregate condition is silently ignored.
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertIsArray($content);
    }

    /**
     * Test aggregate condition default label format
     */
    public function test_aggregate_filter_default_label(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Test report',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:username',
            'aggregation' => count::get_class_name(),
        ]);

        $instance = manager::get_report_from_persistent($report);
        $activecolumns = $instance->get_active_columns();

        // Find the aggregated column.
        $aggregatedcolumn = null;
        foreach ($activecolumns as $col) {
            if ($col->get_aggregation() !== null) {
                $aggregatedcolumn = $col;
                break;
            }
        }
        $this->assertNotNull($aggregatedcolumn);

        $filter = aggregate_filter::create_aggregate_filter($aggregatedcolumn);
        $this->assertNotNull($filter);

        // The label should be in "ColumnName (Aggregation)" format.
        $header = $filter->get_header();
        $this->assertStringContainsString('(', $header);
        $this->assertStringContainsString(')', $header);
    }
}
