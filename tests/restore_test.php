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

use core_availability\tree;
use mod_recuperacao\local\state_machine;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Restore and duplication of the condition end to end (M1.3): real backups and restores.
 *
 * The condition always survives a restore (include_after_restore); it points to the restored round, or to -1 (never
 * available) when the round was not in the same restore. Access still follows target_access: only a sent case of the
 * restored round, on the restored recovery activity.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(condition::class)]
final class restore_test extends \advanced_testcase {
    /** @var string Name of the recovery activity. */
    private const TARGET_NAME = 'Tarefa recuperacao';

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Recovery activity restricted by the round, with cmid. */
    private \stdClass $target;

    /** @var \stdClass Round, with cmid. */
    private \stdClass $round;

    /** @var array<string, int> Students by case status ("none" without case). */
    private array $users = [];

    /** @var int Copies made, for unique short names. */
    private int $copies = 0;

    /**
     * Loads the backup and restore libraries.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/lib.php');
    }

    /**
     * Course with the round, the recovery activity restricted by the round condition only, and three students.
     *
     * The cases are written directly (no sending): the legacy managed group is not involved before the restore.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('enableavailability', 1);
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $generator = $this->getDataGenerator();
        $modgenerator = $generator->get_plugin_generator('mod_recuperacao');
        $this->course = $generator->create_course();
        $this->target = $generator->create_module('page', ['course' => $this->course->id, 'name' => self::TARGET_NAME]);
        $this->round = $modgenerator->create_instance([
            'course' => $this->course->id,
            'name' => 'Rodada',
            'targetcmid' => $this->target->cmid,
        ]);
        $root = tree::get_root_json([condition::get_json((int) $this->round->id)], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $this->target->cmid]);
        rebuild_course_cache((int) $this->course->id, true);
        foreach ([state_machine::STATUS_SENT, state_machine::STATUS_IDENTIFIED, 'none'] as $status) {
            $user = $generator->create_and_enrol($this->course, 'student');
            if ($status !== 'none') {
                $modgenerator->create_case(['recuperacaoid' => $this->round->id, 'userid' => $user->id, 'status' => $status]);
            }
            $this->users[$status] = (int) $user->id;
        }
        $this->assertTrue($this->sees((int) $this->course->id, (int) $this->target->cmid, $this->users['sent']));
    }

    /**
     * Backs up an item and restores it, as the administrator.
     *
     * @param string $type backup::TYPE_1COURSE or backup::TYPE_1ACTIVITY.
     * @param int $id Course id or course module id.
     * @param bool $users Whether user data goes with the backup.
     * @param int|null $intocourseid Existing course to restore into; null for a new course.
     * @return int Course id restored into.
     */
    private function backup_restore(string $type, int $id, bool $users, ?int $intocourseid = null): int {
        global $CFG, $USER;

        $this->setAdminUser();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $bc = new \backup_controller(
            $type,
            $id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_SAMESITE,
            $USER->id
        );
        $plan = $bc->get_plan();
        $plan->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $plan->get_setting('users')->set_value($users);
        $backupid = $bc->get_backupid();
        $basepath = $plan->get_basepath();
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();
        if (!file_exists($basepath . '/moodle_backup.xml')) {
            $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $basepath);
        }

        if ($intocourseid === null) {
            $this->copies++;
            $intocourseid = \restore_dbops::create_new_course(
                'Copia ' . $this->copies,
                'COPY' . $this->copies,
                $this->course->category
            );
            $target = \backup::TARGET_NEW_COURSE;
        } else {
            $target = \backup::TARGET_EXISTING_ADDING;
        }
        $rc = new \restore_controller(
            $backupid,
            $intocourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_SAMESITE,
            $USER->id,
            $target
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        $this->setUser(null);
        return $intocourseid;
    }

    /**
     * Whether a user sees an activity, its restrictions included.
     *
     * @param int $courseid Course.
     * @param int $cmid Course module.
     * @param int $userid User.
     * @return bool
     */
    private function sees(int $courseid, int $cmid, int $userid): bool {
        // As the user: the visibility of the legacy managed group depends on who is looking.
        $this->setUser($userid);
        rebuild_course_cache($courseid, true);
        get_fast_modinfo(0, 0, true);
        $uservisible = get_fast_modinfo($courseid, $userid)->get_cm($cmid)->uservisible;
        $this->setUser(null);
        return $uservisible;
    }

    /**
     * Course modules of a course with a name.
     *
     * @param int $courseid Course.
     * @param string $name Activity name.
     * @return int[] Course module ids.
     */
    private function cms_named(int $courseid, string $name): array {
        $ids = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if ($cm->name === $name) {
                $ids[] = (int) $cm->id;
            }
        }
        return $ids;
    }

    /**
     * Round ids of the recuperacao conditions on an activity.
     *
     * @param int $cmid Course module.
     * @return int[]
     */
    private function condition_ids(int $cmid): array {
        global $DB;
        $json = $DB->get_field('course_modules', 'availability', ['id' => $cmid], MUST_EXIST);
        $this->assertNotEmpty($json, 'The restored activity lost its restriction');
        $ids = [];
        $walk = function (\stdClass $node) use (&$walk, &$ids): void {
            foreach ($node->c ?? [] as $child) {
                $walk($child);
            }
            if (($node->type ?? '') === 'recuperacao') {
                $ids[] = $node->id;
            }
        };
        $walk(json_decode($json));
        return $ids;
    }

    /**
     * Whole course without user data: the condition points to the restored round, whose recovery activity is the
     * restored one; no case comes, so nobody (enrolled again) may open it.
     */
    public function test_course_without_users(): void {
        global $DB;
        $newcourseid = $this->backup_restore(\backup::TYPE_1COURSE, (int) $this->course->id, false);
        $newround = $DB->get_record('recuperacao', ['course' => $newcourseid], '*', MUST_EXIST);
        [$newtarget] = $this->cms_named($newcourseid, self::TARGET_NAME);

        $this->assertNotEquals((int) $this->round->id, (int) $newround->id);
        $this->assertSame([(int) $newround->id], $this->condition_ids($newtarget));
        $this->assertSame($newtarget, (int) $newround->targetcmid);
        $this->assertSame(0, $DB->count_records('recuperacao_case', ['recuperacaoid' => $newround->id]));

        $student = $this->getDataGenerator()->create_and_enrol(get_course($newcourseid), 'student');
        foreach ([...array_values($this->users), (int) $student->id] as $userid) {
            $this->getDataGenerator()->enrol_user($userid, $newcourseid, 'student');
            $this->assertFalse($this->sees($newcourseid, $newtarget, $userid));
        }
        // The original is untouched.
        $this->assertSame([(int) $this->round->id], $this->condition_ids((int) $this->target->cmid));
        $this->assertTrue($this->sees((int) $this->course->id, (int) $this->target->cmid, $this->users['sent']));
    }

    /**
     * Whole course with user data: the cases come as they were (I-3), so the student sent, enrolled in the copy,
     * opens the restored recovery activity; identified and without case do not.
     */
    public function test_course_with_users(): void {
        global $DB;
        $newcourseid = $this->backup_restore(\backup::TYPE_1COURSE, (int) $this->course->id, true);
        $newround = $DB->get_record('recuperacao', ['course' => $newcourseid], '*', MUST_EXIST);
        [$newtarget] = $this->cms_named($newcourseid, self::TARGET_NAME);

        $this->assertContains((int) $newround->id, $this->condition_ids($newtarget));
        $this->assertSame($newtarget, (int) $newround->targetcmid);
        $this->assertSame(state_machine::STATUS_SENT, $DB->get_field(
            'recuperacao_case',
            'status',
            ['recuperacaoid' => $newround->id, 'userid' => $this->users['sent']]
        ));

        $this->assertTrue($this->sees($newcourseid, $newtarget, $this->users['sent']));
        $this->assertFalse($this->sees($newcourseid, $newtarget, $this->users[state_machine::STATUS_IDENTIFIED]));
        $this->assertFalse($this->sees($newcourseid, $newtarget, $this->users['none']));
    }

    /**
     * The recovery activity duplicated in its own course: the round is not in that restore, so the copy points to -1
     * and nobody opens it, the student sent included; the original keeps working.
     */
    public function test_duplicate_target(): void {
        $this->setAdminUser();
        if (method_exists(\core_courseformat\local\cmactions::class, 'duplicate')) {
            $newcm = \core_courseformat\formatactions::cm($this->course->id)->duplicate((int) $this->target->cmid);
        } else {
            // Moodle 5.1.
            $newcm = duplicate_module($this->course, get_fast_modinfo($this->course)->get_cm((int) $this->target->cmid));
        }
        $this->setUser(null);

        $this->assertSame([-1], $this->condition_ids((int) $newcm->id));
        foreach ($this->users as $userid) {
            $this->assertFalse($this->sees((int) $this->course->id, (int) $newcm->id, $userid));
        }
        $this->assertTrue($this->sees((int) $this->course->id, (int) $this->target->cmid, $this->users['sent']));
    }

    /**
     * A copy of the recovery activity that keeps pointing to the round (a restore that maps it to itself) is still
     * closed: target_access only opens the activity the round points to.
     */
    public function test_copy_pointing_to_the_round_is_closed(): void {
        global $DB;
        $copy = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->set_field(
            'course_modules',
            'availability',
            $DB->get_field('course_modules', 'availability', ['id' => $this->target->cmid]),
            ['id' => $copy->cmid]
        );
        $this->assertSame([(int) $this->round->id], $this->condition_ids((int) $copy->cmid));
        foreach ($this->users as $userid) {
            $this->assertFalse($this->sees((int) $this->course->id, (int) $copy->cmid, $userid));
        }
    }

    /**
     * Only the recovery activity restored in another course, with or without user data: no round in that restore,
     * so the condition is -1 and nobody opens it.
     */
    public function test_target_alone_in_other_course(): void {
        $other = $this->getDataGenerator()->create_course();
        foreach ($this->users as $userid) {
            $this->getDataGenerator()->enrol_user($userid, (int) $other->id, 'student');
        }
        foreach ([false, true] as $users) {
            $this->backup_restore(\backup::TYPE_1ACTIVITY, (int) $this->target->cmid, $users, (int) $other->id);
        }
        $copies = $this->cms_named((int) $other->id, self::TARGET_NAME);
        $this->assertCount(2, $copies);
        foreach ($copies as $cmid) {
            $this->assertSame([-1], $this->condition_ids($cmid));
            foreach ($this->users as $userid) {
                $this->assertFalse($this->sees((int) $other->id, $cmid, $userid));
            }
        }
    }

    /**
     * Only the round restored in its own course: the copy has no recovery activity, so it opens nothing, and the
     * condition of the original activity still points to the original round.
     */
    public function test_round_alone_in_same_course(): void {
        global $DB;
        $this->backup_restore(\backup::TYPE_1ACTIVITY, (int) $this->round->cmid, true, (int) $this->course->id);
        $newround = $DB->get_record_select(
            'recuperacao',
            'course = :course AND id <> :id',
            ['course' => $this->course->id, 'id' => $this->round->id],
            '*',
            MUST_EXIST
        );

        $this->assertNull($newround->targetcmid);
        $this->assertSame([(int) $this->round->id], $this->condition_ids((int) $this->target->cmid));
        $this->assertTrue($this->sees((int) $this->course->id, (int) $this->target->cmid, $this->users['sent']));
        $this->assertFalse($this->sees(
            (int) $this->course->id,
            (int) $this->target->cmid,
            $this->users[state_machine::STATUS_IDENTIFIED]
        ));
    }
}
