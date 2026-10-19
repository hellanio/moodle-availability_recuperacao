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
 * Independent QA of M1.3: course duplication (core_course_external::duplicate_course) and recycle bin restores
 * of the recovery activity and of the round. Every path must fail closed.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(condition::class)]
final class qa_m13_restore_test extends \advanced_testcase {
    /** @var string Name of the recovery activity. */
    private const TARGET_NAME = 'QA M13 recovery activity';

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Recovery activity. */
    private \stdClass $target;

    /** @var \stdClass Round. */
    private \stdClass $round;

    /** @var array<string, int> Students by case status ("none" without case). */
    private array $users = [];

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
        require_once($CFG->dirroot . '/course/externallib.php');
    }

    /**
     * Course, round, recovery activity restricted by the round only, students sent, identified and without case.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB, $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        set_config('enableavailability', 1);
        $generator = $this->getDataGenerator();
        $modgenerator = $generator->get_plugin_generator('mod_recuperacao');
        $this->course = $generator->create_course();
        $this->target = $generator->create_module('page', ['course' => $this->course->id, 'name' => self::TARGET_NAME]);
        $this->round = $modgenerator->create_instance([
            'course' => $this->course->id,
            'targetcmid' => $this->target->cmid,
        ]);
        $root = tree::get_root_json([condition::get_json((int) $this->round->id)], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $this->target->cmid]);
        rebuild_course_cache((int) $this->course->id, true);
        // M1.4a (ADR-016): the round hid its unprotected activity; the tree written above has no event, so the guard
        // is told here, as a save of the activity settings would.
        \mod_recuperacao\local\target_guard::sync_cms([(int) $this->target->cmid]);
        foreach ([state_machine::STATUS_SENT, state_machine::STATUS_IDENTIFIED, 'none'] as $status) {
            $user = $generator->create_and_enrol($this->course, 'student');
            if ($status !== 'none') {
                $modgenerator->create_case(['recuperacaoid' => $this->round->id, 'userid' => $user->id, 'status' => $status]);
            }
            $this->users[$status] = (int) $user->id;
        }
        $this->assertTrue($this->sees((int) $this->course->id, (int) $this->target->cmid, $this->users['sent']));
        $this->assertFalse($this->sees((int) $this->course->id, (int) $this->target->cmid, $this->users['identified']));
    }

    /**
     * Deletes a course module through the course format actions (5.2) or course_delete_module() (5.1).
     *
     * @param int $courseid Course.
     * @param int $cmid Course module.
     * @param bool $async Asynchronous deletion when a plugin (recycle bin) recommends it.
     * @return void
     */
    private function delete_cm(int $courseid, int $cmid, bool $async): void {
        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            \core_courseformat\formatactions::cm($courseid)->delete($cmid, $async);
        } else {
            course_delete_module($cmid, $async);
        }
    }

    /**
     * Whether a user sees an activity, as that user.
     *
     * @param int $courseid Course.
     * @param int $cmid Course module.
     * @param int $userid User.
     * @return bool
     */
    private function sees(int $courseid, int $cmid, int $userid): bool {
        $this->setUser($userid);
        rebuild_course_cache($courseid, true);
        get_fast_modinfo(0, 0, true);
        $visible = get_fast_modinfo($courseid, $userid)->get_cm($cmid)->uservisible;
        $this->setUser(null);
        return $visible;
    }

    /**
     * Course modules of a course with a name.
     *
     * @param int $courseid Course.
     * @param string $name Name.
     * @return int[]
     */
    private function cms_named(int $courseid, string $name): array {
        get_fast_modinfo(0, 0, true);
        $ids = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if ($cm->name === $name) {
                $ids[] = (int) $cm->id;
            }
        }
        return $ids;
    }

    /**
     * Round ids of the recuperacao conditions of an activity; fails when the restriction was lost.
     *
     * @param int $cmid Course module.
     * @return int[]
     */
    private function condition_ids(int $cmid): array {
        global $DB;
        $json = $DB->get_field('course_modules', 'availability', ['id' => $cmid], MUST_EXIST);
        $this->assertNotEmpty($json, 'Restriction lost');
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
     * An activity closed by its round (M5.1, ADR-035 P3, QA-M51-01): hidden, without any recuperacao condition.
     *
     * @param int $cmid Course module.
     * @return void
     */
    private function assert_closed_without_condition(int $cmid): void {
        global $DB;
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $cmid]));
        $json = (string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
        $this->assertStringNotContainsString('"recuperacao"', $json);
    }

    /**
     * Course duplicated through the web service function, with and without users: the copy points to its own round;
     * without users nobody opens it, with users only the student sent (enrolments come with the users).
     */
    public function test_duplicate_course_with_and_without_users(): void {
        global $DB;
        foreach ([0, 1] as $withusers) {
            $this->setAdminUser();
            $result = \core_course_external::duplicate_course(
                (int) $this->course->id,
                'QA copy ' . $withusers,
                'QACOPY' . $withusers,
                (int) $this->course->category,
                1,
                [['name' => 'users', 'value' => $withusers]]
            );
            $this->setUser(null);
            $newcourseid = (int) $result['id'];
            $newround = $DB->get_record('recuperacao', ['course' => $newcourseid], '*', MUST_EXIST);
            [$newtarget] = $this->cms_named($newcourseid, self::TARGET_NAME);
            $this->assertSame([(int) $newround->id], $this->condition_ids($newtarget));
            $this->assertSame($newtarget, (int) $newround->targetcmid);
            $this->assertSame(
                $withusers ? 2 : 0,
                $DB->count_records('recuperacao_case', ['recuperacaoid' => $newround->id])
            );
            if (!$withusers) {
                foreach ($this->users as $userid) {
                    $this->getDataGenerator()->enrol_user($userid, $newcourseid, 'student');
                }
            }
            $this->assertSame((bool) $withusers, $this->sees($newcourseid, $newtarget, $this->users['sent']));
            $this->assertFalse($this->sees($newcourseid, $newtarget, $this->users['identified']));
            $this->assertFalse($this->sees($newcourseid, $newtarget, $this->users['none']));
        }
        // The original round and activity are untouched.
        $this->assertSame([(int) $this->round->id], $this->condition_ids((int) $this->target->cmid));
        $this->assertTrue($this->sees((int) $this->course->id, (int) $this->target->cmid, $this->users['sent']));
    }

    /**
     * Recovery activity deleted to the recycle bin and restored from it: the round is not in that backup, so the
     * restored activity keeps the restriction pointing to -1 and nobody opens it (fail closed, sent included).
     */
    public function test_recyclebin_restore_target(): void {
        set_config('coursebinenable', 1, 'tool_recyclebin');
        set_config('coursebinexpiry', 0, 'tool_recyclebin');
        $this->setAdminUser();
        $this->delete_cm((int) $this->course->id, (int) $this->target->cmid, true);
        $this->runAdhocTasks('\core_course\task\course_delete_modules');
        $bin = new \tool_recyclebin\course_bin((int) $this->course->id);
        $items = $bin->get_items();
        $this->assertCount(1, $items);
        $bin->restore_item(reset($items));
        $this->setUser(null);

        [$restored] = $this->cms_named((int) $this->course->id, self::TARGET_NAME);
        $this->assertNotEquals((int) $this->target->cmid, $restored);
        $this->assertSame([-1], $this->condition_ids($restored));
        foreach ($this->users as $userid) {
            $this->assertFalse($this->sees((int) $this->course->id, $restored, $userid));
        }
    }

    /**
     * Round deleted to the recycle bin: the recovery activity closes for everybody at once (async deletion in
     * progress) and stays closed after the deletion and after the round is restored from the bin (since M5.1 the
     * deletion hides it and removes the condition of the round, ADR-035 P3, QA-M51-01; the restored round has a new id
     * and no recovery activity).
     */
    public function test_recyclebin_delete_and_restore_round(): void {
        global $DB;
        set_config('coursebinenable', 1, 'tool_recyclebin');
        set_config('coursebinexpiry', 0, 'tool_recyclebin');
        $roundcmid = (int) $this->round->cmid;
        $this->setAdminUser();
        $this->delete_cm((int) $this->course->id, $roundcmid, true);
        $this->setUser(null);
        $this->assertSame(1, (int) $DB->get_field('course_modules', 'deletioninprogress', ['id' => $roundcmid]));
        $this->assertFalse($this->sees((int) $this->course->id, (int) $this->target->cmid, $this->users['sent']));

        $this->setAdminUser();
        $this->runAdhocTasks('\core_course\task\course_delete_modules');
        $this->assertFalse($DB->record_exists('recuperacao', ['id' => $this->round->id]));
        $this->setUser(null);
        $this->assert_closed_without_condition((int) $this->target->cmid);
        foreach ($this->users as $userid) {
            $this->assertFalse($this->sees((int) $this->course->id, (int) $this->target->cmid, $userid));
        }

        $this->setAdminUser();
        $bin = new \tool_recyclebin\course_bin((int) $this->course->id);
        $items = $bin->get_items();
        $this->assertCount(1, $items);
        $bin->restore_item(reset($items));
        $this->setUser(null);
        $newround = $DB->get_record('recuperacao', ['course' => $this->course->id], '*', MUST_EXIST);
        $this->assertNotEquals((int) $this->round->id, (int) $newround->id);
        $this->assertEmpty($newround->targetcmid);
        $this->assert_closed_without_condition((int) $this->target->cmid);
        foreach ($this->users as $userid) {
            $this->assertFalse($this->sees((int) $this->course->id, (int) $this->target->cmid, $userid));
        }
    }
}
