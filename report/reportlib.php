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
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir .'/tablelib.php');

require_once($CFG->dirroot . '/mod/quiz/report/reportlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

require_once($CFG->dirroot . '/local/bioauth/lib.php');
require_once($CFG->dirroot . '/local/bioauth/locallib.php');
require_once($CFG->dirroot . '/local/bioauth/HighRoller/HighRoller.php');
require_once($CFG->dirroot . '/local/bioauth/HighRoller/HighRollerSeriesData.php');
require_once($CFG->dirroot . '/local/bioauth/HighRoller/HighRollerLineChart.php');


/*
 * Quiz report subclass for the bioauth quiz report.
 *
 * @copyright 
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bioauth_report_quiz extends bioauth_report {
     
    /**
     * 
     * @var array $quizzes
     */
    private $course;
    private $students;
    private $validation;
    private $quizzes;
    private $quizauths;
    

//// SQL-RELATED

    /**
     * The id of the grade_item by which this report will be sorted.
     * @var int $sortitemid
     */
    public $sortitemid;

    /**
     * Sortorder used in the SQL selections.
     * @var int $sortorder
     */
    public $sortorder;

    /**
     * A count of the rows, used for css classes.
     * @var int $rowcount
     */
    public $rowcount = 0;

    
    /**
     * Constructor. Sets local copies of user preferences and initialises grade_tree.
     * @param int $courseid
     * @param object $gpr grade plugin return tracking object
     * @param string $context
     * @param int $page The current page being viewed (when report is paged)
     * @param int $sortitemid The id of the grade_item by which to sort the table
     */
    public function __construct($context, $page) {
        global $CFG;
        parent::__construct($context, $page, $sortitemid=null);

        $this->sortitemid = $sortitemid;

        // base url for sorting
        $this->baseurl = new moodle_url('quiz.php');

        $this->pbarurl = new moodle_url('/local/bioauth/report/quiz.php');
        
        $this->setup_sortitemid();
    }

        /**
     * Setting the sort order, this depends on last state
     * all this should be in the new table class that we might need to use
     * for displaying grades.
     */
    private function setup_sortitemid() {

        global $SESSION;

        if (!isset($SESSION->bioauthcoursereport)) {
            $SESSION->bioauthcoursereport = new stdClass();
        }

        if ($this->sortitemid) {
            if (!isset($SESSION->bioauthcoursereport->sort)) {
                if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                    $this->sortorder = $SESSION->bioauthcoursereport->sort = 'ASC';
                } else {
                    $this->sortorder = $SESSION->bioauthcoursereport->sort = 'DESC';
                }
            } else {
                // this is the first sort, i.e. by last name
                if (!isset($SESSION->bioauthcoursereport->sortitemid)) {
                    if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'ASC';
                    } else {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'DESC';
                    }
                } else if ($SESSION->bioauthcoursereport->sortitemid == $this->sortitemid) {
                    // same as last sort
                    if ($SESSION->bioauthcoursereport->sort == 'ASC') {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'DESC';
                    } else {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'ASC';
                    }
                } else {
                    if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'ASC';
                    } else {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'DESC';
                    }
                }
            }
            $SESSION->bioauthcoursereport->sortitemid = $this->sortitemid;
        } else {
            // not requesting sort, use last setting (for paging)

            if (isset($SESSION->bioauthcoursereport->sortitemid)) {
                $this->sortitemid = $SESSION->gradeuserreport->sortitemid;
            }else{
                $this->sortitemid = 'lastname';
            }

            if (isset($SESSION->bioauthcoursereport->sort)) {
                $this->sortorder = $SESSION->gradeuserreport->sort;
            } else {
                $this->sortorder = 'ASC';
            }
        }
    }

    /**
     * Get information about which students to show in the report.
     * @return an array 
     */
    public function load_validation($context, $course) {
        global $CFG, $DB;
        
        $this->course = $course;
        
        $this->validation = $DB->get_record('bioauth_quiz_validations', array('courseid' => $course->id));
        $this->quizzes = $DB->get_records('quiz', array('course' => $course->id));
        
        $records = $DB->get_records('bioauth_quiz_neighbors', array('courseid' => $course->id));
        $quizauths = array();
        foreach ($records as $record) {
            $quizauths[$record->userid][$record->quizid] = explode(',', $record->neighbors);
        }
        
        $this->quizauths = $quizauths;
        
        if (!empty($this->users)) {
            return;
        }

        //limit to users with a gradeable role
        list($gradebookrolessql, $gradebookrolesparams) = $DB->get_in_or_equal(explode(',', $this->gradebookroles), SQL_PARAMS_NAMED, 'grbr0');

        //limit to users with an active enrollment
        list($enrolledsql, $enrolledparams) = get_enrolled_sql($this->context);

        //fields we need from the user table
        $userfields = user_picture::fields('u', get_extra_user_fields($this->context));

        $sortjoin = $sort = $params = null;

        //if the user has clicked one of the sort asc/desc arrows
        $sortjoin = '';
        switch($this->sortitemid) {
            case 'lastname':
                $sort = "u.lastname $this->sortorder, u.firstname $this->sortorder";
                break;
            case 'firstname':
                $sort = "u.firstname $this->sortorder, u.lastname $this->sortorder";
                break;
            case 'email':
                $sort = "u.email $this->sortorder";
                break;
            case 'idnumber':
            default:
                $sort = "u.idnumber $this->sortorder";
                break;
        }

        $params = array_merge($gradebookrolesparams, $enrolledparams);
        
        $sql = "SELECT $userfields
                  FROM {user} u
                  JOIN ($enrolledsql) je ON je.id = u.id
                       $sortjoin
                  JOIN (
                           SELECT DISTINCT ra.userid
                             FROM {role_assignments} ra
                            WHERE ra.roleid IN ($this->gradebookroles)
                              AND ra.contextid " . get_related_contexts_string($this->context) . "
                       ) rainner ON rainner.userid = u.id
                   AND u.deleted = 0
              ORDER BY $sort";

        $studentsperpage = $this->get_rows_per_page();
        $this->users = $DB->get_records_sql($sql, $params, $studentsperpage * $this->page, $studentsperpage);

        if (empty($this->users)) {
            $this->userselect = '';
            $this->users = array();
            $this->userselect_params = array();
        } else {
            list($usql, $uparams) = $DB->get_in_or_equal(array_keys($this->users), SQL_PARAMS_NAMED, 'usid0');
            $this->userselect = "AND g.userid $usql";
            $this->userselect_params = $uparams;

            // Add a flag to each user indicating whether their enrolment is active.
            $sql = "SELECT ue.userid
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON e.id = ue.enrolid
                     WHERE ue.userid $usql
                           AND ue.status = :uestatus
                           AND e.status = :estatus
                           AND e.courseid = :courseid
                  GROUP BY ue.userid";
            $coursecontext = get_course_context($this->context);
            $params = array_merge($uparams, array('estatus'=>ENROL_INSTANCE_ENABLED, 'uestatus'=>ENROL_USER_ACTIVE, 'courseid'=>$coursecontext->instanceid));
            $useractiveenrolments = $DB->get_records_sql($sql, $params);

            $defaultgradeshowactiveenrol = !empty($CFG->grade_report_showonlyactiveenrol);
            $showonlyactiveenrol = get_user_preferences('grade_report_showonlyactiveenrol', $defaultgradeshowactiveenrol);
            $showonlyactiveenrol = $showonlyactiveenrol || !has_capability('moodle/course:viewsuspendedusers', $coursecontext);
            foreach ($this->users as $user) {
                // If we are showing only active enrolments, then remove suspended users from list.
                if ($showonlyactiveenrol && !array_key_exists($user->id, $useractiveenrolments)) {
                    unset($this->users[$user->id]);
                } else {
                    $this->users[$user->id]->suspendedenrolment = !array_key_exists($user->id, $useractiveenrolments);
                }
            }
        }

        return $this->users;
    }

    public function get_report_graph() {
        $html = '';
        
        $frrstring = explode(',', $this->validation->frr);
        $farstring = explode(',', $this->validation->far);
        
        $frr = array();
        $far = array();
        foreach (array_keys($frrstring) as $m) {
            $frr[] = (float)$frrstring[$m];
            $far[] = (float)$farstring[$m];
        }
        
        $linechart = new HighRollerLineChart();
        $linechart->chart->renderTo = 'linechart';
        $linechart->title->text = 'FRR and FAR vs M';
        $linechart->xAxis->title->text = 'M';
        $linechart->yAxis->min = 0;
        $linechart->yAxis->max = 100;
        $linechart->xAxis->min = 0;
        $linechart->xAxis->max = count($frr);
        
        $linechart->yAxis->title->text = 'Error (%)';
        
        $linechart->chart->width = 600;
        $linechart->chart->height = 300;
        
        $frrseries = new HighRollerSeriesData();
        $frrseries->addName('FRR')->addData($frr);
        $frrseries->marker->enabled = false;
        
        $farseries = new HighRollerSeriesData();
        $farseries->addName('FAR')->addData($far);
        $farseries->marker->enabled = false;
        
        $linechart->addSeries($frrseries);
        $linechart->addSeries($farseries);
        
        $html .= '<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.6.1/jquery.min.js"></script>';
        $html .= "<script type='text/javascript' src='../highcharts/highcharts.js'></script>";
        $html .= '<div id="linechart"></div><script type="text/javascript">'.$linechart->renderChart().'</script>';
        
        return $html;
    }

    public function get_report_table() {
        global $CFG, $DB, $OUTPUT, $PAGE;

        if (!$this->quizzes) {
            echo $OUTPUT->notification(get_string('noquizzesyet'));
            return;
        }
        
        $html = '';
        
        $rows = $this->get_rows();
        
        $reporttable = new html_table();
        $reporttable->attributes['class'] = 'gradestable flexible boxaligncenter generaltable';
        $reporttable->id = 'bioauth-overview-report';
        $reporttable->data = $rows;
        $html .= html_writer::table($reporttable);

        return $html;
    }

    public function get_rows() {
        global $CFG, $USER, $OUTPUT;

        $rows = array();
        
        $showuserimage = $this->get_pref('showuserimage');

        $extrafields = get_extra_user_fields($this->context);

        $arrows = $this->get_sort_arrows($extrafields);
        
        $headerrow = new html_table_row();
        $headerrow->attributes['class'] = 'heading';

        $studentheader = new html_table_cell();
        $studentheader->attributes['class'] = 'header';
        $studentheader->scope = 'col';
        $studentheader->header = true;
        $studentheader->id = 'studentheader';
        $studentheader->text = $arrows['studentname'];

        $headerrow->cells[] = $studentheader;
        
        foreach ($this->quizzes as $quizid => $quiz) {
            $quizheader = new html_table_cell();
            $quizheader->attributes['class'] = 'header';
            $quizheader->scope = 'col';
            $quizheader->header = true;
            $quizheader->id = 'quizheader';
            $quizheader->text = $quiz->name;
    
            $headerrow->cells[] = $quizheader;
        }
        
        $rows[] = $headerrow;
        
        $rowclasses = array('even', 'odd');
        
        foreach ($this->users as $userid => $user) {
            $userrow = new html_table_row();
            $userrow->id = 'fixed_user_'.$userid;
            $userrow->attributes['class'] = 'r'.$this->rowcount++.' '.$rowclasses[$this->rowcount % 2];

            $usercell = new html_table_cell();
            $usercell->attributes['class'] = 'user';

            $usercell->header = true;
            $usercell->scope = 'row';

            if ($showuserimage) {
                $usercell->text = $OUTPUT->user_picture($user);
            }

            $usercell->text .= html_writer::link(new moodle_url('/user/view.php', array('id' => $user->id, 'course' => $this->course->id)), fullname($user));

            if (!empty($user->suspendedenrolment)) {
                $usercell->attributes['class'] .= ' usersuspended';

                //may be lots of suspended users so only get the string once
                if (empty($suspendedstring)) {
                    $suspendedstring = get_string('userenrolmentsuspended', 'grades');
                }
                $usercell->text .= html_writer::empty_tag('img', array('src'=>$OUTPUT->pix_url('i/enrolmentsuspended'), 'title'=>$suspendedstring, 'alt'=>$suspendedstring, 'class'=>'usersuspendedicon'));
            }

            $userrow->cells[] = $usercell;
            
            foreach ($this->quizzes as $quizid => $quiz) {
                $quizcell = new html_table_cell();
                $quizcell->attributes['class'] = 'quiz';
    
                $quizcell->header = true;
                $quizcell->scope = 'row';

                if (array_key_exists($userid, $this->quizauths) && array_key_exists($quizid, $this->quizauths[$userid])) {
                    $quizcell->text .= $this->make_decision_output($this->quizauths[$userid][$quizid][$this->validation->m]);
                } else {
                    $quizcell->text .= '-';
                }

                $userrow->cells[] = $quizcell;
            }
            
            $rows[] = $userrow;
        }

        return $rows;
    }

    /**
     * Make a link to review an individual question in a popup window.
     *
     * @param string $data HTML fragment. The text to make into the link.
     */
    public function make_decision_output($decision) {
        global $OUTPUT;
        
        $decisionclass = 'w' === $decision ? 'correct' : 'incorrect';
        $img = $OUTPUT->pix_icon('i/grade_' . $decisionclass, get_string($decisionclass, 'question'),
                                'moodle', array('class' => 'icon'));
                
        $output = html_writer::tag('span', $img);
        
        return $output;
    }

/**
     * Refactored function for generating HTML of sorting links with matching arrows.
     * Returns an array with 'studentname' and 'idnumber' as keys, with HTML ready
     * to inject into a table header cell.
     * @param array $extrafields Array of extra fields being displayed, such as
     *   user idnumber
     * @return array An associative array of HTML sorting links+arrows
     */
    public function get_sort_arrows(array $extrafields = array()) {
        global $OUTPUT;
        $arrows = array();

        $strsortasc   = $this->get_lang_string('sortasc', 'grades');
        $strsortdesc  = $this->get_lang_string('sortdesc', 'grades');
        $strfirstname = $this->get_lang_string('firstname');
        $strlastname  = $this->get_lang_string('lastname');
        $iconasc = $OUTPUT->pix_icon('t/sort_asc', $strsortasc, '', array('class' => 'iconsmall sorticon'));
        $icondesc = $OUTPUT->pix_icon('t/sort_desc', $strsortdesc, '', array('class' => 'iconsmall sorticon'));

        $firstlink = html_writer::link(new moodle_url($this->baseurl, array('sortitemid'=>'firstname')), $strfirstname);
        $lastlink = html_writer::link(new moodle_url($this->baseurl, array('sortitemid'=>'lastname')), $strlastname);

        $arrows['studentname'] = $lastlink;

        if ($this->sortitemid === 'lastname') {
            if ($this->sortorder == 'ASC') {
                $arrows['studentname'] .= $iconasc;
            } else {
                $arrows['studentname'] .= $icondesc;
            }
        }

        $arrows['studentname'] .= ' ' . $firstlink;

        if ($this->sortitemid === 'firstname') {
            if ($this->sortorder == 'ASC') {
                $arrows['studentname'] .= $iconasc;
            } else {
                $arrows['studentname'] .= $icondesc;
            }
        }

        foreach ($extrafields as $field) {
            $fieldlink = html_writer::link(new moodle_url($this->baseurl,
                    array('sortitemid'=>$field)), get_user_field_name($field));
            $arrows[$field] = $fieldlink;

            if ($field == $this->sortitemid) {
                if ($this->sortorder == 'ASC') {
                    $arrows[$field] .= $iconasc;
                } else {
                    $arrows[$field] .= $icondesc;
                }
            }
        }

        return $arrows;
    }
    
    public function get_numrows() {
        return count($this->students);
    }
    
    public function process_action($target, $action) {
        return self::do_process_action($target, $action);
    }

    /**
     * Processes a single action against a category, grade_item or grade.
     * @param string $target eid ({type}{id}, e.g. c4 for category4)
     * @param string $action Which action to take (edit, delete etc...)
     * @return
     */
    public static function do_process_action($target, $action) {
        
        switch ($action) {
            case 'enable':
                bioauth_enable_course($target);
                break;
                
            case 'disable':
                bioauth_disable_course($target);
                break;
                
            default:
                break;
        }

        return true;
    }
    
        /**
     * Processes the data sent by the form (grades and feedbacks).
     * Caller is responsible for all access control checks
     * @param array $data form submission (with magic quotes)
     * @return array empty array if success, array of warnings if something fails.
     */
    public function process_data($data) {
        global $DB;
        $warnings = array();

        return $warnings;
    }
    
    /**
     * Returns the maximum number of students to be displayed on each page
     *
     * Takes into account the 'studentsperpage' user preference and the 'max_input_vars'
     * PHP setting. Too many fields is only a problem when submitting grades but
     * we respect 'max_input_vars' even when viewing grades to prevent students disappearing
     * when toggling editing on and off.
     *
     * @return int The maximum number of students to display per page
     */
    public function get_rows_per_page() {
        global $USER;
        static $rowsperpage = null;

        if ($rowsperpage === null) {
            $originalstudentsperpage = $rowsperpage = $this->get_pref('rowsperpage');

            // Will this number of students result in more fields that we are allowed?
            $maxinputvars = ini_get('max_input_vars');
            if ($maxinputvars !== false) {

                if ($rowsperpage >= $maxinputvars) {
                    $rowsperpage = $maxinputvars - 1; // Subtract one to be on the safe side
                    if ($rowsperpage<1) {
                        // Make sure students per page doesn't fall below 1, though if your
                        // max_input_vars is only 1 you've got bigger problems!
                        $rowsperpage = 1;
                    }
                }
            }
        }

        return $rowsperpage;
    }
}


/*
 * Quiz report subclass for the overview (grades) report.
 *
 * @copyright 
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bioauth_report_overview extends bioauth_report {
     
    /**
     * The final grades.
     * @var array $grades
     */
    public $courses;

//// SQL-RELATED

    /**
     * The id of the grade_item by which this report will be sorted.
     * @var int $sortitemid
     */
    public $sortitemid;

    /**
     * Sortorder used in the SQL selections.
     * @var int $sortorder
     */
    public $sortorder;

    /**
     * A count of the rows, used for css classes.
     * @var int $rowcount
     */
    public $rowcount = 0;

    
    /**
     * Constructor. Sets local copies of user preferences and initialises grade_tree.
     * @param int $courseid
     * @param object $gpr grade plugin return tracking object
     * @param string $context
     * @param int $page The current page being viewed (when report is paged)
     * @param int $sortitemid The id of the grade_item by which to sort the table
     */
    public function __construct($context, $page) {
        global $CFG;
        parent::__construct($context, $page, $sortitemid=null);

        $this->sortitemid = $sortitemid;

        // base url for sorting
        $this->baseurl = new moodle_url('index.php');

        $this->pbarurl = new moodle_url('/local/bioauth/report/index.php');
        
        $this->setup_sortitemid();
    }

        /**
     * Setting the sort order, this depends on last state
     * all this should be in the new table class that we might need to use
     * for displaying grades.
     */
    private function setup_sortitemid() {

        global $SESSION;

        if (!isset($SESSION->bioauthcoursereport)) {
            $SESSION->bioauthcoursereport = new stdClass();
        }

        if ($this->sortitemid) {
            if (!isset($SESSION->bioauthcoursereport->sort)) {
                if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                    $this->sortorder = $SESSION->bioauthcoursereport->sort = 'ASC';
                } else {
                    $this->sortorder = $SESSION->bioauthcoursereport->sort = 'DESC';
                }
            } else {
                // this is the first sort, i.e. by last name
                if (!isset($SESSION->bioauthcoursereport->sortitemid)) {
                    if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'ASC';
                    } else {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'DESC';
                    }
                } else if ($SESSION->bioauthcoursereport->sortitemid == $this->sortitemid) {
                    // same as last sort
                    if ($SESSION->bioauthcoursereport->sort == 'ASC') {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'DESC';
                    } else {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'ASC';
                    }
                } else {
                    if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'ASC';
                    } else {
                        $this->sortorder = $SESSION->bioauthcoursereport->sort = 'DESC';
                    }
                }
            }
            $SESSION->bioauthcoursereport->sortitemid = $this->sortitemid;
        } else {
            // not requesting sort, use last setting (for paging)

            if (isset($SESSION->bioauthcoursereport->sortitemid)) {
                $this->sortitemid = $SESSION->gradeuserreport->sortitemid;
            }else{
                $this->sortitemid = 'lastname';
            }

            if (isset($SESSION->bioauthcoursereport->sort)) {
                $this->sortorder = $SESSION->gradeuserreport->sort;
            } else {
                $this->sortorder = 'ASC';
            }
        }
    }

    /**
     * Get information about which students to show in the report.
     * @return an array 
     */
    public function load_course_validations() {
        
        $enrolcourses = enrol_get_my_courses();
        $viewgradecourses = array();
        foreach ($enrolcourses as $course) {
            $coursecontext = get_context_instance(CONTEXT_COURSE, $course->id);
            if (has_capability('moodle/grade:viewall', $coursecontext)) {
                $viewgradecourses[] = $course;
            }
        }
        
        $this->courses = $viewgradecourses;
    }

    public function get_report_table() {
        global $CFG, $DB, $OUTPUT, $PAGE;

        if (!$this->courses) {
            echo $OUTPUT->notification(get_string('nocoursesyet'));
            return;
        }
        
        $html = '';
        
        $rows = $this->get_rows();
        
        $reporttable = new html_table();
        $reporttable->attributes['class'] = 'gradestable flexible boxaligncenter generaltable';
        $reporttable->id = 'bioauth-overview-report';
        $reporttable->data = $rows;
        $html .= html_writer::table($reporttable);

        return $html;
    }

    public function get_rows() {
        global $CFG, $USER, $OUTPUT;

        $rows = array();

        $arrows = $this->get_sort_arrows();
        
        $headerrow = new html_table_row();
        $headerrow->attributes['class'] = 'heading';

        $statusheader = new html_table_cell();
        $statusheader->attributes['class'] = 'header';
        $statusheader->scope = 'col';
        $statusheader->header = true;
        $statusheader->id = 'statusheader';
        $statusheader->text = get_string('status', 'local_bioauth');

        $headerrow->cells[] = $statusheader;
        
        $courseheader = new html_table_cell();
        $courseheader->attributes['class'] = 'header';
        $courseheader->scope = 'col';
        $courseheader->header = true;
        $courseheader->id = 'courseheader';
        $courseheader->text = $arrows['coursename'];

        $headerrow->cells[] = $courseheader;
        
        $rows[] = $headerrow;
        $rowclasses = array('even', 'odd');
        
        foreach ($this->courses as $courseid => $course) {
            $courserow = new html_table_row();
            $courserow->id = 'fixed_course_'.$courseid;
            $courserow->attributes['class'] = 'r'.$this->rowcount++.' '.$rowclasses[$this->rowcount % 2];

            $statuscell = new html_table_cell();
            $statuscell->attributes['class'] = 'course';
            $statuscell->header = true;
            $statuscell->scope = 'row';
            $action = $course->bioauthenabled ? 'disable' : 'enable';
            $statuscell->text .= html_writer::link(new moodle_url($this->pbarurl, array('action' => $action, 'target' => $course->id, 'sesskey' => sesskey())), get_string($action, 'local_bioauth'));
            $courserow->cells[] = $statuscell;
            
            $coursecell = new html_table_cell();
            $coursecell->attributes['class'] = 'course';
            $coursecell->header = true;
            $coursecell->scope = 'row';
            $coursecell->text .= html_writer::link(new moodle_url('/local/bioauth/report/quiz.php', array('id' => $course->id)), $course->shortname);
            $courserow->cells[] = $coursecell;

            $rows[] = $courserow;
        }

        return $rows;
    }

/**
     * Refactored function for generating HTML of sorting links with matching arrows.
     * Returns an array with 'studentname' and 'idnumber' as keys, with HTML ready
     * to inject into a table header cell.
     * @param array $extrafields Array of extra fields being displayed, such as
     *   user idnumber
     * @return array An associative array of HTML sorting links+arrows
     */
    public function get_sort_arrows() {
        global $OUTPUT;
        $arrows = array();

        $strsortasc   = $this->get_lang_string('sortasc', 'local_bioauth');
        $strsortdesc  = $this->get_lang_string('sortdesc', 'local_grades');
        $strcoursename = $this->get_lang_string('course');
        $iconasc = $OUTPUT->pix_icon('t/sort_asc', $strsortasc, '', array('class' => 'iconsmall sorticon'));
        $icondesc = $OUTPUT->pix_icon('t/sort_desc', $strsortdesc, '', array('class' => 'iconsmall sorticon'));

        $coursenamelink = html_writer::link(new moodle_url($this->baseurl, array('sortitemid'=>'coursename')), $strcoursename);

        $arrows['coursename'] = $coursenamelink;

        if ($this->sortitemid === 'lastname') {
            if ($this->sortorder == 'ASC') {
                $arrows['coursename'] .= $iconasc;
            } else {
                $arrows['coursename'] .= $icondesc;
            }
        }
        
        return $arrows;
    }
    
    public function get_numrows() {
        return count($this->courses);
    }
    
    public function process_action($target, $action) {
        return self::do_process_action($target, $action);
    }

    /**
     * Processes a single action against a category, grade_item or grade.
     * @param string $target eid ({type}{id}, e.g. c4 for category4)
     * @param string $action Which action to take (edit, delete etc...)
     * @return
     */
    public static function do_process_action($target, $action) {
        
        switch ($action) {
            case 'enable':
                bioauth_enable_course($target);
                break;
                
            case 'disable':
                bioauth_disable_course($target);
                break;
                
            default:
                break;
        }

        return true;
    }
    
        /**
     * Processes the data sent by the form (grades and feedbacks).
     * Caller is responsible for all access control checks
     * @param array $data form submission (with magic quotes)
     * @return array empty array if success, array of warnings if something fails.
     */
    public function process_data($data) {
        global $DB;
        $warnings = array();

        return $warnings;
    }
    
    /**
     * Returns the maximum number of students to be displayed on each page
     *
     * Takes into account the 'studentsperpage' user preference and the 'max_input_vars'
     * PHP setting. Too many fields is only a problem when submitting grades but
     * we respect 'max_input_vars' even when viewing grades to prevent students disappearing
     * when toggling editing on and off.
     *
     * @return int The maximum number of students to display per page
     */
    public function get_rows_per_page() {
        global $USER;
        static $rowsperpage = null;

        if ($rowsperpage === null) {
            $originalstudentsperpage = $rowsperpage = $this->get_pref('rowsperpage');

            // Will this number of students result in more fields that we are allowed?
            $maxinputvars = ini_get('max_input_vars');
            if ($maxinputvars !== false) {

                if ($rowsperpage >= $maxinputvars) {
                    $rowsperpage = $maxinputvars - 1; // Subtract one to be on the safe side
                    if ($rowsperpage<1) {
                        // Make sure students per page doesn't fall below 1, though if your
                        // max_input_vars is only 1 you've got bigger problems!
                        $rowsperpage = 1;
                    }
                }
            }
        }

        return $rowsperpage;
    }
}

/**
 * An abstract class containing variables and methods used by all or most reports.
 * @package core_grades
 */
abstract class bioauth_report {

    /**
     * The context.
     * @var int $context
     */
    public $context;

    /**
     * User preferences related to this report.
     * @var array $prefs
     */
    public $prefs = array();
    /**
     * base url for sorting by first/last name.
     * @var string $baseurl
     */
    public $baseurl;

    /**
     * base url for paging.
     * @var string $pbarurl
     */
    public $pbarurl;

    /**
     * Current page (for paging).
     * @var int $page
     */
    public $page;

    /**
     * Array of cached language strings (using get_string() all the time takes a long time!).
     * @var array $lang_strings
     */
    public $lang_strings = array();
    
    /**
     * The roles for this report.
     * @var string $gradebookroles
     */
    public $gradebookroles;

    /**
     * Constructor. Sets local copies of user preferences and initialises grade_tree.
     * @param int $courseid
     * @param object $gpr grade plugin return tracking object
     * @param string $context
     * @param int $page The current page being viewed (when report is paged)
     */
    public function __construct($context, $page=null) {
        global $CFG, $COURSE, $DB;
        $this->context          = $context;
        $this->page             = $page;
        $this->gradebookroles   = $CFG->gradebookroles;
    }

    /**
     * Handles form data sent by this report for this report. Abstract method to implement in all children.
     * @abstract
     * @param array $data
     * @return mixed True or array of errors
     */
    abstract function process_data($data);

    /**
     * Processes a single action against a category, grade_item or grade.
     * @param string $target Sortorder
     * @param string $action Which action to take (edit, delete etc...)
     * @return
     */
    abstract function process_action($target, $action);

    /**
     * Fetches and returns a count of all the users that will be shown on this page.
     * @param boolean $groups include groups limit
     * @return int Count of users
     */
    abstract public function get_numrows();
    
    /**
     * First checks the cached language strings, then returns match if found, or uses get_string()
     * to get it from the DB, caches it then returns it.
     * @param string $strcode
     * @param string $section Optional language section
     * @return string
     */
    public function get_lang_string($strcode, $section=null) {
        if (empty($this->lang_strings[$strcode])) {
            $this->lang_strings[$strcode] = get_string($strcode, $section);
        }
        return $this->lang_strings[$strcode];
    }

    /**
     * Returns an arrow icon inside an <a> tag, for the purpose of sorting a column.
     * @param string $direction
     * @param moodle_url $sort_link
     * @param string HTML
     */
    protected function get_sort_arrow($direction='move', $sortlink=null) {
        global $OUTPUT;
        $pix = array('up' => 't/sort_desc', 'down' => 't/sort_asc', 'move' => 't/sort');
        $matrix = array('up' => 'desc', 'down' => 'asc', 'move' => 'desc');
        $strsort = $this->get_lang_string('sort' . $matrix[$direction]);

        $arrow = $OUTPUT->pix_icon($pix[$direction], $strsort, '', array('class' => 'sorticon'));
        return html_writer::link($sortlink, $arrow, array('title'=>$strsort));
    }
    
    /**
     * Given the name of a user preference (without grade_report_ prefix), locally saves then returns
     * the value of that preference. If the preference has already been fetched before,
     * the saved value is returned. If the preference is not set at the User level, the $CFG equivalent
     * is given (site default).
     * @static (Can be called statically, but then doesn't benefit from caching)
     * @param string $pref The name of the preference (do not include the grade_report_ prefix)
     * @param int $objectid An optional itemid or categoryid to check for a more fine-grained preference
     * @return mixed The value of the preference
     */
    public function get_pref($pref, $objectid=null) {
        global $CFG;
        $fullprefname = 'bioauth_report_' . $pref;
        $shortprefname = 'bioauth_' . $pref;

        $retval = null;

        if (!isset($this) OR get_class($this) != 'grade_report') {
            if (!empty($objectid)) {
                $retval = get_user_preferences($fullprefname . $objectid, grade_report::get_pref($pref));
            } elseif (isset($CFG->$fullprefname)) {
                $retval = get_user_preferences($fullprefname, $CFG->$fullprefname);
            } elseif (isset($CFG->$shortprefname)) {
                $retval = get_user_preferences($fullprefname, $CFG->$shortprefname);
            } else {
                $retval = null;
            }
        } else {
            if (empty($this->prefs[$pref.$objectid])) {

                if (!empty($objectid)) {
                    $retval = get_user_preferences($fullprefname . $objectid);
                    if (empty($retval)) {
                        // No item pref found, we are returning the global preference
                        $retval = $this->get_pref($pref);
                        $objectid = null;
                    }
                } else {
                    $retval = get_user_preferences($fullprefname, $CFG->$fullprefname);
                }
                $this->prefs[$pref.$objectid] = $retval;
            } else {
                $retval = $this->prefs[$pref.$objectid];
            }
        }

        return $retval;
    }

    /**
     * Uses set_user_preferences() to update the value of a user preference. If 'default' is given as the value,
     * the preference will be removed in favour of a higher-level preference.
     * @static
     * @param string $pref_name The name of the preference.
     * @param mixed $pref_value The value of the preference.
     * @param int $itemid An optional itemid to which the preference will be assigned
     * @return bool Success or failure.
     */
    public function set_pref($pref, $pref_value='default', $itemid=null) {
        $fullprefname = 'bioauth_report_' . $pref;
        if ($pref_value == 'default') {
            return unset_user_preference($fullprefname.$itemid);
        } else {
            return set_user_preference($fullprefname.$itemid, $pref_value);
        }
    }
}


