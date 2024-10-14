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

namespace mod_feedback\reportbuilder\local\systemreports;

use core\context\{course, system};
use mod_feedback\reportbuilder\local\entities\response;
use mod_feedback\reportbuilder\local\entities\question;
use mod_feedback\reportbuilder\local\entities\question_value;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\{action, column};
use core_reportbuilder\system_report;
use html_writer;
use lang_string;
use moodle_url;
use pix_icon;
use stdClass;

defined('MOODLE_INTERNAL') || die;

global $CFG;

/**
 * Feedback responses system report class implementation
 *
 * @package    mod_feedback
 * @copyright  2024 Mikel Martín <mikel@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class responses extends system_report {

    /** @var int $responseid The ID of the current response row */
    private int $responseid;

    /**
     * Initialise report, we need to set the main table, load our entities and set columns/filters
     */
    protected function initialise(): void {
        global $USER;

        $entityresponse = new response();
        $entityresponsealias = $entityresponse->get_table_alias('feedback_completed');

        $this->set_main_table('feedback_completed', $entityresponsealias);
        $this->add_entity($entityresponse);

        $entityuser = new user();
        $entityuseralias = $entityuser->get_table_alias('user');
        $this->add_entity($entityuser->add_join(
            "LEFT JOIN {user} {$entityuseralias} ON {$entityuseralias}.id = {$entityresponsealias}.userid"
        ));

        // Any columns required by actions should be defined here to ensure they're always available.
        $this->add_base_fields("{$entityresponsealias}.id");

        $this->add_columns();
        $this->add_filters();
        $this->add_actions();

        // $this->set_initial_sort_column('response:timemodified', SORT_ASC);
    }

    /**
     * Validates access to view this report
     *
     * @return bool
     */
    protected function can_view(): bool {
        // TODO: Implement can_view() method.
        return true;
    }

    /**
     * Adds the columns we want to display in the report
     *
     * They are all provided by the entities we previously added in the {@see initialise} method, referencing each by their
     * unique identifier
     *
     * @param string $entityuseralias
     * @param string $entityservicealias
     */
    public function add_columns(): void {
        $this->add_columns_from_entities([
            'user:fullnamewithlink',
        ]);

        // Include all identity field columns.
        $identitycolumns = $this->get_entity('user')->get_identity_columns($this->get_context());
        foreach ($identitycolumns as $identitycolumn) {
            $this->add_column($identitycolumn);
        }

        // TODO: Add the rest of the columns, including the ones from the question entity.
    }

    /**
     * Adds the filters we want to display in the report
     *
     * They are all provided by the entities we previously added in the {@see initialise} method, referencing each by their
     * unique identifier
     */
    protected function add_filters(): void {
        $filters = [
            'user:fullname',
        ];

        $this->add_filters_from_entities($filters);

        $this->get_filter('user:fullname')
            ->set_header(new lang_string('user'));
    }

    /**
     * Add the system report actions. An extra column will be appended to each row, containing all actions added here
     *
     * Note the use of ":id" placeholder which will be substituted according to actual values in the row
     */
    protected function add_actions(): void {

        // Action to delete token.
        $this->add_action((new action(
            new moodle_url('/admin/webservice/tokens.php', [
                'action' => 'delete',
                'responseid' => ':id',
            ]),
            new pix_icon('t/delete', '', 'core'),
            ['class' => 'text-danger'],
            false,
            new lang_string('delete', 'core')
        )));
        // TODO: Add JS and WS to perform the delete action.

        // TODO: Add the view action.
    }
}
