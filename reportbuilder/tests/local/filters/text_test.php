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

use advanced_testcase;
use core\lang_string;
use core_reportbuilder\local\report\filter;

/**
 * Unit tests for text report filter
 *
 * @package     core_reportbuilder
 * @covers      \core_reportbuilder\local\filters\base
 * @covers      \core_reportbuilder\local\filters\text
 * @copyright   2021 David Matamoros <davidmc@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class text_test extends advanced_testcase {

    /**
     * Data provider for {@see test_get_sql_filter_simple}
     *
     * @return array
     */
    public static function get_sql_filter_simple_provider(): array {
        return [
            [text::ANY_VALUE, 'fullname', null, true],
            [text::CONTAINS, 'fullname', 'looking', true],
            [text::CONTAINS, 'fullname', 'sky', false],
            [text::DOES_NOT_CONTAIN, 'fullname', 'sky', true],
            [text::DOES_NOT_CONTAIN, 'fullname', 'looking', false],
            [text::IS_EQUAL_TO, 'fullname', "Hello, is it me you're looking for?", true],
            [text::IS_EQUAL_TO, 'fullname', 'I can see it in your eyes', false],
            [text::IS_NOT_EQUAL_TO, 'fullname', "Hello, is it me you're looking for?", false],
            [text::IS_NOT_EQUAL_TO, 'fullname', 'I can see it in your eyes', true],
            [text::STARTS_WITH, 'fullname', 'Hello', true],
            [text::STARTS_WITH, 'fullname', 'sunlight', false],
            [text::ENDS_WITH, 'fullname', 'looking for?', true],
            [text::ENDS_WITH, 'fullname', 'your heart', false],

            // Empty content.
            [text::IS_EMPTY, 'pdfexportfont', null, true],
            [text::IS_EMPTY, 'theme', null, true],
            [text::IS_EMPTY, 'fullname', null, false],
            [text::IS_NOT_EMPTY, 'pdfexportfont', null, false],
            [text::IS_NOT_EMPTY, 'theme', null, false],
            [text::IS_NOT_EMPTY, 'fullname', null, true],

            // Ensure whitespace is trimmed.
            [text::CONTAINS, 'fullname', '   looking for   ', true],
            [text::IS_EQUAL_TO, 'fullname', '  Hello, is it me you\'re looking for?  ', true],
            [text::STARTS_WITH, 'fullname', '  Hello, is it me  ', true],
            [text::ENDS_WITH, 'fullname', '  you\'re looking for?  ', true],
        ];
    }

    /**
     * Test getting filter SQL
     *
     * @param int $operator
     * @param string $field
     * @param string|null $value
     * @param bool $expectmatch
     *
     * @dataProvider get_sql_filter_simple_provider
     */
    public function test_get_sql_filter_simple(
        int $operator,
        string $field,
        ?string $value,
        bool $expectmatch,
    ): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course([
            'fullname' => "Hello, is it me you're looking for?",
            // Following fields are considered empty.
            'pdfexportfont' => null,
            'theme' => '',
        ]);

        $filter = new filter(
            text::class,
            'test',
            new lang_string('course'),
            'testentity',
            $field,
        );

        // Create instance of our filter, passing given operator.
        [$select, $params] = text::create($filter)->get_sql_filter([
            $filter->get_unique_identifier() . '_operator' => $operator,
            $filter->get_unique_identifier() . '_value' => $value,
        ]);

        $fullnames = $DB->get_fieldset_select('course', 'fullname', $select, $params);
        if ($expectmatch) {
            $this->assertContains($course->fullname, $fullnames);
        } else {
            $this->assertNotContains($course->fullname, $fullnames);
        }
    }
}
