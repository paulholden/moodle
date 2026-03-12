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

namespace report_configlog\reportbuilder\datasource;

use core\clock;
use core\user;
use core_reportbuilder_generator;
use core_reportbuilder\local\filters\{date, text};
use core_reportbuilder\tests\core_reportbuilder_testcase;

/**
 * Unit tests for config changes datasource
 *
 * @package     report_configlog
 * @covers      \report_configlog\reportbuilder\datasource\config_changes
 * @copyright   2026 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config_changes_test extends core_reportbuilder_testcase {
    /** @var clock $clock */
    private readonly clock $clock;

    /**
     * Mock the clock
     */
    protected function setUp(): void {
        parent::setUp();
        $this->clock = $this->mock_clock_with_frozen(1622502000);
    }
    /**
     * Test default datasource
     */
    public function test_datasource_default(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Config', 'source' => config_changes::class, 'default' => 1]);

        // A lot of configuration is set during installation. We want to restrict the report to only those subsequent records.
        add_to_config_log('defaultcity', '', 'Perth', null);
        $this->clock->bump(HOURSECS);
        add_to_config_log('maxsizetodownload', '1024', '2048', 'mod_folder');

        // Ensure we filter for only our own known values.
        $content = $this->get_custom_report_content($report->get('id'), filtervalues: [
            'config_change:timemodified_operator' => date::DATE_RANGE,
            'config_change:timemodified_to' => $this->clock->time() + 1,
        ]);

        // Default columns are time modified, user, plugin, setting, new value, old value. Ordered by time modified descending.
        $this->assertEquals([
            [
                'Tuesday, 1 June 2021, 8:00 AM',
                '<a href="' . user::get_profile_url($user) . '">' .  user::get_fullname($user) . '</a>',
                'mod_folder',
                'maxsizetodownload',
                '2048',
                '1024',
            ],
            [
                'Tuesday, 1 June 2021, 7:00 AM',
                '<a href="' . user::get_profile_url($user) . '">' .  user::get_fullname($user) . '</a>',
                'core',
                'defaultcity',
                'Perth',
                '',
            ],
        ], array_map('array_values', $content));
    }

    /**
     * Test datasource columns that aren't added by default
     */
    public function test_datasource_non_default_columns(): void {
        $this->markTestSkipped('All columns are added by default.');
    }

    /**
     * Data provider for {@see test_datasource_filters}
     *
     * @return array[]
     */
    public static function datasource_filters_provider(): array {
        return [
            // Config change.
            'Config change timemodified' => ['config_change:timemodified', [
                'config_change:timemodified_operator' => date::DATE_RANGE,
                'config_change:timemodified_from' => 1622502000,
            ], true],
            'Config change timemodified (no match)' => ['config_change:timemodified', [
                'config_change:timemodified_operator' => date::DATE_RANGE,
                'config_change:timemodified_to' => 1622502000,
            ], false],
            'Config change plugin' => ['config_change:plugin', [
                'config_change:plugin_operator' => text::IS_EQUAL_TO,
                'config_change:plugin_value' => 'mod_folder',
            ], true],
            'Config change plugin (no match)' => ['config_change:plugin', [
                'config_change:plugin_operator' => text::IS_EQUAL_TO,
                'config_change:plugin_value' => 'core',
            ], false],
            'Config change setting' => ['config_change:setting', [
                'config_change:setting_operator' => text::IS_EQUAL_TO,
                'config_change:setting_value' => 'maxsizetodownload',
            ], true],
            'Config change setting (no match)' => ['config_change:setting', [
                'config_change:setting_operator' => text::IS_EQUAL_TO,
                'config_change:setting_value' => 'showexpanded',
            ], false],
            'Config change new value' => ['config_change:value', [
                'config_change:value_operator' => text::IS_EQUAL_TO,
                'config_change:value_value' => '2048',
            ], true],
            'Config change new value (no match)' => ['config_change:value', [
                'config_change:value_operator' => text::IS_EQUAL_TO,
                'config_change:value_value' => '4096',
            ], false],
            'Config change old value' => ['config_change:oldvalue', [
                'config_change:oldvalue_operator' => text::IS_EQUAL_TO,
                'config_change:oldvalue_value' => '1024',
            ], true],
            'Config change old value (no match)' => ['config_change:oldvalue', [
                'config_change:oldvalue_operator' => text::IS_EQUAL_TO,
                'config_change:oldvalue_value' => '512',
            ], false],

            // User.
            'User firstname' => ['user:firstname', [
                'user:firstname_operator' => text::IS_EQUAL_TO,
                'user:firstname_value' => 'Zoe',
            ], true],
            'User firstname (no match)' => ['user:firstname', [
                'user:firstname_operator' => text::IS_EQUAL_TO,
                'user:firstname_value' => 'Alice',
            ], false],
        ];
    }

    /**
     * Test datasource filters
     *
     * @param string $filtername
     * @param array $filtervalues
     * @param bool $expectmatch
     *
     * @dataProvider datasource_filters_provider
     */
    public function test_datasource_filters(string $filtername, array $filtervalues, bool $expectmatch): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user(['firstname' => 'Zoe']);
        $this->setUser($user);

        $settingname = 'maxsizetodownload';
        add_to_config_log($settingname, '1024', '2048', 'mod_folder');

        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');

        // Create report containing single username column, and given filter.
        $report = $generator->create_report(['name' => 'Config', 'source' => config_changes::class, 'default' => 0]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'config_change:setting']);

        // Add filter, set it's values.
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => $filtername]);
        $content = $this->get_custom_report_content($report->get('id'), 0, $filtervalues);

        // Merge report setting names into easily traversable array.
        $settingnames = array_merge(...array_map('array_values', $content));

        if ($expectmatch) {
            $this->assertContains($settingname, $settingnames);
        } else {
            $this->assertNotContains($settingname, $settingnames);
        }
    }

    /**
     * Stress test datasource
     *
     * In order to execute this test PHPUNIT_LONGTEST should be defined as true in phpunit.xml or directly in config.php
     */
    public function test_stress_datasource(): void {
        if (!PHPUNIT_LONGTEST) {
            $this->markTestSkipped('PHPUNIT_LONGTEST is not defined');
        }

        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        add_to_config_log('maxsizetodownload', '1024', '2048', 'mod_folder');

        $this->datasource_stress_test_columns(config_changes::class);
        $this->datasource_stress_test_columns_aggregation(config_changes::class);
        $this->datasource_stress_test_conditions(config_changes::class, 'config_change:setting');
    }
}
