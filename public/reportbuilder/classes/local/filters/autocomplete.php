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

namespace core_reportbuilder\local\filters;

use MoodleQuickForm;
use core_reportbuilder\local\helpers\database;

/**
 * Autocomplete report filter
 *
 * @package     core_reportbuilder
 * @copyright   2022 Nathan Nguyen <nathannguyen@catalyst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class autocomplete extends base {
    /** @var int Equal to */
    public const EQUAL_TO = 1;

    /** @var int Not equal to */
    public const NOT_EQUAL_TO = 2;

    /**
     * Return the options for the filter as an array, to be used to populate the select input field
     *
     * @return array
     */
    protected function get_select_options(): array {
        return (array) $this->filter->get_options();
    }

    /**
     * Setup form
     *
     * @param MoodleQuickForm $mform
     */
    public function setup_form(MoodleQuickForm $mform): void {
        $mform->addElement(
            'autocomplete',
            "{$this->name}_value",
            get_string('filterfieldvalue', 'core_reportbuilder', $this->get_header()),
            [0 => ''] + $this->get_select_options(),
            ['multiple' => true],
        )->setHiddenLabel(true);

        $mform->addElement(
            'advcheckbox',
            "{$this->name}_operator",
            get_string('filterisnotequalto', 'core_reportbuilder'),
            null,
            null,
            [self::EQUAL_TO, self::NOT_EQUAL_TO],
        );
    }

    /**
     * Return filter SQL
     *
     * @param array $values
     * @return array
     */
    public function get_sql_filter(array $values): array {
        global $DB;

        $fieldsql = $this->filter->get_field_sql();
        $params = $this->filter->get_field_params();

        $operator = (int) ($values["{$this->name}_operator"] ?? self::EQUAL_TO);

        // Ensure all filter values are valid options.
        $options = $this->get_select_options();
        $invalues = array_filter(
            (array) ($values["{$this->name}_value"] ?? []),
            fn(string $value): bool => array_key_exists($value, $options),
        );

        // Validate filter form values.
        if (empty($invalues)) {
            return ['', []];
        }

        [$insql, $inparams] = $DB->get_in_or_equal(
            $invalues,
            SQL_PARAMS_NAMED,
            database::generate_param_name('_'),
            $operator === self::EQUAL_TO,
        );

        return ["{$fieldsql} $insql", array_merge($params, $inparams)];
    }

    /**
     * Return sample filter values
     *
     * @return array
     */
    public function get_sample_values(): array {
        return [
            "{$this->name}_value" => [1],
        ];
    }
}
