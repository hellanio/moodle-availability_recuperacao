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
 * Restriction by recovery round, server side (M1.2): fail closed on every path (ADR-014).
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(condition::class)]
final class condition_test extends \advanced_testcase {
    /** @var string Name of the round, never shown to students. */
    private const ROUND_NAME = 'Secret round name';

    /** @var string Name of the recovery activity, never shown to students without access. */
    private const TARGET_NAME = 'Secret recovery activity';

    /** @var \mod_recuperacao_generator Plugin generator. */
    private \mod_recuperacao_generator $generator;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Recovery activity. */
    private \stdClass $target;

    /** @var \stdClass Round pointing to the recovery activity. */
    private \stdClass $round;

    /**
     * Course, recovery activity restricted by the round, and the round.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/availability/tests/fixtures/mock_info.php');
        require_once($CFG->dirroot . '/availability/tests/fixtures/mock_info_section.php');
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
            'name' => self::ROUND_NAME,
            'targetcmid' => $this->target->cmid,
        ]);
        $this->restrict((int) $this->target->cmid, (int) $this->round->id);
        // M1.CI (ADR-021): the round hid its unprotected activity; the tree written above has no event, so the guard
        // is told here, as a save of the activity settings would.
        \mod_recuperacao\local\target_guard::sync_cms([(int) $this->target->cmid]);
    }

    /**
     * Replaces the availability of an activity with the round condition only.
     *
     * Test setup only: production code never writes this condition in M1.2.
     *
     * @param int $cmid Activity.
     * @param int $roundid Round of the condition.
     * @param bool $showc Whether the restricted activity is shown greyed out.
     * @param string $op Root operator.
     * @return void
     */
    private function restrict(int $cmid, int $roundid, bool $showc = false, string $op = tree::OP_AND): void {
        global $DB;
        $root = tree::get_root_json([condition::get_json($roundid)], $op, $showc);
        $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $cmid]);
        rebuild_course_cache((int) $this->course->id, true);
    }

    /**
     * An enrolled student with a case of the given status in the round of setUp.
     *
     * @param string|null $status Case status, null for no case.
     * @return int User id.
     */
    private function student(?string $status): int {
        $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        if ($status !== null) {
            $this->generator->create_case([
                'recuperacaoid' => (int) $this->round->id,
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
     * @param int|null $cmid Activity, the recovery activity by default.
     * @return \cm_info
     */
    private function cm(int $userid, ?int $cmid = null): \cm_info {
        get_fast_modinfo(0, 0, true);
        return get_fast_modinfo($this->course->id, $userid)->get_cm($cmid ?? (int) $this->target->cmid);
    }

    /**
     * Invalid structures are refused; a valid one is saved as it came.
     */
    public function test_structure(): void {
        foreach ([(object) [], (object) ['id' => '3'], (object) ['roundid' => 3]] as $structure) {
            try {
                new condition($structure);
                $this->fail('Invalid structure accepted');
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('Invalid ->id', $e->getMessage());
            }
        }
        $cond = new condition((object) ['type' => 'recuperacao', 'id' => 7]);
        $this->assertEquals((object) ['type' => 'recuperacao', 'id' => 7], $cond->save());
        $this->assertSame('{recuperacao:#7}', (string) $cond);
        $this->assertTrue($cond->is_applied_to_user_lists());
        $this->assertFalse($cond->is_available_for_all());
        $this->assertFalse($cond->update_dependency_id('course_modules', 7, 8));
    }

    /**
     * Every case status with the expected access: only sent (ADR-003).
     *
     * @return array
     */
    public static function status_provider(): array {
        return [
            'no case' => [null, false],
            'identified' => [state_machine::STATUS_IDENTIFIED, false],
            'dismissed' => [state_machine::STATUS_DISMISSED, false],
            'sent' => [state_machine::STATUS_SENT, true],
            'approved' => [state_machine::STATUS_APPROVED, false],
            'failed' => [state_machine::STATUS_FAILED, false],
            'cancelled' => [state_machine::STATUS_CANCELLED, false],
        ];
    }

    /**
     * The recovery activity follows the case status; hidden ones are absent from the course page and leak nothing.
     *
     * @param string|null $status Case status, null for no case.
     * @param bool $expected Whether the student may open the activity.
     */
    #[DataProvider('status_provider')]
    public function test_status_matrix(?string $status, bool $expected): void {
        $userid = $this->student($status);
        $cm = $this->cm($userid);
        $this->assertSame($expected, $cm->uservisible);
        $this->assertSame($expected, $cm->available);
        $this->assertSame($expected, $cm->is_visible_on_course_page());
        $this->assertStringNotContainsString(self::ROUND_NAME, (string) $cm->availableinfo);

        $info = new info_module($cm);
        $information = '';
        $this->assertSame($expected, $info->is_available($information, false, $userid));
        $this->assertStringNotContainsString(self::ROUND_NAME, $information);
        $this->assertStringNotContainsString(self::TARGET_NAME, $information);
    }

    /**
     * Opening the activity by its URL: require_login refuses a student not sent and lets a sent one in.
     */
    public function test_direct_access(): void {
        $sent = $this->student(state_machine::STATUS_SENT);
        $identified = $this->student(state_machine::STATUS_IDENTIFIED);

        $this->setUser($sent);
        require_login($this->course, false, $this->cm($sent), false, true);

        $this->setUser($identified);
        try {
            require_login($this->course, false, $this->cm($identified), false, true);
            $this->fail('Student not sent opened the recovery activity');
        } catch (\require_login_exception $e) {
            $this->assertStringNotContainsString(self::ROUND_NAME, $e->getMessage());
        }
        $this->expectException(\moodle_exception::class);
        require_login($this->course, false, $this->cm($identified));
    }

    /**
     * Shown greyed out, the description is generic: no round, activity or student name.
     */
    public function test_description_is_generic(): void {
        $this->restrict((int) $this->target->cmid, (int) $this->round->id, true);
        $userid = $this->student(state_machine::STATUS_IDENTIFIED);
        $cm = $this->cm($userid);
        $this->assertFalse($cm->uservisible);
        $this->assertTrue($cm->is_visible_on_course_page());
        $text = \core_availability\info::format_info($cm->availableinfo, $this->course);
        $this->assertStringContainsString(get_string('requires_recuperacao', 'availability_recuperacao'), $text);
        $this->assertStringNotContainsString(self::ROUND_NAME, $text);
        $this->assertStringNotContainsString((string) $this->round->id, strip_tags($text));
        $user = \core_user::get_user($userid);
        $this->assertStringNotContainsString($user->lastname, $text);
    }

    /**
     * Negation never gives access (ADR-014): neither directly nor through a "must not" tree.
     */
    public function test_not_is_closed(): void {
        $sent = $this->student(state_machine::STATUS_SENT);
        $identified = $this->student(state_machine::STATUS_IDENTIFIED);
        $cond = new condition(condition::get_json((int) $this->round->id));
        $info = new info_module($this->cm($sent));
        $this->assertFalse($cond->is_available(true, $info, false, $sent));
        $this->assertFalse($cond->is_available(true, $info, false, $identified));
        $checker = new \core_availability\capability_checker($info->get_context());
        $this->assertSame([], $cond->filter_user_list([$sent => 1, $identified => 1], true, $info, $checker));

        $this->restrict((int) $this->target->cmid, (int) $this->round->id, true, tree::OP_NOT_AND);
        $this->assertFalse($this->cm($sent)->uservisible);
        $this->assertFalse($this->cm($identified)->uservisible);
    }

    /**
     * Only the round's own recovery activity: the condition on another activity, or on a round of another course,
     * never opens it.
     */
    public function test_other_target_round_course(): void {
        $sent = $this->student(state_machine::STATUS_SENT);
        $other = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->restrict((int) $other->cmid, (int) $this->round->id);
        $this->assertTrue($this->cm($sent)->uservisible);
        $this->assertFalse($this->cm($sent, (int) $other->cmid)->uservisible);

        $course2 = $this->getDataGenerator()->create_course();
        $target2 = $this->getDataGenerator()->create_module('page', ['course' => $course2->id]);
        $round2 = $this->generator->create_instance(['course' => $course2->id, 'targetcmid' => $target2->cmid]);
        $this->getDataGenerator()->enrol_user($sent, $course2->id, 'student');
        $this->generator->create_case(['recuperacaoid' => $round2->id, 'userid' => $sent,
            'status' => state_machine::STATUS_SENT]);
        $this->restrict((int) $this->target->cmid, (int) $round2->id);
        $this->assertFalse($this->cm($sent)->uservisible);

        $this->restrict((int) $this->target->cmid, -1);
        $this->assertFalse($this->cm($sent)->uservisible);
    }

    /**
     * A sent student whose enrolment ended loses access.
     */
    public function test_not_enrolled(): void {
        global $DB;
        $sent = $this->student(state_machine::STATUS_SENT);
        $this->assertTrue($this->cm($sent)->uservisible);
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->unenrol_user($instance, $sent);
        $this->assertFalse($this->cm($sent)->uservisible);
    }

    /**
     * On a section, or on anything other than an activity, the condition is never available.
     */
    public function test_section_is_closed(): void {
        $sent = $this->student(state_machine::STATUS_SENT);
        $cond = new condition(condition::get_json((int) $this->round->id));
        $section = get_fast_modinfo($this->course)->get_section_info(1);
        $this->assertFalse($cond->is_available(false, new \core_availability\info_section($section), false, $sent));
        $this->assertFalse($cond->is_available(false, new \core_availability\mock_info($this->course, $sent), false, $sent));
        [$sql, $params] = $cond->get_user_list_sql(false, new \core_availability\mock_info($this->course, $sent), true);
        $this->assertNotSame('', $sql);
        $this->assertSame([], $GLOBALS['DB']->get_fieldset_sql($sql, $params));
    }

    /**
     * Any error while checking means "not available", never an exception or an open activity.
     */
    public function test_exception_is_closed(): void {
        global $DB;
        $sent = $this->student(state_machine::STATUS_SENT);
        $info = new class ($this->cm($sent)) extends info_module {
            /**
             * Fails like a broken course module.
             *
             * @return \cm_info
             */
            public function get_course_module() {
                throw new \moodle_exception('error');
            }
        };
        $cond = new condition(condition::get_json((int) $this->round->id));
        $this->assertFalse($cond->is_available(false, $info, false, $sent));
        $checker = new \core_availability\capability_checker(\context_course::instance($this->course->id));
        $this->assertSame([], $cond->filter_user_list([$sent => 1], false, $info, $checker));
        [$sql, $params] = $cond->get_user_list_sql(false, $info, true);
        $this->assertNotSame('', $sql);
        $this->assertSame([], $DB->get_fieldset_sql($sql, $params));
    }

    /**
     * User lists keep only sent, actively enrolled students; teachers come back through core's view hidden rule.
     */
    public function test_user_lists(): void {
        global $DB;
        $sent = $this->student(state_machine::STATUS_SENT);
        $identified = $this->student(state_machine::STATUS_IDENTIFIED);
        $approved = $this->student(state_machine::STATUS_APPROVED);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $users = [$sent => 1, $identified => 1, $approved => 1, (int) $teacher->id => 1];

        $cond = new condition(condition::get_json((int) $this->round->id));
        $info = new info_module($this->cm($sent));
        $checker = new \core_availability\capability_checker($info->get_context());
        $this->assertSame([$sent => 1], $cond->filter_user_list($users, false, $info, $checker));
        [$sql, $params] = $cond->get_user_list_sql(false, $info, true);
        $this->assertEquals([$sent], $DB->get_fieldset_sql($sql, $params));

        $this->assertEqualsCanonicalizing([$sent, (int) $teacher->id], array_keys($info->filter_user_list($users)));
        [$sql, $params] = $info->get_user_list_sql(true);
        $ids = array_map('intval', $DB->get_fieldset_sql($sql, $params));
        $this->assertEqualsCanonicalizing([$sent, (int) $teacher->id], $ids);
    }

    /**
     * Restore keeps the condition: remapped to the restored round, or -1 (never available) without it.
     */
    public function test_restore(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $restoreid = 'availability_recuperacao_test';
        \restore_controller_dbops::create_restore_temp_tables($restoreid);
        $logger = new class (\backup::LOG_WARNING) extends \base_logger {
            /** @var string[] Logged messages. */
            public array $messages = [];

            /**
             * Keeps the message.
             *
             * @param string $message Message.
             * @param int $level Level.
             * @param mixed $options Options.
             * @return bool
             */
            protected function action($message, $level, $options = null) {
                $this->messages[] = $message;
                return true;
            }
        };
        try {
            $cond = new condition(condition::get_json((int) $this->round->id));
            $task = $this->createMock(\base_task::class);
            $this->assertTrue($cond->include_after_restore($restoreid, (int) $this->course->id, $logger, 'x', $task));
            $this->assertTrue($cond->update_after_restore($restoreid, (int) $this->course->id, $logger, 'x'));
            $this->assertEquals(condition::get_json(-1), $cond->save());
            $this->assertCount(1, $logger->messages);
            $this->assertStringNotContainsString(self::ROUND_NAME, $logger->messages[0]);

            \restore_dbops::set_backup_ids_record($restoreid, 'recuperacao', (int) $this->round->id, 4242);
            $cond = new condition(condition::get_json((int) $this->round->id));
            $this->assertTrue($cond->update_after_restore($restoreid, (int) $this->course->id, $logger, 'x'));
            $this->assertEquals(condition::get_json(4242), $cond->save());
        } finally {
            \restore_controller_dbops::drop_restore_temp_tables($restoreid);
        }
    }
}
