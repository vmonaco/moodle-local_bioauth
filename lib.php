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
 * Library of functions for the bioauth module.
 *
 * This contains functions that are called also from outside the biaouth module
 * Functions that are only called by the biaouth module itself are in {@link locallib.php}
 *
 * @package    local_bioauth
 * @copyright  Vinnie Monaco
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/bioauth/locallib.php');

function local_bioauth_cron() {
    global $DB;
    
    // While jobs are waiting for data, check to see if enough data has been collected.
    // Run the job if enough data has been collected and there is newly discovered data.
    
    $jobsvoid = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_VOID));
    foreach ($jobsvoid as $idx => $job) {
        // delete job
    }
    
    $jobswaiting = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_WAITING));
    foreach ($jobswaiting as $idx => $job) {
        if (job_enough_data($job)) {
            // If a job has enough data, mark it as ready
        }
    }
    
    $jobsmonitor = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_MONITOR));
    foreach ($jobswaiting as $idx => $job) {
        if (job_enough_new_data($job)) {
            // If a job has enough NEW data, mark it as ready
        }
    }
    
    $jobsready = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_READY));
    foreach ($jobsready as $idx => $job) {
        // run job, gets marked as complete afterwards
    }
    
    $jobsrunning = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_RUNNING));
    foreach ($jobsready as $idx => $job) {
        // do nothing
    }
    
    $jobscomplete = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_COMPLETE));
    foreach ($jobsready as $idx => $job) {
        // check job settings - if monitor flag is set and time has not expired, put back into monitor state
    }
}

function bioauth_extends_navigation(global_navigation $navigation) {
    $bioauthnode = $navigation->add(get_string('pluginname', 'local_bioauth'));
    $reportnode = $bioauthnode->add(get_string('report', 'local_bioauth'), new moodle_url('/local/bioauth/report/index.php'));
    $settingsnode = $bioauthnode->add(get_string('settings', 'local_bioauth'));
}

function run_validation($course) {
    global $CFG;
    
    // $output = shell_exec('nohup java -Xmx512m -jar '.$CFG->dirroot.'/local/bioauth/bin/ssi.jar localhost moodle root ziggy mdl_ 2 1 11 5 &');
    $output = shell_exec('java -Xmx512m -jar '.$CFG->dirroot.'/local/bioauth/bin/ssi.jar localhost moodle root ziggy mdl_ 2 1 11 5');
    file_put_contents('/Users/vinnie/output.txt', print_r($output, true));
}

function bioauth_create_job($course, $target) {
    
}

function bioauth_enable_course($courseid) {
    // TODO: check capabilites
    
    
    // Create all the necessary jobs for the course (quiz, etc)
    
}

function bioauth_disable_course($courseid) {
    // Delete any jobs and validations for the course
    
}

/**
 * Return a textual summary of the number of attempts that have been made at a particular quiz,
 * returns '' if no attempts have been made yet, unless $returnzero is passed as true.
 *
 * @param object $quiz the quiz object. Only $quiz->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param bool $returnzero if false (default), when no attempts have been
 *      made '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string a string like "Attempts: 123"
 */
function bioauth_performance_summary($validation, $course) {
    global $DB, $USER;

    $a = new stdClass();
    $a->performance = number_format(100 - $validation->eer, 2);
    $a->numauths = $DB->count_records('bioauth_quiz_neighbors', array('courseid'=> $course->id));
    return get_string('performancesummary', 'local_bioauth', $a);
}

/**
 * Prints the page headers, breadcrumb trail, page heading, (optional) dropdown navigation menu and
 * (optional) navigation tabs for any gradebook page. All gradebook pages MUST use these functions
 * in favour of the usual print_header(), print_header_simple(), print_heading() etc.
 * !IMPORTANT! Use of tabs.php file in gradebook pages is forbidden unless tabs are switched off at
 * the site level for the gradebook ($CFG->grade_navmethod = GRADE_NAVMETHOD_DROPDOWN).
 *
 * @param int     $courseid Course id
 * @param string  $active_type The type of the current page (report, settings,
 *                             import, export, scales, outcomes, letters)
 * @param string  $active_plugin The plugin of the current page (grader, fullview etc...)
 * @param string  $heading The heading of the page. Tries to guess if none is given
 * @param boolean $return Whether to return (true) or echo (false) the HTML generated by this function
 * @param string  $bodytags Additional attributes that will be added to the <body> tag
 * @param string  $buttons Additional buttons to display on the page
 * @param boolean $shownavigation should the gradebook navigation drop down (or tabs) be shown?
 *
 * @return string HTML code or nothing if $return == false
 */
function print_bioauth_page_head($active_type,
                               $heading = false, $return=false,
                               $buttons=false, $shownavigation=true) {
    global $CFG, $OUTPUT, $PAGE;

    $title = get_string($active_type, 'local_bioauth');

    if ($active_type == 'report') {
        $PAGE->set_pagelayout('report');
    } else {
        $PAGE->set_pagelayout('admin');
    }
    $PAGE->set_title(get_string('pluginname', 'local_bioauth') . ': ' . $active_type);
    $PAGE->set_heading($title);
    if ($buttons instanceof single_button) {
        $buttons = $OUTPUT->render($buttons);
    }
    $PAGE->set_button($buttons);

    $returnval = $OUTPUT->header();
    if (!$return) {
        echo $returnval;
    }

    // Guess heading if not given explicitly
    if (!$heading) {
        $heading = $stractive_plugin;
    }

    if ($shownavigation) {

        if ($return) {
            $returnval .= $OUTPUT->heading($heading);
        } else {
            echo $OUTPUT->heading($heading);
        }
    }

    if ($return) {
        return $returnval;
    }
}