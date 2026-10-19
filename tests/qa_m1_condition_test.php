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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Independent QA of M1.1+M1.2: gaps of the acceptance matrix "invisible by list and by URL; refused cases".
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(condition::class)]
#[CoversClass(target_access::class)]
final class qa_m1_condition_test extends \advanced_testcase {
    /** @var string Name of the recovery activity. */
    private const TARGET_NAME = 'QA hidden recovery activity';

    /** @var \mod_recuperacao_generator Plugin generator. */
    private \mod_recuperacao_generator $generator;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Recovery activity. */
    private \stdClass $target;

    /** @var \stdClass Round. */
    private \stdClass $round;

    /** @var \stdClass Manual enrolment instance. */
    private \stdClass $manual;

    /**
     * Course, recovery activity restricted by the round (hidden entirely), and the round.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        target_access::reset_caches();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_recuperacao');
        $this->course = $this->getDataGenerator()->create_course();
        $this->target = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id,
            'name' => self::TARGET_NAME,
        ]);
        $this->round = $this->generator->create_instance([
            'course' => $this->course->id,
            'targetcmid' => $this->target->cmid,
        ]);
        $this->manual = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $this->restrict((object) tree::get_root_json([condition::get_json((int) $this->round->id)], tree::OP_AND, false));
        // M1.CI (ADR-021): the round hid its unprotected activity; the tree written above has no event, so the guard
        // is told here, as a save of the activity settings would.
        \mod_recuperacao\local\target_guard::sync_cms([(int) $this->target->cmid]);
    }

    /**
     * Writes an availability structure on the recovery activity.
     *
     * @param \stdClass|string $structure Structure or raw JSON.
     * @return void
     */
    private function restrict($structure): void {
        global $DB;
        $json = is_string($structure) ? $structure : json_encode($structure);
        $DB->set_field('course_modules', 'availability', $json, ['id' => $this->target->cmid]);
        rebuild_course_cache((int) $this->course->id, true);
        target_access::reset_caches();
    }

    /**
     * A student with a case of the given status.
     *
     * @param string|null $status Case status, null for no case.
     * @param int|null $roundid Round, the one of setUp by default.
     * @return int User id.
     */
    private function student(?string $status, ?int $roundid = null): int {
        $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        if ($status !== null) {
            $this->generator->create_case([
                'recuperacaoid' => $roundid ?? (int) $this->round->id,
                'userid' => $user->id,
                'status' => $status,
            ]);
        }
        return (int) $user->id;
    }

    /**
     * The recovery activity as seen by a user.
     *
     * @param int $userid User id.
     * @return \cm_info
     */
    private function cm(int $userid): \cm_info {
        get_fast_modinfo(0, 0, true);
        target_access::reset_caches();
        return get_fast_modinfo($this->course->id, $userid)->get_cm((int) $this->target->cmid);
    }

    /**
     * Every enrolment state with a sent case: only an active enrolment opens the activity, for one user and in lists.
     */
    public function test_enrolment_states_and_list_coherence(): void {
        global $DB;
        $active = $this->student(state_machine::STATUS_SENT);
        $suspended = $this->student(state_machine::STATUS_SENT);
        $expired = $this->student(state_machine::STATUS_SENT);
        $future = $this->student(state_machine::STATUS_SENT);
        $unenrolled = $this->student(state_machine::STATUS_SENT);
        $usersuspended = $this->student(state_machine::STATUS_SENT);
        $plugin = enrol_get_plugin('manual');
        $plugin->update_user_enrol($this->manual, $suspended, ENROL_USER_SUSPENDED);
        $plugin->update_user_enrol($this->manual, $expired, null, time() - 2 * DAYSECS, time() - DAYSECS);
        $plugin->update_user_enrol($this->manual, $future, null, time() + DAYSECS, 0);
        $plugin->unenrol_user($this->manual, $unenrolled);
        $DB->set_field('user', 'suspended', 1, ['id' => $usersuspended]);

        $others = [];
        foreach (state_machine::STATUSES as $status) {
            if ($status !== state_machine::STATUS_SENT) {
                $others[] = $this->student($status);
            }
        }
        $nocase = $this->student(null);
        $teacher = (int) $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher')->id;

        $students = array_merge([$active, $suspended, $expired, $future, $unenrolled, $usersuspended, $nocase], $others);
        $available = [];
        foreach ($students as $userid) {
            $cm = $this->cm($userid);
            if ($cm->available) {
                $available[] = $userid;
            }
            $this->assertSame($cm->available, $cm->uservisible, "uservisible follows availability for $userid");
            $this->assertSame($cm->available, $cm->is_visible_on_course_page());
        }
        // User suspension is a login matter, not an enrolment one: same answer in both paths is what matters.
        $this->assertContains($active, $available);
        foreach ([$suspended, $expired, $future, $unenrolled, $nocase] as $closed) {
            $this->assertNotContains($closed, $available);
        }
        foreach ($others as $closed) {
            $this->assertNotContains($closed, $available);
        }

        $cond = new condition(condition::get_json((int) $this->round->id));
        $info = new info_module($this->cm($active));
        $checker = new \core_availability\capability_checker($info->get_context());
        $all = array_fill_keys(array_merge($students, [$teacher]), true);
        $this->assertEqualsCanonicalizing($available, array_keys($cond->filter_user_list($all, false, $info, $checker)));
        [$sql, $params] = $cond->get_user_list_sql(false, $info, true);
        $this->assertEqualsCanonicalizing($available, array_map('intval', $DB->get_fieldset_sql($sql, $params)));

        // Disabled enrolment instance: everybody loses access, in both paths.
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['id' => $this->manual->id]);
        \cache_helper::purge_all();
        $this->assertFalse($this->cm($active)->uservisible);
        $this->assertSame([], $cond->filter_user_list($all, false, $info, $checker));
        [$sql, $params] = $cond->get_user_list_sql(false, $info, true);
        $this->assertSame([], $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * Another user: the decision is about the user asked for, not the logged in one.
     */
    public function test_other_user(): void {
        $sent = $this->student(state_machine::STATUS_SENT);
        $identified = $this->student(state_machine::STATUS_IDENTIFIED);
        $information = '';

        $this->setUser($sent);
        $info = new info_module($this->cm($sent));
        $this->assertFalse($info->is_available($information, false, $identified));
        $this->setUser($identified);
        $this->assertTrue($info->is_available($information, false, $sent));
        $this->assertFalse($info->is_available($information, false, $identified));
    }

    /**
     * Teachers who ignore availability restrictions see and open the restricted activity; the condition refuses them.
     */
    public function test_teacher_view_hidden(): void {
        $teacher = (int) $this->getDataGenerator()->create_and_enrol($this->course, 'teacher')->id;
        $cm = $this->cm($teacher);
        $this->assertFalse($cm->available);
        $this->assertTrue($cm->uservisible);
        $this->setUser($teacher);
        require_login($this->course, false, $cm, false, true);

        // Without moodle/course:ignoreavailabilityrestrictions the teacher is refused like a student.
        $roleid = (int) $GLOBALS['DB']->get_field('role', 'id', ['shortname' => 'teacher']);
        $contextid = \context_course::instance($this->course->id)->id;
        assign_capability('moodle/course:ignoreavailabilityrestrictions', CAP_PROHIBIT, $roleid, $contextid, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse($this->cm($teacher)->uservisible);
    }

    /**
     * Opening by URL is refused for every status other than sent.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'no case' => [null],
            'identified' => [state_machine::STATUS_IDENTIFIED],
            'dismissed' => [state_machine::STATUS_DISMISSED],
            'approved' => [state_machine::STATUS_APPROVED],
            'failed' => [state_machine::STATUS_FAILED],
            'cancelled' => [state_machine::STATUS_CANCELLED],
        ];
    }

    /**
     * require_login on the activity throws for every refused status, without leaking the activity name.
     *
     * @param string|null $status Case status.
     */
    #[DataProvider('refused_provider')]
    public function test_require_login_refused(?string $status): void {
        $userid = $this->student($status);
        $this->setUser($userid);
        $cm = $this->cm($userid);
        $this->assertFalse($cm->uservisible);
        $this->assertSame('', (string) $cm->availableinfo);
        try {
            require_login($this->course, false, $cm, false, true);
            $this->fail('Refused student opened the recovery activity');
        } catch (\require_login_exception $e) {
            $this->assertStringNotContainsString(self::TARGET_NAME, $e->getMessage());
        }
    }

    /**
     * Deleting the round closes the activity: asynchronously (deletion in progress) and for good.
     */
    public function test_round_deleted(): void {
        global $DB;
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $sent = $this->student(state_machine::STATUS_SENT);
        $this->assertTrue($this->cm($sent)->uservisible);

        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $this->round->cmid]);
        $this->assertFalse($this->cm($sent)->uservisible);
        $DB->set_field('course_modules', 'deletioninprogress', 0, ['id' => $this->round->cmid]);
        $this->assertTrue($this->cm($sent)->uservisible);

        course_delete_module((int) $this->round->cmid);
        $this->resetDebugging(); // Deprecated in 5.2 (MDL-86856), still the API in 5.1.
        $this->assertFalse($DB->record_exists('recuperacao', ['id' => $this->round->id]));
        $this->assertFalse($this->cm($sent)->uservisible);
        // Since M5.1 (ADR-035 P3, QA-M51-01) the deletion closes the activity by hiding it and removes the condition of
        // the round from it: closed by its visibility, not by a restriction left behind.
        $this->assertSame(0, (int) $this->cm($sent)->visible);
        $json = (string) $DB->get_field('course_modules', 'availability', ['id' => $this->target->cmid]);
        $this->assertStringNotContainsString('"recuperacao"', $json);
    }

    /**
     * Invalid stored structures never open the activity: the tree is refused and core reports it as not available.
     *
     * @return array
     */
    public static function invalid_json_provider(): array {
        return [
            'id as string' => ['{"op":"&","c":[{"type":"recuperacao","id":"%d"}],"showc":[false]}'],
            'id missing' => ['{"op":"&","c":[{"type":"recuperacao"}],"showc":[false]}'],
            'id null' => ['{"op":"&","c":[{"type":"recuperacao","id":null}],"showc":[false]}'],
            'id float' => ['{"op":"&","c":[{"type":"recuperacao","id":%d.5}],"showc":[false]}'],
        ];
    }

    /**
     * A corrupted condition of a sent student does not open the activity.
     *
     * @param string $json Stored JSON, %d is replaced by the round id.
     */
    #[DataProvider('invalid_json_provider')]
    public function test_invalid_json_is_closed(string $json): void {
        $sent = $this->student(state_machine::STATUS_SENT);
        $this->restrict(sprintf($json, (int) $this->round->id));
        $cm = $this->cm($sent);
        $this->assertFalse($cm->uservisible);
        $this->resetDebugging();
    }

    /**
     * Constructor refuses every id that is not an integer.
     */
    public function test_constructor_types(): void {
        foreach ([null, 3.0, true, [3], '7', (object) []] as $id) {
            try {
                new condition((object) ['type' => 'recuperacao', 'id' => $id]);
                $this->fail('Accepted id ' . var_export($id, true));
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('Invalid ->id', $e->getMessage());
            }
        }
        $cond = new condition((object) ['type' => 'recuperacao', 'id' => -1]);
        $sent = $this->student(state_machine::STATUS_SENT);
        $this->assertFalse($cond->is_available(false, new info_module($this->cm($sent)), false, $sent));
    }

    /**
     * Restoring a condition already at -1 stays -1 (no mapping is ever looked up for it).
     */
    public function test_restore_minus_one_stays_closed(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $restoreid = 'availability_recuperacao_qa';
        \restore_controller_dbops::create_restore_temp_tables($restoreid);
        try {
            \restore_dbops::set_backup_ids_record($restoreid, 'recuperacao', -1, 99);
            $logger = new \error_log_logger(\backup::LOG_NONE);
            $cond = new condition(condition::get_json(-1));
            $this->assertTrue($cond->update_after_restore($restoreid, (int) $this->course->id, $logger, 'x'));
            $this->assertEquals(condition::get_json(-1), $cond->save());
        } finally {
            \restore_controller_dbops::drop_restore_temp_tables($restoreid);
        }
    }

    /**
     * A sent case of a user in the round, but a condition combined under AND with a true condition stays coherent,
     * and nested double negation is plain (not a grant by negation).
     */
    public function test_nested_trees(): void {
        $sent = $this->student(state_machine::STATUS_SENT);
        $identified = $this->student(state_machine::STATUS_IDENTIFIED);
        $cond = condition::get_json((int) $this->round->id);
        // Must not (must not (sent)) == sent.
        $inner = tree::get_nested_json([$cond], tree::OP_NOT_AND);
        $this->restrict((object) tree::get_root_json([$inner], tree::OP_NOT_AND, false));
        $this->assertTrue($this->cm($sent)->uservisible);
        $this->assertFalse($this->cm($identified)->uservisible);
        // Must not (sent): nobody.
        $this->restrict((object) tree::get_root_json([$cond], tree::OP_NOT_OR, false));
        $this->assertFalse($this->cm($sent)->uservisible);
        $this->assertFalse($this->cm($identified)->uservisible);
    }
}
