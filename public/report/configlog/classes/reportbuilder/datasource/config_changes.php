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

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use report_configlog\reportbuilder\local\entities\config_change;

/**
 * Config changes custom report class implementation
 *
 * @package     report_configlog
 * @copyright   2026 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config_changes extends datasource {
    /**
     * Return user friendly name of the report source
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('configlog', 'report_configlog');
    }

    /**
     * Initialise report
     */
    protected function initialise(): void {
        $entitymain = new config_change();
        $entitymainalias = $entitymain->get_table_alias('config_log');

        $this->set_main_table('config_log', $entitymainalias);
        $this->add_entity($entitymain);

        // Join the user entity.
        $entityuser = new user();
        $entityuseralias = $entityuser->get_table_alias('user');
        $this->add_entity($entityuser
            ->add_join("LEFT JOIN {user} {$entityuseralias} ON {$entityuseralias}.id = {$entitymainalias}.userid"));

        // Add report elements from each of the entities we added to the report.
        $this->add_all_from_entities([
            $entitymain,
            $entityuser,
        ]);
    }

    /**
     * Return the columns that will be added to the report upon creation
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'config_change:timemodified',
            'user:fullnamewithlink',
            'config_change:plugin',
            'config_change:setting',
            'config_change:newvalue',
            'config_change:oldvalue',
        ];
    }

    /**
     * Return the default sorting that will be added to the report upon creation
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return [
            'config_change:timemodified' => SORT_DESC,
        ];
    }

    /**
     * Return the filters that will be added to the report upon creation
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'config_change:plugin',
            'config_change:setting',
            'config_change:value',
            'config_change:oldvalue',
            'user:fullname',
            'config_change:timemodified',
        ];
    }

    /**
     * Return the conditions that will be added to the report upon creation
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [];
    }
}
