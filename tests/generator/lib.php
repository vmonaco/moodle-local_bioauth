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

defined('MOODLE_INTERNAL') || die();

/**
 * BioAuth module test data generator class
 *
 * @package local_bioauth
 * @copyright Vinnie Monaco
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_bioauth_generator extends testing_module_generator {

    /**
     * Create new quiz module instance.
     * @param array|stdClass $record
     * @param array $options (mostly course_module properties)
     * @return stdClass activity record with extra cmid field
     */
    public function create_instance($record = null, array $options = null) {
        global $CFG;
        require_once("$CFG->dirroot/local/bioauth/locallib.php");
    }
    
    
    public function create_sample($n_features) {
        $sample = array();
        for ($feature_idx = 0; $feature_idx < $n_features; $feature_idx++) {
            $sample[$feature_idx] = mt_rand() / mt_getrandmax();
        }
        return $sample;
    }
    
    public function create_fspace($n_users, $n_user_samples, $n_features) {
        mt_srand(1234);
        
        $fspace = array();
        for ($user_idx = 0; $user_idx < $n_users; $user_idx++) {
            $samples = array();
            for ($sample_idx = 0; $sample_idx < $n_user_samples; $sample_idx++) {
                $samples[] = $this->create_sample($n_features);
            }
            $fspace[$user_idx] = $samples;
        }
        
        return $fspace;
    }
}
