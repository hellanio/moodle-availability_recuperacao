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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

/**
 * QA steps of M1.1+M1.2: put the server side condition on an activity without the frontend (M1.4).
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_availability_recuperacao_qa extends behat_base {
    /**
     * Restricts an activity to the students sent to a round, hidden entirely from the others (showc false).
     *
     * @Given /^the activity "(?P<activity_string>[^"]*)" is restricted by the recovery round "(?P<round_string>[^"]*)" and hidden$/
     * @param string $activityname Activity idnumber.
     * @param string $roundname Round idnumber.
     * @return void
     */
    public function the_activity_is_restricted_by_round(string $activityname, string $roundname): void {
        global $DB;
        $cmid = (int) $DB->get_field('course_modules', 'id', ['idnumber' => $activityname], MUST_EXIST);
        $round = $DB->get_record('course_modules', ['idnumber' => $roundname], 'id, course, instance', MUST_EXIST);
        $condition = \availability_recuperacao\condition::get_json((int) $round->instance);
        $root = \core_availability\tree::get_root_json([$condition], \core_availability\tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $cmid]);
        rebuild_course_cache((int) $round->course, true);
    }
}
