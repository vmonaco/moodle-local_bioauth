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
    
    $jobs = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_VOID));
    foreach ($jobs as $idx => $job) {
        // do nothing
    }
    
    // Place complete jobs which are still active into the monitor state
    $jobs = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_COMPLETE));
    foreach ($jobs as $idx => $job) {
        if (time() < $job->activeuntil) {
            mtrace('Keeping job active: ' . $job->id);
            $DB->set_field('bioauth_quiz_validations', 'state', BIOAUTH_JOB_MONITOR, array('id' => $job->id));
        }
    }
    
    // If a job has enough data, mark it as ready
    $jobs = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_WAITING));
    foreach ($jobs as $idx => $job) {
        if (job_enough_data($job)) {
            mtrace('Enough data collected for job ' . $job->id);
            $DB->set_field('bioauth_quiz_validations', 'state', BIOAUTH_JOB_READY, array('id' => $job->id));
        }
    }
    
    // If a job has enough NEW data, mark it as ready
    $jobs = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_MONITOR));
    foreach ($jobs as $idx => $job) {
        if (job_enough_new_data($job)) {
            mtrace('Enough new data collected for job ' . $job->id);
            $DB->set_field('bioauth_quiz_validations', 'state', BIOAUTH_JOB_READY, array('id' => $job->id));
        }
    }
    
    // Start ready jobs without exceeding the allowable limit
    $maxconcurrentjobs = get_config('local_bioauth', 'maxconcurrentjobs');
    $numjobsrunning = $DB->count_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_RUNNING));
    
    $jobs = $DB->get_records('bioauth_quiz_validations', array('state' => BIOAUTH_JOB_READY));
    shuffle($jobs); // Ensure all queued jobs have a change of running
    foreach ($jobs as $idx => $job) {
        if ($numjobsrunning < $maxconcurrentjobs) {
            mtrace('Running quiz validation job ' . $job->id);
            $percentdataused = get_percent_data_ready($job);
            $DB->set_field('bioauth_quiz_validations', 'percentdataused', $percentdataused, array('id' => $job->id));
            run_quiz_validation($job);
            $numjobsrunning += 1;
        }
    }
}

function bioauth_get_quiz_validation($course) {
    global $DB;
    
    return $DB->get_record('bioauth_quiz_validations', array('courseid' => $course->id));
}

function get_percent_data_ready($job) {
    global $DB;
    
    $coursecontext = get_context_instance(CONTEXT_COURSE, $job->courseid);
    if (!$students = get_users_by_capability($coursecontext,
            array('mod/quiz:reviewmyattempts', 'mod/quiz:attempt'),
            'u.id, 1', '', '', '', '', '', false)) {
        $students = array();
    } else {
        $students = array_keys($students);
    }
    
    $quizzes = $DB->get_records('quiz', array('course' => $job->courseid));
    $numquizzes = count($quizzes);
    $numstudents = count($students);
    
    list($quizsql, $quizparams) = $DB->get_in_or_equal(array_keys($quizzes), SQL_PARAMS_NAMED, 'qzid0');

    $sql = "SELECT COUNT(bd.id)
                      FROM {bioauth_demo_biodata} bd
                     WHERE bd.quizid $quizsql";
                           
    $numbiodata = $DB->count_records_sql($sql, $quizparams);
    
    if ($numbiodata > 0) {
        $percentdata = 100 * ($numstudents*$numquizzes)/$numbiodata;
    } else {
        $percentdata = 0;
    }
    
    return $percentdata;
}

function job_enough_data($job) {
    $percentdataready = get_percent_data_ready($job);
    return $percentdataready >= $job->percentdataneeded;
}

function job_enough_new_data($job) {
    $percentdataready = get_percent_data_ready($job);
    return $percentdataready > $job->percentdataused;
}

function bioauth_extends_navigation(global_navigation $navigation) {
    $bioauthnode = $navigation->add(get_string('pluginname', 'local_bioauth'));
    $reportnode = $bioauthnode->add(get_string('report', 'local_bioauth'), new moodle_url('/local/bioauth/report/index.php'));
    $settingsnode = $bioauthnode->add(get_string('settings', 'local_bioauth'), new moodle_url('/admin/settings.php', array('section' => 'local_bioauth')));
}

function run_quiz_validation($job) {
    global $CFG;
 
    $errorratios = array(
        BIOAUTH_DECISION_NEUTRAL => 1.0,
        BIOAUTH_DECISION_CONVENIENT => 0.5,
        BIOAUTH_DECISION_SECURE => 1.5,
        );
    
    $jobparams = json_decode($job->jobparams);
    $errorratio = $errorratios[$jobparams->decisionmode];
    
    shell_exec("nohup java -Xmx512m -jar $CFG->dirroot/local/bioauth/bin/ssi.jar $CFG->dbhost $CFG->dbname $CFG->dbuser $CFG->dbpass $CFG->prefix $job->courseid $jobparams->featureset $jobparams->knn $jobparams->minkeyfrequency $errorratio >/dev/null 2>&1 & ");
}


function bioauth_enable_course($courseid) {
    create_quiz_validation_job($courseid);
}

function bioauth_disable_course($courseid) {
    remove_quiz_validation_job($courseid);
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