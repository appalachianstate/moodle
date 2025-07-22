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
 * Standard log reader/writer.
 *
 * @package    logstore_standard
 * @copyright  2014 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_standard\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Class containing functions for delete logstore cron task.
 */
class cleanup_task extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskcleanup', 'logstore_standard');
    }

    /**
     * Do the job.
     * Throw exceptions on errors (the job will be retried).
     */
    public function execute() {
        global $DB;

        $loglifetime = (int)get_config('logstore_standard', 'loglifetime');
        if (empty($loglifetime) || $loglifetime < 0) {
            return;
        }

        $loglifetime = time() - ($loglifetime * 3600 * 24); // Value in days.
        $lifetimep = [$loglifetime];
        $start = time();
        $querylimit = 131072;
        $recordstodelete = true;

        while ($recordstodelete) {
            $deletebatch = $DB->get_records_select(
                "logstore_standard_log",
                "timecreated < ?",
                $lifetimep,
                "",
                "id",
                0,
                $querylimit);
            if (!$deletebatch) {
                $recordstodelete = false; // Break out if no records match the delete criteria.
                break;
            }
            // Need helper DB function for final delete, delete_records doesn't have explicit limit keyword.
            $deletebatchlist = array_keys($deletebatch);
            list($insql, $inparams) = $DB->get_in_or_equal($deletebatchlist, SQL_PARAMS_NAMED);
            $DB->delete_records_select('logstore_standard_log', "id $insql", $inparams);

            if (time() > $start + 300) {
                // Do not churn on log deletion for too long each run.
                mtrace(" Delete records timeout has been reached, will resume at next scheduled run!");
                break;
            }
        }
        mtrace(" Deleted old log records from standard store.");
    }
}
