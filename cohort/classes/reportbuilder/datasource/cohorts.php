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

namespace core_cohort\reportbuilder\datasource;

use core_cohort\reportbuilder\local\entities\{cohort, cohort_member};
use core_course\reportbuilder\local\entities\course_category;
use core\reportbuilder\local\entities\context;
use core_reportbuilder\local\filters\boolean_select;
use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;

/**
 * Cohorts datasource
 *
 * @package     core_cohort
 * @copyright   2021 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohorts extends datasource {

    /**
     * Return user friendly name of the datasource
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cohorts', 'core_cohort');
    }

    /**
     * Initialise report
     */
    protected function initialise(): void {
        $cohortentity = new cohort();

        [
            'cohort' => $cohortalias,
            'context' => $contextalias,
        ] = $cohortentity->get_table_aliases();

        $this->set_main_table('cohort', $cohortalias);
        $this->add_entity($cohortentity);

        // Join the cohort member entity to the cohort entity.
        $cohortmemberentity = new cohort_member();
        $cohortmemberalias = $cohortmemberentity->get_table_alias('cohort_members');
        $this->add_entity($cohortmemberentity
            ->add_join("LEFT JOIN {cohort_members} {$cohortmemberalias} ON {$cohortmemberalias}.cohortid = {$cohortalias}.id"));

        // Join the context entity to the cohort entity.
        $contextentity = (new context())
            ->set_table_alias('context', $contextalias);
        $this->add_entity($contextentity
            ->add_join($cohortentity->get_context_join()));

        // Join the course category entity to the cohort entity.
        $coursecategoryentity = (new course_category())
            ->set_table_join_alias('context', $contextalias);
        $coursecategoryalias = $coursecategoryentity->get_table_alias('course_categories');
        $this->add_entity($coursecategoryentity
            ->add_joins([
                $cohortentity->get_context_join(),
                "LEFT JOIN {course_categories} {$coursecategoryalias} ON {$coursecategoryalias}.id = {$contextalias}.instanceid",
            ]));

        // Join the user entity to the cohort member entity.
        $userentity = new user();
        $useralias = $userentity->get_table_alias('user');
        $this->add_entity($userentity
            ->add_joins($cohortmemberentity->get_joins())
            ->add_join("LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$cohortmemberalias}.userid"));

        // Add all columns/filters/conditions from entities to be available in custom reports.
        $this->add_all_from_entities();
    }

    /**
     * Return the columns that will be added to the report as part of default setup
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'context:name',
            'cohort:name',
            'cohort:idnumber',
            'cohort:description',
        ];
    }

    /**
     * Return the filters that will be added to the report once is created
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'context:level',
            'course_category:name',
            'cohort:name',
        ];
    }

    /**
     * Return the conditions that will be added to the report once is created
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'cohort:visible',
        ];
    }

    /**
     * Return the condition values that will be set for the report upon creation
     *
     * @return array
     */
    public function get_default_condition_values(): array {
        return [
            'cohort:visible_operator' => boolean_select::CHECKED,
        ];
    }

    /**
     * Return the default sorting that will be added to the report once it is created
     *
     * @return array|int[]
     */
    public function get_default_column_sorting(): array {
        return [
            'cohort:name' => SORT_ASC,
        ];
    }
}
