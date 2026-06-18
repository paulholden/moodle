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

namespace core_competency\reportbuilder\local\entities;

use core\lang_string;
use core\output\html_writer;
use core\url;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{date, select, text};
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\{column, filter};
use stdClass;

/**
 * Evidence entity
 *
 * @package     core_competency
 * @copyright   2024 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence extends base {
    /**
     * Database tables that this entity uses
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            'competency_evidence',
        ];
    }

    /**
     * The default title for this entity
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('evidence', 'core_competency');
    }

    /**
     * Returns list of all available columns
     *
     * @return column[]
     */
    protected function get_available_columns(): array {
        $evidencealias = $this->get_table_alias('competency_evidence');

        // Action.
        $columns[] = (new column(
            'action',
            new lang_string('evidenceaction', 'core_competency'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->add_field("{$evidencealias}.action")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $action): string {
                if ($action === null) {
                    return '';
                }
                return get_string("evidenceaction_{$action}", 'core_competency');
            });

        // Description.
        $columns[] = (new column(
            'description',
            new lang_string('description'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->add_fields("{$evidencealias}.descidentifier, {$evidencealias}.desccomponent, {$evidencealias}.desca")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $descidentifier, stdClass $row): string {
                if ($descidentifier === null) {
                    return '';
                }
                $evidence = new \core_competency\evidence(0, $row);
                return (string) $evidence->get_description();
            });

        // URL.
        $columns[] = (new column(
            'url',
            new lang_string('url'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->add_field("{$evidencealias}.url")
            ->set_is_sortable(true)
            ->add_callback(static function (?string $url): string {
                if ($url === null) {
                    return '';
                }
                return html_writer::link(new url($url), $url);
            });

        // Notes.
        $columns[] = (new column(
            'notes',
            new lang_string('evidencenotes', 'core_competency'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->add_field("{$evidencealias}.note")
            ->set_is_sortable(true);

        // Time created.
        $columns[] = (new column(
            'timecreated',
            new lang_string('timecreated', 'core_reportbuilder'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$evidencealias}.timecreated")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Time modified.
        $columns[] = (new column(
            'timemodified',
            new lang_string('timemodified', 'core_reportbuilder'),
            $this->get_entity_name(),
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$evidencealias}.timemodified")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        return $columns;
    }

    /**
     * Return list of all available filters
     *
     * @return filter[]
     */
    protected function get_available_filters(): array {
        $evidencealias = $this->get_table_alias('competency_evidence');

        // Action.
        $filters[] = (new filter(
            select::class,
            'action',
            new lang_string('evidenceaction', 'core_competency'),
            $this->get_entity_name(),
            "{$evidencealias}.action",
        ))
            ->add_joins($this->get_joins())
            ->set_options([
                \core_competency\evidence::ACTION_LOG => get_string('evidenceaction_0', 'core_competency'),
                \core_competency\evidence::ACTION_COMPLETE => get_string('evidenceaction_2', 'core_competency'),
                \core_competency\evidence::ACTION_OVERRIDE => get_string('evidenceaction_3', 'core_competency'),
            ]);

        // Notes.
        $filters[] = (new filter(
            text::class,
            'notes',
            new lang_string('evidencenotes', 'core_competency'),
            $this->get_entity_name(),
            "{$evidencealias}.note",
        ))
            ->add_joins($this->get_joins());

        // Time created.
        $filters[] = (new filter(
            date::class,
            'timecreated',
            new lang_string('timecreated', 'core_reportbuilder'),
            $this->get_entity_name(),
            "{$evidencealias}.timecreated",
        ))
            ->add_joins($this->get_joins());

        // Time modified.
        $filters[] = (new filter(
            date::class,
            'timemodified',
            new lang_string('timemodified', 'core_reportbuilder'),
            $this->get_entity_name(),
            "{$evidencealias}.timemodified",
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
