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

/**
 * Edit a custom report
 *
 * @package   core_reportbuilder
 * @copyright 2021 David Matamoros <davidmc@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

use core\output\dynamic_tabs;
use core_reportbuilder\manager;
use core_reportbuilder\permission;
use core_reportbuilder\output\dynamictabs\audience;
use core_reportbuilder\output\dynamictabs\editor;

require_once(__DIR__ . '/../config.php');
require_once("{$CFG->libdir}/adminlib.php");

$reportid = required_param('id', PARAM_INT);

admin_externalpage_setup('customreports', null, ['id' => $reportid], new moodle_url('/reportbuilder/edit.php'));

$report = manager::get_report_from_id($reportid);
permission::require_can_edit_report($report->get_report_persistent());

$PAGE->set_context($report->get_context());

$reportname = $report->get_report_persistent()->get_formatted_name();
$PAGE->navbar->add($reportname);
$PAGE->set_title($reportname);
$PAGE->set_heading($reportname);

echo $OUTPUT->header();

// Add dynamic tabs.
$tabdata = ['reportid' => $reportid];
$tabs = [
    new editor($tabdata),
    new audience($tabdata),
];

echo $OUTPUT->render_from_template('core/dynamic_tabs',
    (new dynamic_tabs($tabdata, $tabs))->export_for_template($OUTPUT));

echo $OUTPUT->footer();
