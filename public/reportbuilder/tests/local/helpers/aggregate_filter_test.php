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
use core_reportbuilder\local\aggregation\avg;
use core_reportbuilder\local\aggregation\count;
use core_reportbuilder\local\aggregation\countdistinct;
use core_reportbuilder\local\aggregation\groupconcat;
use core_reportbuilder\local\aggregation\groupconcatdistinct;
use core_reportbuilder\local\aggregation\max;
use core_reportbuilder\local\aggregation\min;
use core_reportbuilder\local\aggregation\percent;
use core_reportbuilder\local\aggregation\sum;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\text;
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
        $condition = report::add_report_condition(
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
        report::add_report_condition(
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
        report::add_report_condition(
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
        report::add_report_condition(
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

    /**
     * Test COUNT DISTINCT aggregate condition
     */
    public function test_count_distinct_aggregate_condition(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Two cohorts: one with 2 distinct members, one with 1.
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Big']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'Small']);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
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
            'aggregation' => countdistinct::get_class_name(),
        ]);

        // Add aggregate condition: COUNT DISTINCT(username) > 1.
        report::add_report_condition(
            $report->get('id'),
            'user:username:countdistinct',
        );

        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:username:countdistinct_operator' => number::GREATER_THAN,
            'user:username:countdistinct_value1' => 1,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('Big', $values[0]);
    }

    /**
     * Test SUM aggregate condition on boolean column
     */
    public function test_sum_aggregate_condition(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Two cohorts: one with a suspended user, one without.
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Has Suspended']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'No Suspended']);
        $user1 = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $user2 = $this->getDataGenerator()->create_user(['suspended' => 0]);
        cohort_add_member($cohort1->id, $user1->id);
        cohort_add_member($cohort2->id, $user2->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Cohort suspended',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:suspended',
            'aggregation' => sum::get_class_name(),
        ]);

        // Add aggregate condition: SUM(suspended) > 0 (cohorts with suspended users).
        report::add_report_condition(
            $report->get('id'),
            'user:suspended:sum',
        );

        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:suspended:sum_operator' => number::GREATER_THAN,
            'user:suspended:sum_value1' => 0,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('Has Suspended', $values[0]);
    }

    /**
     * Test AVG aggregate condition
     */
    public function test_avg_aggregate_condition(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Two cohorts with different suspended ratios.
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'All Suspended']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'None Suspended']);
        $user1 = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $user2 = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $user3 = $this->getDataGenerator()->create_user(['suspended' => 0]);
        cohort_add_member($cohort1->id, $user1->id);
        cohort_add_member($cohort1->id, $user2->id);
        cohort_add_member($cohort2->id, $user3->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Cohort avg',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:suspended',
            'aggregation' => avg::get_class_name(),
        ]);

        // Add aggregate condition: AVG(suspended) = 0 (cohorts where no one is suspended).
        report::add_report_condition(
            $report->get('id'),
            'user:suspended:avg',
        );

        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:suspended:avg_operator' => number::EQUAL_TO,
            'user:suspended:avg_value1' => 0,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('None Suspended', $values[0]);
    }

    /**
     * Test PERCENT aggregate condition
     */
    public function test_percent_aggregate_condition(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Two cohorts: one with 100% suspended, one with 0%.
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Full Suspended']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'No Suspended']);
        $user1 = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $user2 = $this->getDataGenerator()->create_user(['suspended' => 0]);
        cohort_add_member($cohort1->id, $user1->id);
        cohort_add_member($cohort2->id, $user2->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Cohort percent',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:suspended',
            'aggregation' => percent::get_class_name(),
        ]);

        // Add aggregate condition: PERCENT(suspended) > 50 (majority suspended).
        report::add_report_condition(
            $report->get('id'),
            'user:suspended:percent',
        );

        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:suspended:percent_operator' => number::GREATER_THAN,
            'user:suspended:percent_value1' => 50,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('Full Suspended', $values[0]);
    }

    /**
     * Test multiple aggregate conditions combine with AND in HAVING
     */
    public function test_multiple_aggregate_conditions_and(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Three cohorts with different member counts and suspended ratios.
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Large Active']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'Large Mixed']);
        $cohort3 = $this->getDataGenerator()->create_cohort(['name' => 'Small Active']);

        $user1 = $this->getDataGenerator()->create_user(['suspended' => 0]);
        $user2 = $this->getDataGenerator()->create_user(['suspended' => 0]);
        $user3 = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $user4 = $this->getDataGenerator()->create_user(['suspended' => 0]);

        // Large Active: 2 active users.
        cohort_add_member($cohort1->id, $user1->id);
        cohort_add_member($cohort1->id, $user2->id);
        // Large Mixed: 1 active + 1 suspended.
        cohort_add_member($cohort2->id, $user2->id);
        cohort_add_member($cohort2->id, $user3->id);
        // Small Active: 1 active user.
        cohort_add_member($cohort3->id, $user4->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Multi aggregate',
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

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:suspended',
            'aggregation' => sum::get_class_name(),
        ]);

        // Condition 1: COUNT(username) > 1 (at least 2 members).
        report::add_report_condition(
            $report->get('id'),
            'user:username:count',
        );

        // Condition 2: SUM(suspended) = 0 (no suspended users).
        report::add_report_condition(
            $report->get('id'),
            'user:suspended:sum',
        );

        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:username:count_operator' => number::GREATER_THAN,
            'user:username:count_value1' => 1,
            'user:suspended:sum_operator' => number::EQUAL_TO,
            'user:suspended:sum_value1' => 0,
        ]);

        // Only "Large Active" has 2+ members AND 0 suspended.
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('Large Active', $values[0]);
    }

    /**
     * Test default labels for different numeric aggregation types
     */
    public function test_numeric_aggregation_default_labels(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Label test',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        // Create columns with different numeric aggregations.
        $aggregationtypes = [
            'user:username' => count::class,
            'user:suspended' => sum::class,
        ];

        foreach ($aggregationtypes as $columnid => $aggregationclass) {
            $generator->create_column([
                'reportid' => $report->get('id'),
                'uniqueidentifier' => $columnid,
                'aggregation' => $aggregationclass::get_class_name(),
            ]);
        }

        $instance = manager::get_report_from_persistent($report);
        $activecolumns = $instance->get_active_columns();

        foreach ($activecolumns as $col) {
            if ($col->get_aggregation() === null) {
                continue;
            }

            $filter = aggregate_filter::create_aggregate_filter($col);
            $this->assertNotNull($filter);

            $header = $filter->get_header();
            // All labels should contain parentheses indicating aggregation name.
            $this->assertStringContainsString('(', $header,
                "Label for {$col->get_unique_identifier()} should contain aggregation name");
            $this->assertStringContainsString(')', $header);
        }
    }

    /**
     * Test MIN aggregate condition on timestamp column (date filter)
     */
    public function test_min_timestamp_aggregate_condition(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Two cohorts with members added at different times.
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Old']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'Recent']);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        // Add members — timeadded is set automatically.
        cohort_add_member($cohort1->id, $user1->id);
        cohort_add_member($cohort2->id, $user2->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Min time test',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort_member:timeadded',
            'aggregation' => min::get_class_name(),
        ]);

        // Verify that create_aggregate_filter returns a date filter for MIN on timestamp.
        $instance = manager::get_report_from_persistent($report);
        $activecolumns = $instance->get_active_columns();
        foreach ($activecolumns as $col) {
            if ($col->get_aggregation() !== null) {
                $filter = aggregate_filter::create_aggregate_filter($col);
                $this->assertNotNull($filter);
                $this->assertEquals(
                    \core_reportbuilder\local\filters\date::class,
                    $filter->get_filter_class(),
                );
            }
        }

        // Add aggregate condition: MIN(timeadded) > 0 (has members).
        report::add_report_condition(
            $report->get('id'),
            'cohort_member:timeadded:min',
        );

        // Set date condition: after epoch 0 (effectively "has any member").
        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'cohort_member:timeadded:min_operator' => date::DATE_RANGE,
            'cohort_member:timeadded:min_from' => 1,
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(2, $content);
    }

    /**
     * Test MAX aggregate condition generates correct filter type for timestamps
     */
    public function test_max_timestamp_filter_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Max time test',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort_member:timeadded',
            'aggregation' => max::get_class_name(),
        ]);

        // Verify MAX on timestamp produces a date filter.
        $instance = manager::get_report_from_persistent($report);
        $activecolumns = $instance->get_active_columns();
        foreach ($activecolumns as $col) {
            if ($col->get_aggregation() !== null) {
                $filter = aggregate_filter::create_aggregate_filter($col);
                $this->assertNotNull($filter);
                $this->assertEquals(
                    \core_reportbuilder\local\filters\date::class,
                    $filter->get_filter_class(),
                );
            }
        }
    }

    /**
     * Test MIN/MAX on numeric column generates number filter
     */
    public function test_min_max_numeric_filter_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Min numeric test',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        // MIN on boolean (numeric) column.
        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:suspended',
            'aggregation' => min::get_class_name(),
        ]);

        $instance = manager::get_report_from_persistent($report);
        $activecolumns = $instance->get_active_columns();
        foreach ($activecolumns as $col) {
            if ($col->get_aggregation() !== null) {
                $filter = aggregate_filter::create_aggregate_filter($col);
                $this->assertNotNull($filter);
                // Boolean is preserved by MIN, mapped to boolean_select filter.
                $this->assertEquals(
                    \core_reportbuilder\local\filters\boolean_select::class,
                    $filter->get_filter_class(),
                );
            }
        }
    }

    /**
     * Test GROUP_CONCAT aggregate condition with text filter
     */
    public function test_groupconcat_aggregate_condition(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'TestCohort']);
        $user1 = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $user2 = $this->getDataGenerator()->create_user(['username' => 'bob']);
        cohort_add_member($cohort1->id, $user1->id);
        cohort_add_member($cohort1->id, $user2->id);

        // Create a second cohort with a different member.
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'OtherCohort']);
        $user3 = $this->getDataGenerator()->create_user(['username' => 'charlie']);
        cohort_add_member($cohort2->id, $user3->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Group concat test',
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
            'aggregation' => groupconcat::get_class_name(),
        ]);

        // Verify GROUP_CONCAT produces a text filter.
        $instance = manager::get_report_from_persistent($report);
        $activecolumns = $instance->get_active_columns();
        foreach ($activecolumns as $col) {
            if ($col->get_aggregation() !== null) {
                $filter = aggregate_filter::create_aggregate_filter($col);
                $this->assertNotNull($filter);
                $this->assertEquals(
                    \core_reportbuilder\local\filters\text::class,
                    $filter->get_filter_class(),
                );
            }
        }

        // Add text condition: GROUP_CONCAT(username) contains 'alice'.
        report::add_report_condition(
            $report->get('id'),
            'user:username:groupconcat',
        );

        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:username:groupconcat_operator' => text::CONTAINS,
            'user:username:groupconcat_value' => 'alice',
        ]);

        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('TestCohort', $values[0]);
    }

    /**
     * Test GROUP_CONCAT DISTINCT generates text filter
     */
    public function test_groupconcatdistinct_filter_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Group concat distinct test',
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
            'aggregation' => groupconcatdistinct::get_class_name(),
        ]);

        $instance = manager::get_report_from_persistent($report);
        $activecolumns = $instance->get_active_columns();
        foreach ($activecolumns as $col) {
            if ($col->get_aggregation() !== null) {
                $filter = aggregate_filter::create_aggregate_filter($col);
                $this->assertNotNull($filter);
                $this->assertEquals(
                    \core_reportbuilder\local\filters\text::class,
                    $filter->get_filter_class(),
                );
            }
        }
    }

    /**
     * Test aggregate filter (viewer-facing) routes to HAVING
     */
    public function test_aggregate_filter_viewer(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Big']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'Small']);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort1->id, $user1->id);
        cohort_add_member($cohort1->id, $user2->id);
        cohort_add_member($cohort2->id, $user1->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Filter test',
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

        // Add aggregate filter (not condition).
        report::add_report_filter(
            $report->get('id'),
            'user:username:count',
        );

        // Without setting filter values, should show all cohorts (default = "Any value").
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(2, $content);

        // Set filter value: COUNT > 1.
        $content = $this->get_custom_report_content($report->get('id'), 30, [
            'user:username:count_operator' => number::GREATER_THAN,
            'user:username:count_value1' => 1,
        ]);

        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('Big', $values[0]);
    }

    /**
     * Test aggregate filters and conditions coexist on the same report
     */
    public function test_aggregate_filter_and_condition_coexist(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Three cohorts with different member counts and suspended users.
        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'AllActive']);
        $cohort2 = $this->getDataGenerator()->create_cohort(['name' => 'Mixed']);
        $cohort3 = $this->getDataGenerator()->create_cohort(['name' => 'Solo']);

        $user1 = $this->getDataGenerator()->create_user(['suspended' => 0]);
        $user2 = $this->getDataGenerator()->create_user(['suspended' => 0]);
        $user3 = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $user4 = $this->getDataGenerator()->create_user(['suspended' => 0]);

        // AllActive: 2 active users.
        cohort_add_member($cohort1->id, $user1->id);
        cohort_add_member($cohort1->id, $user2->id);
        // Mixed: 1 active + 1 suspended.
        cohort_add_member($cohort2->id, $user2->id);
        cohort_add_member($cohort2->id, $user3->id);
        // Solo: 1 active user.
        cohort_add_member($cohort3->id, $user4->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Coexist test',
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

        $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:suspended',
            'aggregation' => sum::get_class_name(),
        ]);

        // Condition (always-on): COUNT(username) > 1 — excludes Solo.
        report::add_report_condition(
            $report->get('id'),
            'user:username:count',
        );

        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:username:count_operator' => number::GREATER_THAN,
            'user:username:count_value1' => 1,
        ]);

        // Filter (viewer-facing): SUM(suspended) = 0 — only fully active cohorts.
        report::add_report_filter(
            $report->get('id'),
            'user:suspended:sum',
        );

        // Only AllActive: has 2+ members AND 0 suspended.
        $content = $this->get_custom_report_content($report->get('id'), 30, [
            'user:suspended:sum_operator' => number::EQUAL_TO,
            'user:suspended:sum_value1' => 0,
        ]);

        $this->assertCount(1, $content);

        $values = array_values(reset($content));
        $this->assertEquals('AllActive', $values[0]);
    }

    /**
     * Test aggregate condition is ignored when aggregation type changes
     */
    public function test_aggregate_condition_ignored_on_aggregation_change(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Test']);
        $user1 = $this->getDataGenerator()->create_user();
        cohort_add_member($cohort1->id, $user1->id);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Lifecycle test',
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

        // Add a COUNT aggregate condition.
        report::add_report_condition(
            $report->get('id'),
            'user:username:count',
        );

        $instance = manager::get_report_from_persistent($report);
        $instance->set_condition_values([
            'user:username:count_operator' => number::GREATER_THAN,
            'user:username:count_value1' => 0,
        ]);

        // Verify it works with COUNT.
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertCount(1, $content);

        // Change aggregation from COUNT to COUNT DISTINCT.
        $usernamecolumn->set('aggregation', countdistinct::get_class_name())->update();
        manager::reset_caches();

        // The old COUNT condition should be silently ignored (aggregation mismatch).
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertIsArray($content);

        // Verify the condition record still exists in the database.
        $conditions = filter_model::get_condition_records($report->get('id'));
        $identifiers = array_map(fn($c) => $c->get('uniqueidentifier'), $conditions);
        $this->assertContains('user:username:count', $identifiers);
    }

    /**
     * Test aggregate condition is ignored when column is removed entirely
     */
    public function test_aggregate_condition_ignored_on_column_removal(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cohort1 = $this->getDataGenerator()->create_cohort(['name' => 'Test']);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        $report = $generator->create_report([
            'name' => 'Column removal test',
            'source' => \core_cohort\reportbuilder\datasource\cohorts::class,
            'default' => 0,
        ]);

        $namecolumn = $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'cohort:name',
        ]);

        $usernamecolumn = $generator->create_column([
            'reportid' => $report->get('id'),
            'uniqueidentifier' => 'user:username',
            'aggregation' => count::get_class_name(),
        ]);

        // Add aggregate condition.
        report::add_report_condition(
            $report->get('id'),
            'user:username:count',
        );

        // Remove the aggregated column entirely.
        $usernamecolumn->delete();
        manager::reset_caches();

        // Report should still work — the aggregate condition is silently ignored.
        // Note: without the aggregated column, there's no GROUP BY, so results are unfiltered.
        $content = $this->get_custom_report_content($report->get('id'));
        $this->assertIsArray($content);

        // Verify the condition record still exists.
        $conditions = filter_model::get_condition_records($report->get('id'));
        $identifiers = array_map(fn($c) => $c->get('uniqueidentifier'), $conditions);
        $this->assertContains('user:username:count', $identifiers);
    }
}
