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
 * This script allows to do backup.
 *
 * @package    core
 * @subpackage cli
 * @copyright  2013 Lancaster University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', 1);

require(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

// Now get cli options.
list($options, $unrecognized) = cli_get_params(array(
    'courseid' => false,
    'courseshortname' => '',
    'destination' => '',
    'help' => false,
    ), array('h' => 'help'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help'] || !($options['courseid'] || $options['courseshortname'])) {
    $help = <<<EOL
Perform backup of the given course.

Options:
--courseid=INTEGER          Course ID for backup.
--courseshortname=STRING    Course shortname for backup.
--destination=STRING        Path where to store backup file. If not set the backup
                            will be stored within the course backup file area.
-h, --help                  Print out this help.

Example:
\$sudo -u www-data /usr/bin/php admin/cli/backup.php --courseid=2 --destination=/moodle/backup/\n
EOL;

    echo $help;
    die;
}

$admin = get_admin();
if (!$admin) {
    mtrace("Error: No admin account was found");
    die;
}

// Do we need to store backup somewhere else?
// If supplied, validate it
$s3path = $s3bucket = '';
$destination = rtrim($options['destination'], '/');
if (!empty($destination)) {
    if (stripos($destination, 's3://') === 0) {
        $s3path = array_pop(explode('//', $destination, 2));
        $s3bucket = array_shift(explode('/', $s3path));
        if (empty($s3bucket)) {
            mtrace(get_string('backuperrorinvaliddestination'));
            die;
        }
        require_once("{$CFG->libdir}/vendor/autoload.php");
        $s3client = Aws\S3\S3Client::factory($CFG->aws_config);
        if (!$s3client->doesBucketExist($s3bucket)) {
            mtrace(get_string('backuperrorinvaliddestination'));
            die;
        }
    } elseif (!file_exists($destination) || !is_dir($destination) || !is_writable($destination)) {
        mtrace("Destination directory does not exists or not writable.");
        die;
    }
}

// Check that the course exists.
if ($options['courseid']) {
    $course = $DB->get_record('course', array('id' => $options['courseid']), '*', MUST_EXIST);
} else if ($options['courseshortname']) {
    $course = $DB->get_record('course', array('shortname' => $options['courseshortname']), '*', MUST_EXIST);
}

cli_heading('Performing backup...');
$bc = new backup_controller(backup::TYPE_1COURSE, $course->id, backup::FORMAT_MOODLE,
                            backup::INTERACTIVE_YES, backup::MODE_GENERAL, $admin->id);
// Set the default filename.
$format = $bc->get_format();
$type = $bc->get_type();
$id = $bc->get_id();
$users = $bc->get_plan()->get_setting('users')->get_value();
$anonymised = $bc->get_plan()->get_setting('anonymize')->get_value();
$filename = backup_plan_dbops::get_default_backup_filename($format, $type, $id, $users, $anonymised);
$bc->get_plan()->get_setting('filename')->set_value($filename);

// Execution.
$bc->finish_ui();
$bc->execute_plan();

// Get the stored_file object produced and returned
// via the backup results
$results = $bc->get_results();
$file = $results['backup_destination'];

// Do we need to store backup somewhere else?
if (!empty($s3bucket)) {

    $s3folder = (strpos($s3path, '/') === false ? '' : rtrim(array_pop(explode('/', $s3path, 2)), '/'));
    $fh = null;
    try {
        $fh = $file->get_content_file_handle(stored_file::FILE_HANDLE_FOPEN);
        $s3result = $s3client->putObject(array(
            'Bucket'    => $s3bucket,
            'Key'       => (empty($s3folder) ? '' : "{$s3folder}/") . "{$filename}",
            'Body'      => $fh,
            'Metadata'  => array(
                'moodle-course-id'      => "{$course->id}",
                'moodle-backup-id'      => "{$bc->get_backupid()}",
                'moodle-backup-type'    => "{$bc->get_type()}",
                'moodle-backup-mode'    => "{$bc->get_mode()}")));
        fclose($fh);
    } catch (Exception $exc) {
        mtrace("Execution of backup plan threw exception: " . $exc->getMessage());
        if ($fh != null) { fclose($fh); }
        die;
    }

    $s3status = $s3result['@metadata']['statusCode'];
    if ($s3status == 200) {
        mtrace("load backup to S3 bucket succeeded");
    } else {
        mtrace("load backup to S3 bucket failed: {$s3status}");
    }

}
elseif (!empty($destination)) {
    if ($file) {
        mtrace("Writing " . $destination.'/'.$filename);
        if ($file->copy_content_to($destination.'/'.$filename)) {
            $file->delete();
            mtrace("Backup completed.");
        } else {
            mtrace("Destination directory does not exist or is not writable. Leaving the backup in the course backup file area.");
        }
    }
} else {
    mtrace("Backup completed, the new file is listed in the backup area of the given course");
}
$bc->destroy();
exit(0);