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

use core_availability\info_module;
use core_availability\tree;
use mod_recuperacao\local\access\target_access;
use mod_recuperacao\local\state_machine;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Security gate of the M1.3 delta (D2/D3 and restore): negative tests.
 *
 * Status values differing from "sent" only in case or spaces, rounds of another course, ids -1/0 and restores with
 * user data never open the recovery activity to someone who was not sent in the round of that very activity, and the
 * user list SQL agrees with is_eligible().
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(condition::class)]
#[CoversClass(target_access::class)]
final class sec_m13_access_test extends \advanced_testcase {
    /** @var string Name of the recovery activity. */
    private const TARGET_NAME = 'Alvo sec m13';

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Recovery activity, with cmid. */
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
     * Course, recovery activity restricted by AND[rec] (showc false) and one student per status.
     *
     * @return void
     */
    protected function setUp(): void {
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
            'targetcmid' => $this->target->cmid,
        ]);
        $this->set_rec((int) $this->target->cmid, (int) $this->round->id);
        // M1.4a (ADR-016): the round hid its unprotected activity; the tree written by set_rec() has no event, so the guard
        // is told here, as a save of the activity settings would.
        \mod_recuperacao\local\target_guard::sync_cms([(int) $this->target->cmid]);
        $statuses = [
            'none',
            state_machine::STATUS_IDENTIFIED,
            state_machine::STATUS_SENT,
            state_machine::STATUS_APPROVED,
            state_machine::STATUS_CANCELLED,
        ];
        foreach ($statuses as $status) {
            $user = $generator->create_and_enrol($this->course, 'student');
            if ($status !== 'none') {
                $modgenerator->create_case([
                    'recuperacaoid' => (int) $this->round->id,
                    'userid' => (int) $user->id,
                    'status' => $status,
                ]);
            }
            $this->users[$status] = (int) $user->id;
        }
    }

    /**
     * Writes AND[rec(roundid)] with showc=false on a course module (test setup only).
     *
     * @param int $cmid Course module.
     * @param int $roundid Round id stored in the condition.
     * @return void
     */
    private function set_rec(int $cmid, int $roundid): void {
        global $DB;
        $json = tree::get_root_json([condition::get_json($roundid)], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($json), ['id' => $cmid]);
        rebuild_course_cache((int) $DB->get_field('course_modules', 'course', ['id' => $cmid]), true);
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
        get_fast_modinfo(0, 0, true);
        $visible = get_fast_modinfo($courseid, $userid)->get_cm($cmid)->uservisible;
        $this->setUser(null);
        return $visible;
    }

    /**
     * Users of the core availability user list SQL of an activity (the API reports and participants use).
     *
     * @param int $courseid Course.
     * @param int $cmid Course module.
     * @return int[] Sorted user ids.
     */
    private function core_list(int $courseid, int $cmid): array {
        global $DB;
        get_fast_modinfo(0, 0, true);
        $info = new info_module(get_fast_modinfo($courseid)->get_cm($cmid));
        [$sql, $params] = $info->get_user_list_sql(true);
        $this->assertNotSame('', $sql, 'The condition must always filter the list');
        $ids = array_map('intval', $DB->get_fieldset_sql($sql, $params));
        sort($ids);
        return $ids;
    }

    /**
     * Users of the contract list SQL.
     *
     * @param int $roundid Round.
     * @param int $cmid Activity.
     * @return int[] Sorted user ids.
     */
    private function contract_list(int $roundid, int $cmid): array {
        global $DB;
        [$sql, $params] = target_access::user_list_sql($roundid, $cmid);
        $ids = array_map('intval', $DB->get_fieldset_sql($sql, $params));
        sort($ids);
        return $ids;
    }

    /**
     * D3: no status other than exactly "sent" opens the activity, and the list agrees with is_eligible().
     *
     * Exact on every database, trailing spaces included (S-05 fixed by ADR-017a, mod_recuperacao >= 2026101705).
     */
    public function test_status_variants_never_open_and_lists_agree(): void {
        global $DB;
        $modgenerator = $this->getDataGenerator()->get_plugin_generator('mod_recuperacao');
        $variants = ['SENT', 'Sent', 'sEnt', ' sent', 'sent ', "sent\t", "sent\n", 'sent%', 'sen', 'sentt', ''];
        $byvariant = [];
        foreach ($variants as $variant) {
            $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
            $modgenerator->create_case([
                'recuperacaoid' => (int) $this->round->id,
                'userid' => (int) $user->id,
                'status' => state_machine::STATUS_SENT,
            ]);
            $DB->set_field('recuperacao_case', 'status', $variant, [
                'recuperacaoid' => $this->round->id,
                'userid' => $user->id,
            ]);
            $byvariant[$variant] = (int) $user->id;
        }
        $contract = $this->contract_list((int) $this->round->id, (int) $this->target->cmid);
        $core = $this->core_list((int) $this->course->id, (int) $this->target->cmid);

        foreach ($byvariant as $variant => $userid) {
            $label = json_encode($variant);
            $this->assertFalse(
                target_access::is_eligible((int) $this->round->id, $userid, (int) $this->target->cmid),
                "is_eligible must deny $label"
            );
            $this->assertFalse($this->sees((int) $this->course->id, (int) $this->target->cmid, $userid), "visible $label");
            $this->assertNotContains($userid, $contract, "contract list must not contain $label");
            $this->assertNotContains($userid, $core, "core list must not contain $label");
        }
        $this->assertSame([$this->users[state_machine::STATUS_SENT]], $core);
        $this->assertSame($core, $contract);
        $this->assertTrue($this->sees((int) $this->course->id, (int) $this->target->cmid, $this->users['sent']));
    }

    /**
     * Ids -1 and 0 (restore without the round, malformed edits) and a round of another course never open anything,
     * also for a student sent in that round and enrolled in both courses; a round pointing (corruptly) to an
     * activity of another course does not open it either.
     */
    public function test_foreign_and_invalid_round_ids_are_closed(): void {
        global $DB;
        $sent = $this->users[state_machine::STATUS_SENT];
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($sent, (int) $other->id, 'student');
        $foreign = $this->getDataGenerator()->create_module('page', ['course' => $other->id]);

        foreach ([-1, 0, (int) $this->round->id, PHP_INT_MAX] as $roundid) {
            $this->set_rec((int) $foreign->cmid, $roundid);
            $this->assertFalse($this->sees((int) $other->id, (int) $foreign->cmid, $sent), "round $roundid");
            $this->assertSame([], $this->core_list((int) $other->id, (int) $foreign->cmid), "round $roundid");
        }
        foreach ([-1, 0] as $roundid) {
            $this->set_rec((int) $this->target->cmid, $roundid);
            $this->assertFalse($this->sees((int) $this->course->id, (int) $this->target->cmid, $sent), "round $roundid");
            $this->assertSame([], $this->core_list((int) $this->course->id, (int) $this->target->cmid));
        }

        // Corrupt round pointing to the activity of the other course, which carries rec(round).
        $DB->set_field('recuperacao', 'targetcmid', $foreign->cmid, ['id' => $this->round->id]);
        $this->set_rec((int) $foreign->cmid, (int) $this->round->id);
        $this->assertFalse(target_access::is_eligible((int) $this->round->id, $sent, (int) $foreign->cmid));
        $this->assertSame([], $this->contract_list((int) $this->round->id, (int) $foreign->cmid));
        $this->assertFalse($this->sees((int) $other->id, (int) $foreign->cmid, $sent));
    }

    /**
     * Backs up the course and restores it, as the administrator.
     *
     * @param bool $users Whether user data goes with the backup.
     * @param int|null $intocourseid Existing course to add into; null for a new course.
     * @return int Course restored into.
     */
    private function backup_restore_course(bool $users, ?int $intocourseid = null): int {
        global $CFG, $USER;
        $this->setAdminUser();
        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            (int) $this->course->id,
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
            $intocourseid = \restore_dbops::create_new_course('Sec ' . $this->copies, 'SEC' . $this->copies, 1);
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
        // The legacy managed group of the restored round is filled by an ad hoc task.
        ob_start();
        $this->runAdhocTasks();
        ob_end_clean();
        $this->setUser(null);
        return $intocourseid;
    }

    /**
     * Course modules of a course with the target name.
     *
     * @param int $courseid Course.
     * @return int[]
     */
    private function targets_in(int $courseid): array {
        get_fast_modinfo(0, 0, true);
        $ids = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            if ($cm->name === self::TARGET_NAME) {
                $ids[] = (int) $cm->id;
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * Copy with user data in a new course: the preserved "sent" case opens the copy only to that student while
     * actively enrolled in the copy; nobody else (identified, approved, cancelled, without case) and no suspended or
     * unenrolled sent student; the condition points to the round of the copy, never to the original.
     */
    public function test_copy_with_users_opens_only_enrolled_sent(): void {
        global $DB;
        $newcourseid = $this->backup_restore_course(true);
        $newround = $DB->get_record('recuperacao', ['course' => $newcourseid], '*', MUST_EXIST);
        [$newtarget] = $this->targets_in($newcourseid);
        $this->assertSame($newtarget, (int) $newround->targetcmid);
        $tree = json_decode($DB->get_field('course_modules', 'availability', ['id' => $newtarget]));
        $this->assertSame((int) $newround->id, $tree->c[0]->id);
        $this->assertFalse($tree->showc[0]);
        $this->assertFalse(target_access::is_eligible((int) $this->round->id, $this->users['sent'], $newtarget));

        // The enrolments come with the user data; enrol anyway (idempotent) so the check does not depend on it.
        foreach ($this->users as $userid) {
            $this->getDataGenerator()->enrol_user($userid, $newcourseid, 'student');
        }
        foreach ($this->users as $status => $userid) {
            $this->assertSame($status === state_machine::STATUS_SENT, $this->sees($newcourseid, $newtarget, $userid), $status);
        }
        $this->assertSame([$this->users['sent']], $this->core_list($newcourseid, $newtarget));
        // The legacy managed group of the copy (AND sibling of rec) holds only the sent student.
        if ($newround->groupid) {
            $members = array_map('intval', $DB->get_fieldset('groups_members', 'userid', ['groupid' => $newround->groupid]));
            $this->assertSame([$this->users['sent']], $members);
        }

        // Suspended enrolment in the copy closes it again.
        $this->getDataGenerator()->enrol_user($this->users['sent'], $newcourseid, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->assertFalse($this->sees($newcourseid, $newtarget, $this->users['sent']));
        $this->assertSame([], $this->core_list($newcourseid, $newtarget));

        // Unenrolled from the copy: closed as well.
        $manual = enrol_get_plugin('manual');
        foreach (enrol_get_instances($newcourseid, false) as $instance) {
            if ($instance->enrol === 'manual') {
                $manual->unenrol_user($instance, $this->users['sent']);
            }
        }
        $this->assertFalse(is_enrolled(\context_course::instance($newcourseid), $this->users['sent']));
        $this->assertFalse(target_access::is_eligible((int) $newround->id, $this->users['sent'], $newtarget));
        $this->assertSame([], $this->core_list($newcourseid, $newtarget));
        $this->assertTrue($this->sees((int) $this->course->id, (int) $this->target->cmid, $this->users['sent']));
    }

    /**
     * Whole course with users restored into itself (merge): two rounds and two recovery activities in one course.
     * Each activity stays bound to its own round; a round never opens the other's activity.
     */
    public function test_merge_into_same_course_keeps_pairs(): void {
        global $DB;
        $this->backup_restore_course(true, (int) $this->course->id);
        $rounds = $DB->get_records('recuperacao', ['course' => $this->course->id], 'id');
        $this->assertCount(2, $rounds);
        $newround = end($rounds);
        $targets = $this->targets_in((int) $this->course->id);
        $this->assertCount(2, $targets);
        $newtarget = (int) $newround->targetcmid;
        $this->assertContains($newtarget, $targets);
        $this->assertNotSame((int) $this->target->cmid, $newtarget);

        $tree = json_decode($DB->get_field('course_modules', 'availability', ['id' => $newtarget]));
        $this->assertSame((int) $newround->id, $tree->c[0]->id);
        $tree = json_decode($DB->get_field('course_modules', 'availability', ['id' => $this->target->cmid]));
        $this->assertSame((int) $this->round->id, $tree->c[0]->id);

        $sent = $this->users[state_machine::STATUS_SENT];
        $this->assertFalse(target_access::is_eligible((int) $this->round->id, $sent, $newtarget));
        $this->assertFalse(target_access::is_eligible((int) $newround->id, $sent, (int) $this->target->cmid));
        foreach ($targets as $cmid) {
            foreach ($this->users as $status => $userid) {
                $this->assertSame($status === state_machine::STATUS_SENT, $this->sees((int) $this->course->id, $cmid, $userid));
            }
            $this->assertSame([$sent], $this->core_list((int) $this->course->id, $cmid));
        }
    }
}
