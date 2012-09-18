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
 * Script that actually restores backup.
 *
 * @copyright &copy; 2011 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @package local
 * @subpackage broom
 */

require_once('../../config.php');

global $CFG, $PAGE, $SITE, $USER, $DB, $OUTPUT;

require_once($CFG->dirroot . '/lib/formslib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

$context = context_system::instance();

$pluginname = get_string('pluginname', 'local_broom');
$pagename = get_string('restoreall', 'local_broom');

$PAGE->set_url(new moodle_url('/local/broom/restoreall.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading($SITE->fullname);
$PAGE->set_title($SITE->fullname. ': ' . $pagename);

$PAGE->navbar->add($pluginname, new moodle_url('/local/broom/'));
$PAGE->navbar->add($pagename);

$directory = required_param('directory', PARAM_SAFEPATH);
$categoryname = optional_param('categoryname', 'Broom restores', PARAM_TEXT);
$suffix = optional_param('suffix', '', PARAM_TEXT);

require_login();
require_capability('moodle/site:config', $context);
if (!debugging('', DEBUG_DEVELOPER)) {
    print_error('error',  'local_broom');
}

print $OUTPUT->header();

print html_writer::tag('h2', get_string('restore'));

$fs = get_file_storage();
if (empty($directory)) {
    die('No file location has been configured'); // Should be impossible for people to even see the link to this page.
}
$files = glob($directory.'/*.mbz');
if (empty($files)) {
    print_error('nofiles', 'local_broom'); // TODO is this the right error message?
}

foreach ($files as $found) {
    /* @var stored_file $found */

    // Unzip backup.
    $rand = $USER->id;
    while (strlen($rand) < 10) {
        $rand = '0' . $rand;
    }
    $rand .= rand();
    check_dir_exists($CFG->dataroot . '/temp/backup');
    $packer = get_file_packer();
    $tempdir = $CFG->dataroot.'/temp/backup/'.$rand;
    $packer->extract_to_pathname($found, $tempdir);

    // Get or create category.
    $categoryid = $DB->get_field('course_categories', 'id', array('name'=>$categoryname));
    if (!$categoryid) {
        $categoryid = $DB->insert_record('course_categories', (object)array(
            'name' => $categoryname,
            'parent' => 0,
            'visible' => 0
        ));
        $DB->set_field('course_categories', 'path', '/' . $categoryid, array('id'=>$categoryid));
    }

    $backupxml = simplexml_load_file($tempdir.'/moodle_backup.xml');

    if (!empty($backupxml->information->original_course_shortname)) {
        $shortname = (string)$backupxml->information->original_course_shortname;
    } else {
        $shortname = 'BRM ' . date('His');
    }
    if (!empty($backupxml->information->original_course_shortname)) {
        $fullname = (string)$backupxml->information->original_course_fullname;
    } else {
        $fullname = 'Broom restore ' . date('Y-m-d H:i:s');
    }

    // Skip if courseshortname is already here as we have to have unique ones.
    if ($DB->record_exists('course', array('shortname' => $shortname))) {

        if (!empty($suffix)) {
            $shortname .= $suffix;
        } else {
            print html_writer::tag('p', get_string('courseexists', 'local_broom', $shortname));
            continue;
        }
    }

    // Create new course.
    $courseid = restore_dbops::create_new_course($fullname, $shortname, $categoryid);

    // Restore backup into course.
    $controller = new restore_controller($rand, $courseid,
            backup::INTERACTIVE_NO, backup::MODE_SAMESITE, $USER->id,
            backup::TARGET_NEW_COURSE);
    /* @var base_logger $logger */
    $logger = $controller->get_logger();
    $logger->set_next(new output_indented_logger(backup::LOG_INFO, false, true));
    if (!$controller->execute_precheck()) {
        // Errors of some sort.
        echo get_string('somethingwentwrong', 'local_broom', $shortname);
        $errors = $controller->get_precheck_results();
        foreach ($errors as $error) {
            echo $error.html_writer::empty_tag('br');
        }
        $DB->delete_records('course', array('id' => $courseid));
        continue;
    }
    $controller->execute_plan();

    // Set shortname and fullname back!
    $DB->update_record('course', (object)array(
        'id' => $courseid,
        'shortname' => $shortname,
        'fullname' => $fullname
    ));

    print html_writer::tag('p', get_string('restoredone', 'local_broom'));

    $courseurl = new moodle_url('/course/view.php', array('id'=>$courseid));
    print html_writer::tag('p', html_writer::tag('a',
            get_string('viewcoursenamed', 'local_broom', $fullname),
            array('href'=>$courseurl->out(), 'target'=>'_blank')));


}


print html_writer::tag('script', 'document.getElementById("r").disabled=true;',
        array('type'=>'text/javascript'));

print $OUTPUT->footer();

