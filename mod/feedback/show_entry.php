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
 * Print single feedback entry
 *
 * @package     mod_feedback
 * @copyright   2024 Mikel Martín <mikel@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("../../config.php");
require_once("lib.php");

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', false, PARAM_INT);
$showcompleted = optional_param('showcompleted', false, PARAM_INT);

list($course, $cm) = get_course_and_cm_from_cmid($id, 'feedback');

$PAGE->set_url(new moodle_url(
    '/mod/feedback/show_entry.php',
    ['id' => $cm->id, 'userid' => $userid, 'showcompleted' => $showcompleted])
);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
$feedback = $PAGE->activityrecord;

require_capability('mod/feedback:viewreports', $context);

navigation_node::override_active_url(new moodle_url('/mod/feedback/show_entries.php', ['id' => $cm->id]));
$titleparts = [
    $feedback->name,
    format_string($course->fullname),
];
$PAGE->set_title(implode(moodle_page::TITLE_SEPARATOR, $titleparts));
$PAGE->set_heading($course->fullname);
$PAGE->add_body_class('limitedwidth');
echo $OUTPUT->header();

$feedbackstructure = new mod_feedback_completion($feedback, $cm, 0, true, $showcompleted, $userid);
$completedrecord = $feedbackstructure->get_completed();

if ($userid) {
    $usr = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
    $title = userdate($completedrecord->timemodified) . ' (' . fullname($usr) . ')';
} else {
    $title = get_string('response_nr', 'feedback') . ': ' .
            $completedrecord->random_response . ' (' . get_string('anonymous', 'feedback') . ')';
}
// TODO: Improve title?
echo $OUTPUT->heading(get_string('show_entry', 'mod_feedback'), 3);
echo $OUTPUT->heading($title, 4);

$form = new mod_feedback_complete_form(
    mod_feedback_complete_form::MODE_VIEW_RESPONSE,
    $feedbackstructure,
'feedback_viewresponse_form'
);
$form->display();

echo $OUTPUT->footer();
