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
use lang_string;
use core_reportbuilder\local\report\filter;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider};

/**
 * Unit tests for select report filter
 *
 * @package     core_reportbuilder
 * @copyright   2021 David Matamoros <davidmc@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(select::class)]
final class select_test extends advanced_testcase {
    /**
     * Data provider for {@see test_get_sql_filter_simple} and {@see test_get_sql_filter_grouped}
     *
     * @return array[]
     */
    public static function get_sql_filter_provider(): array {
        return [
            [select::ANY_VALUE, null, ['PHPUnit test site', 'courseone', 'coursetwo']],
            [select::EQUAL_TO, 'courseone', ['courseone']],
            [select::EQUAL_TO, '', ['PHPUnit test site', 'courseone', 'coursetwo']],
            [select::EQUAL_TO, 'invalid', []],
            [select::NOT_EQUAL_TO, 'courseone', ['PHPUnit test site', 'coursetwo']],
            [select::NOT_EQUAL_TO, '', ['PHPUnit test site', 'courseone', 'coursetwo']],
            [select::NOT_EQUAL_TO, 'invalid', ['PHPUnit test site', 'courseone', 'coursetwo']],
        ];
    }

    /**
     * Test getting filter SQL
     *
     * @param int $operator
     * @param string|null $value
     * @param string[] $expectedcourses
     */
    #[DataProvider('get_sql_filter_provider')]
    public function test_get_sql_filter_simple(int $operator, ?string $value, array $expectedcourses): void {
        global $DB;

        $this->resetAfterTest();

        $course1 = $this->getDataGenerator()->create_course(['fullname' => 'courseone', 'idnumber' => 'courseone']);
        $course2 = $this->getDataGenerator()->create_course(['fullname' => 'coursetwo', 'idnumber' => 'coursetwo']);

        $filter = (new filter(
            select::class,
            'simpletest',
            new lang_string('course'),
            'testentity',
            'idnumber'
        ))->set_options([
            $course1->idnumber => $course1->fullname,
            $course2->idnumber => $course2->fullname,
            'invalid' => 'This is not the course you are looking for',
        ]);

        // Create instance of our filter, passing given operator.
        [$select, $params] = select::create($filter)->get_sql_filter([
            $filter->get_unique_identifier() . '_operator' => $operator,
            $filter->get_unique_identifier() . '_value' => $value,
        ]);

        $coursenames = $DB->get_fieldset_select('course', 'fullname', $select, $params);
        $this->assertEqualsCanonicalizing($expectedcourses, $coursenames);
    }

    /**
     * Test getting filter SQL with grouped (multidimensional) options using non-sequential integer keys
     *
     * @param int $operator
     * @param string|null $value
     * @param string[] $expectedcourses
     */
    #[DataProvider('get_sql_filter_provider')]
    public function test_get_sql_filter_grouped(int $operator, ?string $value, array $expectedcourses): void {
        global $DB;

        $this->resetAfterTest();

        $course1 = $this->getDataGenerator()->create_course(['fullname' => 'courseone']);
        $course2 = $this->getDataGenerator()->create_course(['fullname' => 'coursetwo']);

        $filter = (new filter(
            select::class,
            'groupedtest',
            new lang_string('course'),
            'testentity',
            'id'
        ))->set_options([
            'Group 1' => [$course1->id => $course1->fullname],
            'Group 2' => [$course2->id => $course2->fullname],
            'Group 3' => [999 => 'invalid'],
        ]);

        $value = match ($value) {
            $course1->fullname => (string) $course1->id,
            $course2->fullname => (string) $course2->id,
            'invalid' => '999',
            default => $value,
        };

        [$select, $params] = select::create($filter)->get_sql_filter([
            $filter->get_unique_identifier() . '_operator' => $operator,
            $filter->get_unique_identifier() . '_value' => $value,
        ]);

        $coursenames = $DB->get_fieldset_select('course', 'fullname', $select, $params);
        $this->assertEqualsCanonicalizing($expectedcourses, $coursenames);
    }
}
