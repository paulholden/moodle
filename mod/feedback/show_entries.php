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
 * print the single entries
 *
 * @author Andreas Grabs
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package mod_feedback
 */

require_once("../../config.php");
require_once("lib.php");

// Get the params.
$id = required_param('id', PARAM_INT);
$deleteid = optional_param('delete', null, PARAM_INT);
$courseid = optional_param('courseid', null, PARAM_INT);

// Get the objects.
list($course, $cm) = get_course_and_cm_from_cmid($id, 'feedback');

$baseurl = new moodle_url('/mod/feedback/show_entries.php', ['id' => $cm->id]);
$PAGE->set_url(new moodle_url($baseurl, ['delete' => $deleteid]));
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
$feedback = $PAGE->activityrecord;

require_capability('mod/feedback:viewreports', $context);

$actionbar = new \mod_feedback\output\responses_action_bar($cm->id, $baseurl);

if ($deleteid) {
    // This is a request to delete a reponse.
    require_capability('mod/feedback:deletesubmissions', $context);
    require_sesskey();
    $feedbackstructure = new mod_feedback_completion($feedback, $cm, 0, true, $deleteid);
    feedback_delete_completed($feedbackstructure->get_completed(), $feedback, $cm);
    redirect($baseurl);
} else {
    // Viewing list of reponses.
    $feedbackstructure = new mod_feedback_structure($feedback, $cm, $courseid);
}

$responsestable = new mod_feedback_responses_table($feedbackstructure);
$anonresponsestable = new mod_feedback_responses_anon_table($feedbackstructure);

if ($responsestable->is_downloading()) {
    $responsestable->download();
}
if ($anonresponsestable->is_downloading()) {
    $anonresponsestable->download();
}

// Process course select form.
$courseselectform = new mod_feedback_course_select_form($baseurl, $feedbackstructure, $feedback->course == SITEID);
if ($data = $courseselectform->get_data()) {
    redirect(new moodle_url($baseurl, ['courseid' => $data->courseid]));
}
// Print the page header.
navigation_node::override_active_url($baseurl);
$PAGE->set_heading($course->fullname);
$PAGE->set_title($feedback->name);
$PAGE->activityheader->set_attrs([
    'hidecompletion' => true,
    'description' => ''
]);
echo $OUTPUT->header();

/** @var \mod_feedback\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_feedback');
echo $renderer->main_action_bar($actionbar);
echo $OUTPUT->heading(get_string('show_entries', 'mod_feedback'), 3);

// Print the list of responses.
$courseselectform->display();

// Show non-anonymous responses (always retrieve them even if current feedback is anonymous).
$totalrows = $responsestable->get_total_responses_count();
if (!$feedbackstructure->is_anonymous() || $totalrows) {
    echo $OUTPUT->heading(get_string('non_anonymous_entries', 'feedback', $totalrows), 4);
    $responsestable->display();
}

// Show anonymous responses (always retrieve them even if current feedback is not anonymous).
$feedbackstructure->shuffle_anonym_responses();
$totalrows = $anonresponsestable->get_total_responses_count();
if ($feedbackstructure->is_anonymous() || $totalrows) {
    echo $OUTPUT->heading(get_string('anonymous_entries', 'feedback', $totalrows), 4);
    $anonresponsestable->display();
}

// Finish the page.
echo $OUTPUT->footer();
