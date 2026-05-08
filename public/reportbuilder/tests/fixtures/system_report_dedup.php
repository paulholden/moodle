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

namespace core_reportbuilder;

use lang_string;
use core_reportbuilder\local\report\column;

/**
 * System report fixture with deliberately overlapping field expressions across multiple columns,
 * used to exercise SELECT-clause field deduplication.
 *
 * @package     core_reportbuilder
 * @copyright   2026 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class system_report_dedup extends system_report {

    /**
     * Initialise the report with three columns whose field expressions are intentionally identical
     */
    protected function initialise(): void {
        $this->set_main_table('user', 'u');
        $this->annotate_entity('user', new lang_string('user'));

        // Three columns sharing the same underlying field expression (u.firstname). Two of them
        // must be collapsed in the SELECT, while still formatting and exporting correctly.
        foreach (['firstone', 'firsttwo', 'firstthree'] as $name) {
            $this->add_column((new column($name, new lang_string('user'), 'user'))
                ->add_joins($this->get_joins())
                ->add_field('u.firstname', $name)
                ->set_is_sortable(true));
        }
    }

    /**
     * Ensure we can view the report
     *
     * @return bool
     */
    protected function can_view(): bool {
        return true;
    }

    /**
     * Always available
     *
     * @return bool
     */
    public static function is_available(): bool {
        return true;
    }
}
