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

namespace core_reportbuilder\external;

use renderer_base;
use core\external\exporter;
use core_reportbuilder\datasource;
use core_reportbuilder\local\helpers\aggregate_filter;
use core_reportbuilder\local\report\filter;
use core_reportbuilder\output\filter_heading_editable;

/**
 * Custom report filters exporter class
 *
 * @package     core_reportbuilder
 * @copyright   2021 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_report_filters_exporter extends exporter {

    /**
     * Return a list of objects that are related to the exporter
     *
     * @return array
     */
    protected static function define_related(): array {
        return [
            'report' => datasource::class,
        ];
    }

    /**
     * Return the list of additional properties for read structure and export
     *
     * @return array[]
     */
    protected static function define_other_properties(): array {
        return [
            'hasavailablefilters' => [
                'type' => PARAM_BOOL,
            ],
            'availablefilters' => [
                'type' => [
                    'optiongroup' => [
                        'type' => [
                            'text' => ['type' => PARAM_TEXT],
                            'values' => [
                                'type' => [
                                    'value' => ['type' => PARAM_TEXT],
                                    'visiblename' => ['type' => PARAM_TEXT],
                                ],
                                'multiple' => true,
                            ],
                        ],
                    ],
                ],
                'multiple' => true,
            ],
            'hasactivefilters' => [
                'type' => PARAM_BOOL,
            ],
            'activefilters' => [
                'type' => [
                    'id' => ['type' => PARAM_INT],
                    'heading' => ['type' => PARAM_TEXT],
                    'headingeditable' => ['type' => PARAM_RAW],
                    'sortorder' => ['type' => PARAM_INT],
                    'movetitle' => ['type' => PARAM_TEXT],
                    'entityname' => ['type' => PARAM_TEXT],
                ],
                'multiple' => true,
            ],
            'helpicon' => [
                'type' => PARAM_RAW,
            ],
        ];
    }

    /**
     * Get the additional values to inject while exporting
     *
     * @param renderer_base $output
     * @return array
     */
    protected function get_other_values(renderer_base $output): array {
        /** @var datasource $report */
        $report = $this->related['report'];

        // Current filter instances contained in the report.
        $filters = $report->get_active_filters();
        $filteridentifiers = array_map(static function(filter $filter): string {
            return $filter->get_unique_identifier();
        }, $filters);

        $availablefilters = $activefilters = [];

        // Populate available filters.
        foreach ($report->get_filters() as $filter) {

            // Filters can only be added once per report, skip if it already exists.
            if (in_array($filter->get_unique_identifier(), $filteridentifiers) || $filter->get_is_deprecated()) {
                continue;
            }

            $entityname = $filter->get_entity_name();
            if (!array_key_exists($entityname, $availablefilters)) {
                $availablefilters[$entityname] = [
                    'optiongroup' => [
                        'text' => $report->get_entity_title($entityname)->out(),
                        'values' => [],
                    ],
                ];
            }

            $availablefilters[$entityname]['optiongroup']['values'][] = [
                'value' => $filter->get_unique_identifier(),
                'visiblename' => $filter->get_header(),
            ];
        }

        // Populate available aggregate filters from aggregated columns.
        $activecolumns = $report->get_active_columns();
        foreach ($activecolumns as $column) {
            if ($column->get_aggregation() === null) {
                continue;
            }

            $aggregatefilter = aggregate_filter::create_aggregate_filter($column);
            if ($aggregatefilter === null) {
                continue;
            }

            $identifier = $aggregatefilter->get_unique_identifier();

            // Skip if already added as an active filter.
            if (in_array($identifier, $filteridentifiers)) {
                continue;
            }

            if (!array_key_exists('_aggregated', $availablefilters)) {
                $availablefilters['_aggregated'] = [
                    'optiongroup' => [
                        'text' => get_string('aggregatedcolumns', 'core_reportbuilder'),
                        'values' => [],
                    ],
                ];
            }

            $availablefilters['_aggregated']['optiongroup']['values'][] = [
                'value' => $identifier,
                'visiblename' => $aggregatefilter->get_header(),
            ];
        }

        // Populate active filters.
        $filterinstances = $report->get_filter_instances();
        $activefilterreports = $report->get_active_filters();
        foreach ($filterinstances as $identifier => $filterinstance) {
            $persistent = $filterinstance->get_filter_persistent();

            $entityname = $filterinstance->get_entity_name();
            $displayvalue = $filterinstance->get_header();

            // Pass the already-resolved report filter to avoid redundant resolution.
            $resolvedfilter = $activefilterreports[$identifier] ?? null;
            $editable = new filter_heading_editable(0, $persistent, $resolvedfilter);

            $activefilters[] = [
                'id' => $persistent->get('id'),
                'entityname' => $report->get_entity_title($entityname)->out(),
                'heading' => $displayvalue,
                'headingeditable' => $editable->render($output),
                'sortorder' => $persistent->get('filterorder'),
                'movetitle' => get_string('movefilter', 'core_reportbuilder', $displayvalue),
            ];
        }

        return [
            'hasavailablefilters' => !empty($availablefilters),
            'availablefilters' => array_values($availablefilters),
            'hasactivefilters' => !empty($activefilters),
            'activefilters' => $activefilters,
            'helpicon' => $output->help_icon('filters', 'core_reportbuilder'),
        ];
    }
}
