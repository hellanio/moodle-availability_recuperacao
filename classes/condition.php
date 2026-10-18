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

namespace availability_recuperacao;

use core_availability\capability_checker;
use core_availability\info;
use core_availability\info_module;
use mod_recuperacao\local\access\target_access;

/**
 * Restriction by recovery round: the activity is available only to the students the round gives access to.
 *
 * Server side only (M1.2). Stored as {"type":"recuperacao","id":<round instance id>}. The activity checked is the
 * course module carrying the condition, which must be the recovery activity of the round; the decision is
 * mod_recuperacao's access contract (target_access): sent students and, as the round settings allowed when they
 * were resolved, passed or failed students (ADR-022, ADR-022a). Fail closed (ADR-014): a negated condition, a
 * condition on a section, a missing contract or round and any error mean "not available".
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class condition extends \core_availability\condition {
    /** @var string SQL returning no user, for every refusal. */
    protected const NOBODY_SQL = 'SELECT u.id FROM {user} u WHERE 1 = 0';

    /** @var int Round (recuperacao instance) id; -1 after a restore without the round. */
    protected int $roundid;

    /**
     * Constructor.
     *
     * @param \stdClass $structure Data structure from JSON decode.
     * @throws \coding_exception If invalid data structure.
     */
    public function __construct($structure) {
        if (!isset($structure->id) || !is_int($structure->id)) {
            throw new \coding_exception('Invalid ->id for recuperacao condition');
        }
        $this->roundid = $structure->id;
    }

    /**
     * Saves the condition as a JSON-ready structure.
     *
     * @return \stdClass
     */
    public function save() {
        return self::get_json($this->roundid);
    }

    /**
     * JSON structure of a condition on a round, for code and tests building availability trees.
     *
     * @param int $roundid Round (recuperacao instance) id.
     * @return \stdClass
     */
    public static function get_json(int $roundid): \stdClass {
        return (object) ['type' => 'recuperacao', 'id' => $roundid];
    }

    /**
     * Whether the user may open the activity carrying the condition.
     *
     * @param bool $not Negated condition: never available (ADR-014).
     * @param info $info Item we are checking.
     * @param bool $grabthelot Unused: the contract reads one indexed row per user.
     * @param int $userid User id.
     * @return bool
     */
    public function is_available($not, info $info, $grabthelot, $userid) {
        if ($not) {
            return false;
        }
        try {
            $targetcmid = self::target_cmid($info);
            if (!$targetcmid || !class_exists(target_access::class)) {
                return false;
            }
            return target_access::is_eligible($this->roundid, (int) $userid, $targetcmid);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Generic description: no round, activity or student name (SEC: no leak to who has no access).
     *
     * @param bool $full Unused.
     * @param bool $not Negated condition.
     * @param info $info Item we are checking.
     * @return string
     */
    public function get_description($full, $not, info $info) {
        return get_string($not ? 'requires_notrecuperacao' : 'requires_recuperacao', 'availability_recuperacao');
    }

    /**
     * Debug representation.
     *
     * @return string
     */
    protected function get_debug_string() {
        return '#' . $this->roundid;
    }

    /**
     * Always kept on restore: dropping it would open the activity to everyone.
     *
     * @param int $restoreid The restore id.
     * @param int $courseid The id of the course.
     * @param \base_logger $logger The logger being used.
     * @param string $name Name of item being restored.
     * @param \base_task $task The task being performed.
     * @return bool
     */
    public function include_after_restore($restoreid, $courseid, \base_logger $logger, $name, \base_task $task) {
        return true;
    }

    /**
     * Points the condition to the restored round, or to -1 (never available) when the round was not restored.
     *
     * @param string $restoreid The restore id.
     * @param int $courseid The id of the target course.
     * @param \base_logger $logger The logger being used.
     * @param string $name Name of item being restored.
     * @return bool True: the condition always changes or is confirmed as unusable.
     */
    public function update_after_restore($restoreid, $courseid, \base_logger $logger, $name) {
        $rec = $this->roundid > 0 ? \restore_dbops::get_backup_ids_record($restoreid, 'recuperacao', $this->roundid) : false;
        if ($rec && $rec->newitemid) {
            $this->roundid = (int) $rec->newitemid;
        } else {
            $this->roundid = -1;
            $logger->process('Restored item (' . $name .
                ') has availability condition on a recovery round that was not restored', \backup::LOG_WARNING);
        }
        return true;
    }

    /**
     * The condition depends on no core table remapped by update_dependency_id().
     *
     * @param string $table Table name.
     * @param int $oldid Previous id.
     * @param int $newid New id.
     * @return bool Always false.
     */
    public function update_dependency_id($table, $oldid, $newid) {
        return false;
    }

    /**
     * The condition is permanent per user, so it filters user lists of the activity.
     *
     * @return bool
     */
    public function is_applied_to_user_lists() {
        return true;
    }

    /**
     * Keeps only the users target_access lets in; nobody when negated or on error.
     *
     * @param array $users Array of userid => object.
     * @param bool $not Negated condition.
     * @param info $info Item we are checking.
     * @param capability_checker $checker Unused: users who view hidden items are added by core.
     * @return array Filtered users.
     */
    public function filter_user_list(array $users, $not, info $info, capability_checker $checker) {
        global $DB;
        if ($not || !$users) {
            return [];
        }
        try {
            [$sql, $params] = $this->get_user_list_sql($not, $info, true);
            $allowed = array_flip($DB->get_fieldset_sql($sql, $params));
            return array_intersect_key($users, $allowed);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * SQL of the users target_access lets in; a query returning nobody when negated or on error, never ''.
     *
     * @param bool $not Negated condition.
     * @param info $info Item we are checking.
     * @param bool $onlyactive Unused: the contract always requires an active enrolment.
     * @return array SQL and parameters.
     */
    public function get_user_list_sql($not, info $info, $onlyactive) {
        if ($not) {
            return [self::NOBODY_SQL, []];
        }
        try {
            $targetcmid = self::target_cmid($info);
            if (!$targetcmid || !class_exists(target_access::class)) {
                return [self::NOBODY_SQL, []];
            }
            return target_access::user_list_sql($this->roundid, $targetcmid);
        } catch (\Throwable $e) {
            return [self::NOBODY_SQL, []];
        }
    }

    /**
     * Course module carrying the condition; 0 for a section or any other item.
     *
     * @param info $info Item we are checking.
     * @return int Course module id.
     */
    protected static function target_cmid(info $info): int {
        if (!$info instanceof info_module) {
            return 0;
        }
        return (int) $info->get_course_module()->id;
    }
}
