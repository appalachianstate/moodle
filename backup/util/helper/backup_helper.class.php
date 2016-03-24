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
 * @package    moodlecore
 * @subpackage backup-helper
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Base abstract class for all the helper classes providing various operations
 *
 * TODO: Finish phpdocs
 */
abstract class backup_helper {

    /**
     * Given one backupid, create all the needed dirs to have one backup temp dir available
     */
    static public function check_and_create_backup_dir($backupid) {
        $backupiddir = make_backup_temp_directory($backupid, false);
        if (empty($backupiddir)) {
            throw new backup_helper_exception('cannot_create_backup_temp_dir');
        }
    }

    /**
     * Given one backupid, ensure its temp dir is completely empty
     *
     * If supplied, progress object should be ready to receive indeterminate
     * progress reports.
     *
     * @param string $backupid Backup id
     * @param \core\progress\base $progress Optional progress reporting object
     */
    static public function clear_backup_dir($backupid, \core\progress\base $progress = null) {
        $backupiddir = make_backup_temp_directory($backupid, false);
        if (!self::delete_dir_contents($backupiddir, '', $progress)) {
            throw new backup_helper_exception('cannot_empty_backup_temp_dir');
        }
        return true;
    }

    /**
     * Given one backupid, delete completely its temp dir
     *
     * If supplied, progress object should be ready to receive indeterminate
     * progress reports.
     *
     * @param string $backupid Backup id
     * @param \core\progress\base $progress Optional progress reporting object
     */
     static public function delete_backup_dir($backupid, \core\progress\base $progress = null) {
         $backupiddir = make_backup_temp_directory($backupid, false);
         self::clear_backup_dir($backupid, $progress);
         return rmdir($backupiddir);
     }

     /**
     * Given one fullpath to directory, delete its contents recursively
     * Copied originally from somewhere in the net.
     * TODO: Modernise this
     *
     * If supplied, progress object should be ready to receive indeterminate
     * progress reports.
     *
     * @param string $dir Directory to delete
     * @param string $excludedir Exclude this directory
     * @param \core\progress\base $progress Optional progress reporting object
     */
    static public function delete_dir_contents($dir, $excludeddir='', \core\progress\base $progress = null) {
        global $CFG;

        if ($progress) {
            $progress->progress();
        }

        if (!is_dir($dir)) {
            // if we've been given a directory that doesn't exist yet, return true.
            // this happens when we're trying to clear out a course that has only just
            // been created.
            return true;
        }
        $slash = "/";

        // Create arrays to store files and directories
        $dir_files      = array();
        $dir_subdirs    = array();

        // Make sure we can delete it
        chmod($dir, $CFG->directorypermissions);

        if ((($handle = opendir($dir))) == false) {
            // The directory could not be opened
            return false;
        }

        // Loop through all directory entries, and construct two temporary arrays containing files and sub directories
        while (false !== ($entry = readdir($handle))) {
            if (is_dir($dir. $slash .$entry) && $entry != ".." && $entry != "." && $entry != $excludeddir) {
                $dir_subdirs[] = $dir. $slash .$entry;

            } else if ($entry != ".." && $entry != "." && $entry != $excludeddir) {
                $dir_files[] = $dir. $slash .$entry;
            }
        }

        // Delete all files in the curent directory return false and halt if a file cannot be removed
        for ($i=0; $i<count($dir_files); $i++) {
            chmod($dir_files[$i], $CFG->directorypermissions);
            if (((unlink($dir_files[$i]))) == false) {
                return false;
            }
        }

        // Empty sub directories and then remove the directory
        for ($i=0; $i<count($dir_subdirs); $i++) {
            chmod($dir_subdirs[$i], $CFG->directorypermissions);
            if (self::delete_dir_contents($dir_subdirs[$i], '', $progress) == false) {
                return false;
            } else {
                if (remove_dir($dir_subdirs[$i]) == false) {
                    return false;
                }
            }
        }

        // Close directory
        closedir($handle);

        // Success, every thing is gone return true
        return true;
    }

    /**
     * Delete all the temp dirs older than the time specified.
     *
     * If supplied, progress object should be ready to receive indeterminate
     * progress reports.
     *
     * @param int $deletefrom Time to delete from
     * @param \core\progress\base $progress Optional progress reporting object
     */
    static public function delete_old_backup_dirs($deletefrom, \core\progress\base $progress = null) {
        $status = true;
        // Get files and directories in the backup temp dir without descend.
        $backuptempdir = make_backup_temp_directory('');
        $list = get_directory_list($backuptempdir, '', false, true, true);
        foreach ($list as $file) {
            $file_path = $backuptempdir . '/' . $file;
            $moddate = filemtime($file_path);
            if ($status && $moddate < $deletefrom) {
                //If directory, recurse
                if (is_dir($file_path)) {
                    // $file is really the backupid
                    $status = self::delete_backup_dir($file, $progress);
                //If file
                } else {
                    unlink($file_path);
                }
            }
        }
        if (!$status) {
            throw new backup_helper_exception('problem_deleting_old_backup_temp_dirs');
        }
    }

    /**
     * This function will be invoked by any log() method in backup/restore, acting
     * as a simple forwarder to the standard loggers but also, if the $display
     * parameter is true, supporting translation via get_string() and sending to
     * standard output.
     */
    static public function log($message, $level, $a, $depth, $display, $logger) {
        // Send to standard loggers
        $logmessage = $message;
        $options = empty($depth) ? array() : array('depth' => $depth);
        if (!empty($a)) {
            $logmessage = $logmessage . ' ' . implode(', ', (array)$a);
        }
        $logger->process($logmessage, $level, $options);

        // If $display specified, send translated string to output_controller
        if ($display) {
            output_controller::get_instance()->output($message, 'backup', $a, $depth);
        }
    }

    /**
     * Given one backupid and the (FS) final generated file, perform its final storage
     * into Moodle file storage. For stored files it returns the complete file_info object
     *
     * Note: the $filepath is deleted if the backup file is created successfully
     *
     * If you specify the progress monitor, this will start a new progress section
     * to track progress in processing (in case this task takes a long time).
     *
     * @param int $backupid
     * @param string $filepath zip file containing the backup
     * @param \core\progress\base $progress Optional progress monitor
     * @return stored_file if created, null otherwise
     *
     * @throws backup_helper_exception
     */
    static public function store_backup_file($backupid, $filepath, \core\progress\base $progress = null) {
        global $CFG;


        // Before leaving this routine, want to make sure
        // the backup file in the temp dir is removed, so
        // wrap up in try/catch,
        try
        {

            // First of all, get some information from the backup_controller to help us decide
            list($detailinfo, $courseinfo, $settinginfo) = backup_controller_dbops::get_moodle_backup_information($backupid, $progress);

            // Extract useful information to decide
            $hasusers   = (bool)$settinginfo['users']->value;       // Backup has users
            $isannon    = (bool)$settinginfo['anonymize']->value;   // Backup is anonymised
            $filename   = $settinginfo['filename']->value;          // Backup filename

            $backupmode = $detailinfo[0]->mode;                     // Backup mode backup::MODE_GENERAL/IMPORT/HUB
            $backuptype = $detailinfo[0]->type;                     // Backup type backup::TYPE_1ACTIVITY/SECTION/COURSE
            $userid     = $detailinfo[0]->userid;                   // User->id executing the backup
            $id         = $detailinfo[0]->id;                       // Id of activity/section/course (depends of type)
            $courseid   = $detailinfo[0]->courseid;                 // Id of the course
            $format     = $detailinfo[0]->format;                   // Type of backup file


            // Backups of type IMPORT aren't stored ever
            // and in this case do not remove the backup
            // file in the temp dir
            if ($backupmode == backup::MODE_IMPORT) {
                return null;
            }

            // Will need a filename to add file to filedir
            if (empty($filename)) {
                throw new backup_helper_exception('backup_helper::store_backup_file() expects valid $filename settings info.');
            }

            // Problem if zip file does not exist
            if (!is_readable($filepath)) {
                throw new backup_helper_exception('backup_helper::store_backup_file() expects valid $filepath parameter');

            }


            // Defaults for the file record
            $component = 'backup';
            $ctxid     = 0;
            $filearea  = '';
            $itemid    = 0;

            // Adjustments based on $backuptype value
            switch ($backuptype) {
                case backup::TYPE_1ACTIVITY:
                    $ctxid     = context_module::instance($id)->id;
                    $filearea  = 'activity';
                    break;
                case backup::TYPE_1SECTION:
                    $ctxid     = context_course::instance($courseid)->id;
                    $filearea  = 'section';
                    $itemid    = $id;
                    break;
                case backup::TYPE_1COURSE:
                    $ctxid     = context_course::instance($courseid)->id;
                    $filearea  = 'course';
                    break;
            }


            // Adjustments based on the $backupmode value
            if ($backupmode == backup::MODE_HUB) {

                // Backups of type HUB (by definition never have user info)
                // are sent to user's "user_tohub" file area. The upload process
                // will be responsible for cleaning that filearea once finished
                $ctxid     = context_user::instance($userid)->id;
                $component = 'user';
                $filearea  = 'tohub';
                $itemid    = 0;

            } elseif ($backupmode == backup::MODE_GENERAL && (!$hasusers || $isannon)) {

                // Backups without user info or with the anonymise functionality
                // enabled are sent to user's "user_backup"
                // file area. Maintenance of such area is responsibility of
                // the user via corresponding file manager frontend
                $ctxid     = context_user::instance($userid)->id;
                $component = 'user';
                $filearea  = 'backup';
                $itemid    = 0;

            } elseif ($backupmode == backup::MODE_AUTOMATED) {

                // Automated backups have there own special area!
                $filearea  = 'automated';

                // Check if backup file should be copied to an
                // external destination.
                $config = get_config('backup');
                if ($config->backup_auto_storage != 0) {

                    $externdest = $config->backup_auto_destination;
                    if (empty($externdest)) {
                        throw new backup_helper_exception('backup_helper::store_backup_file() missing backup_auto_destination configuration');
                    }

                    if (stripos($externdest, 's3://') === 0) {
                        // Destination is AWS S3 bucket
                        self::store_backup_file_s3($filepath, $filename, $externdest, $detailinfo[0], $courseinfo['course'][0]);
                    } else {
                        // Destination is filesystem directory
                        if (is_dir($externdest) && is_writable($externdest)) {
                            $filedest = $externdest . '/' . $filename;
                            umask($CFG->umaskpermissions);
                            if (copy($filepath, $filedest)) {
                                // Expect chmod to fail if perms do not make
                                // sense outside of data root.
                                @chmod($filedest, $CFG->filepermissions);
                            } else {
                                throw new backup_helper_exception('backup_helper::store_backup_file() copy backup to backup_auto_destination failed');
                            }
                        } else {
                            throw new backup_helper_exception('backup_helper::store_backup_file() invalid backup_auto_destination configuration');
                        }
                    }

                    // At this point, have copied the backup file to
                    // external destination. If is only destination
                    // then leave now, returning null to indicate no
                    // stored_file object created, otherwise drop
                    // through to create entry in the filedir repo
                    if ($config->backup_auto_storage == 1) {
                        unlink($filepath);
                        return null;
                    }

                } // $config->backup_auto_storage != 0

            } // $backupmode == backup::MODE_AUTOMATED


            // If an entry that matches this backup file's specs
            // already exists in the filedir repo (by checking the
            // mdl_files table), assume it is bad, and should be
            // replaced with this new one
            $fs = get_file_storage();

            $pathnamehash = $fs->get_pathname_hash($ctxid, $component, $filearea, $itemid, '/', $filename);
            if ($fs->file_exists_by_hash($pathnamehash)) {
                $fs->get_file_by_hash($pathnamehash)->delete();
            }

            // Fix up a file record to put in the filedir repo
            $stored_file = $fs->create_file_from_pathname(
                array('contextid'   => $ctxid,
                      'component'   => $component,
                      'filearea'    => $filearea,
                      'itemid'      => $itemid,
                      'filepath'    => '/',
                      'filename'    => $filename,
                      'userid'      => $userid,
                      'timecreated' => time(),
                      'timemodified'=> time()),
                $filepath);

            // Delete file in original location, i.e. backup temp dir
            unlink($filepath);

            return $stored_file;

        }
        catch (Exception $exc)
        {
            // If the temp file is present make best
            // attempt to remove it, but don't let
            // the file removal cause a new exception
            if (@file_exists($filepath)) {
                @unlink($filepath);
            }
            // Re-throw whatever brought us here
            throw $exc;
        }

    }

    /**
     * This function simply marks one param to be considered as straight sql
     * param, so it won't be searched in the structure tree nor converted at
     * all. Useful for better integration of definition of sources in structure
     * and DB stuff
     */
    public static function is_sqlparam($value) {
        return array('sqlparam' => $value);
    }

    /**
     * This function returns one array of itemnames that are being handled by
     * inforef.xml files. Used both by backup and restore
     */
    public static function get_inforef_itemnames() {
        return array('user', 'grouping', 'group', 'role', 'file', 'scale', 'outcome', 'grade_item', 'question_category');
    }

    public static function store_backup_file_s3($filepath, $targetname, $s3url, $detailinfo, $courseinfo)
    {   global $CFG;


        // Strip off the leading s3://
        $temparray      = explode('//', $s3url, 2);
        $s3bucketpath   = array_pop($temparray);
        // Parse to get the bucket name only,
        // everything up to the first slash
        $temparray      = explode('/', $s3bucketpath);
        $s3bucketname   = array_shift($temparray);
        if (empty($s3bucketname)) {
            // Misconfigured, stop everything until corrected
            throw new backup_helper_exception('backup_helper::store_backup_file() external S3 destination misconfigured');
        }

        // Parse to get the folder name only,
        // everything after the first slash
        $s3folder = '';
        if (strpos($s3bucketpath, '/') !== false) {
            $temparray  = explode('/', $s3bucketpath, 2);
            $s3folder   = rtrim(array_pop($temparray), '/');
            if (!empty($s3folder)) {
                $s3folder .= '/';
            }
        }

        require_once("{$CFG->libdir}/vendor/autoload.php");
        $s3client = Aws\S3\S3Client::factory($CFG->aws_config);

        if (!$s3client->doesBucketExist($s3bucketname)) {
            throw new backup_helper_exception('backup_helper::store_backup_file() S3 bucket does not exist');
        }

        $s3result = $s3client->putObject(array(
            'Bucket'        => $s3bucketname,
            'Key'           => $s3folder . $targetname,
            'SourceFile'    => $filepath,
            'Metadata'      => array(
                'moodle-course-id'      => "{$detailinfo->courseid}",
                'moodle-course-title'   => "{$courseinfo['title']}",
                'moodle-backup-site'    => md5(get_site_identifier()),
                'moodle-backup-id'      => "{$detailinfo->backup_id}",
                'moodle-backup-type'    => "{$detailinfo->type}",
                'moodle-backup-mode'    => "{$detailinfo->mode}",
                'moodle-backup-date'    => time())));

        $s3status = $s3result['@metadata']['statusCode'];
        if ($s3status != 200) {
            throw new backup_helper_exception('backup_helper::store_backup_file() load backup to S3 bucket failed');
        }

    }

}

/*
 * Exception class used by all the @helper stuff
 */
class backup_helper_exception extends backup_exception {

    public function __construct($errorcode, $a=NULL, $debuginfo=null) {
        parent::__construct($errorcode, $a, $debuginfo);
    }
}
