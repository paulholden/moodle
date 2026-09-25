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
use core_reportbuilder_generator;
use core_reportbuilder\local\models\user_filter;
use core_user\reportbuilder\datasource\users;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider};

/**
 * Unit tests for the user filter helper
 *
 * @package     core_reportbuilder
 * @copyright   2021 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(user_filter_manager::class)]
final class user_filter_manager_test extends advanced_testcase {
    /**
     * Data provider for {@see test_get}
     *
     * @return array
     */
    public static function get_provider(): array {
        return [
            'Small value' => ['foo'],
            'Large value' => [str_repeat('A', 4000)],
            'Empty value' => [''],
        ];
    }

    /**
     * Test getting filter values
     *
     * @param string $value
     */
    #[DataProvider('get_provider')]
    public function test_get(string $value): void {
        $this->resetAfterTest();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'My report', 'source' => users::class]);

        $values = [
            'entity:filter_name' => $value,
        ];
        user_filter_manager::set($report->get('id'), $values);

        // Make sure we get the same value back.
        $this->assertEquals($values, user_filter_manager::get($report->get('id')));
    }

    /**
     * Test getting filter values that haven't been set
     */
    public function test_get_empty(): void {
        $this->resetAfterTest();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'My report', 'source' => users::class]);

        $this->assertEquals([], user_filter_manager::get($report->get('id')));
    }

    /**
     * Data provider for {@see test_reset}
     *
     * @return array
     */
    public static function reset_provider(): array {
        return [
            'Small value' => ['foo'],
            'Large value' => [str_repeat('A', 4000)],
            'Empty value' => [''],
        ];
    }

    /**
     * Test resetting all filter values
     *
     * @param string $value
     */
    #[DataProvider('reset_provider')]
    public function test_reset(string $value): void {
        $this->resetAfterTest();

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'My report', 'source' => users::class]);

        user_filter_manager::set($report->get('id'), [
            'entity:filter_name' => $value,
        ]);

        $reset = user_filter_manager::reset($report->get('id'));
        $this->assertTrue($reset);

        // We should get an empty array back.
        $this->assertEquals([], user_filter_manager::get($report->get('id')));

        // All filter preferences should be removed.
        $this->assertFalse(user_filter::get_record(['reportid' => $report->get('id')]));
    }
}
