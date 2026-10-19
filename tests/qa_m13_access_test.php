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
use mod_recuperacao\local\case_manager;
use mod_recuperacao\local\round_manager;
use mod_recuperacao\local\state_machine;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Independent QA of D2 (no stale round in the request, through the real APIs) and D3 (status compared with exact
 * case in is_available and in every user list) and the query cost of a course page (M1.3).
 *
 * No call to target_access::reset_caches() anywhere in this file.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(condition::class)]
#[CoversClass(target_access::class)]
final class qa_m13_access_test extends \advanced_testcase {
    /** @var \mod_recuperacao_generator Plugin generator. */
    private \mod_recuperacao_generator $generator;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Recovery activity. */
    private \stdClass $target;

    /** @var \stdClass Round. */
    private \stdClass $round;

    /**
     * Course, recovery activity restricted by the round only, round.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_recuperacao');
        $this->course = $this->getDataGenerator()->create_course();
        $this->target = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->round = $this->generator->create_instance([
            'course' => $this->course->id,
            'targetcmid' => $this->target->cmid,
        ]);
        $this->restrict((int) $this->target->cmid, (int) $this->round->id);
        // M1.CI (ADR-021): the round hid its unprotected activity; the tree written above has no event, so the guard
        // is told here, as a save of the activity settings would.
        \mod_recuperacao\local\target_guard::sync_cms([(int) $this->target->cmid]);
    }

    /**
     * Puts the round condition alone (AND, hidden entirely) on an activity.
     *
     * @param int $cmid Course module.
     * @param int $roundid Round.
     * @return void
     */
    private function restrict(int $cmid, int $roundid): void {
        global $DB;
        $root = tree::get_root_json([condition::get_json($roundid)], tree::OP_AND, false);
        $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $cmid]);
        rebuild_course_cache((int) $this->course->id, true);
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
     * Enrolled student with a case.
     *
     * @param string $status Case status.
     * @return array{0: int, 1: \mod_recuperacao\persistent\recovery_case}
     */
    private function student(string $status): array {
        $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $case = $this->generator->create_case([
            'recuperacaoid' => $this->round->id,
            'userid' => $user->id,
            'status' => $status,
        ]);
        return [(int) $user->id, $case];
    }

    /**
     * Availability of an activity for a user straight from the tree (no modinfo user cache).
     *
     * @param int $cmid Course module.
     * @param int $userid User.
     * @return bool
     */
    private function available(int $cmid, int $userid): bool {
        $info = new info_module(get_fast_modinfo($this->course->id)->get_cm($cmid));
        $information = '';
        return $info->is_available($information, false, $userid);
    }

    /**
     * Users of the course kept by the core user list filter and by the core user list SQL of an activity.
     *
     * @param int $cmid Course module.
     * @return array{0: int[], 1: int[]}
     */
    private function lists(int $cmid): array {
        global $DB;
        $info = new info_module(get_fast_modinfo($this->course->id)->get_cm($cmid));
        $enrolled = get_enrolled_users(\context_course::instance($this->course->id));
        $filtered = array_map('intval', array_keys($info->filter_user_list($enrolled)));
        [$sql, $params] = $info->get_user_list_sql(true);
        $fromsql = array_map('intval', $DB->get_fieldset_sql($sql, $params));
        sort($filtered);
        sort($fromsql);
        return [$filtered, $fromsql];
    }

    /**
     * D2 through the round manager: the recovery activity changed by update() closes the old one at once.
     */
    public function test_target_change_through_round_manager(): void {
        [$sent] = $this->student(state_machine::STATUS_SENT);
        $this->assertTrue($this->available((int) $this->target->cmid, $sent));
        $this->assertSame([[$sent], [$sent]], $this->lists((int) $this->target->cmid));

        $other = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->restrict((int) $other->cmid, (int) $this->round->id);
        $this->setAdminUser();
        (new round_manager())->update(
            (object) ['instance' => $this->round->id, 'targetcmid' => $other->cmid],
            (int) get_admin()->id
        );
        $this->setUser(null);

        // Since M5.1 (ADR-035 P3, QA-M51-01) the old activity is closed by hiding it, its condition of the round
        // removed: no student reaches it, by its visibility.
        $this->assert_hidden_without_condition((int) $this->target->cmid, $sent);
        // The new activity also gets the legacy managed group restriction (attach_access), so only the contract is
        // checked for it here.
        $this->assertTrue(target_access::is_eligible((int) $this->round->id, $sent, (int) $other->cmid));
    }

    /**
     * D2 through the state machine: a sent case resolved or cancelled closes the activity in the same request, and a
     * reopened one opens it again.
     */
    public function test_case_transitions_apply_in_request(): void {
        [$approved, $case1] = $this->student(state_machine::STATUS_SENT);
        [$cancelled, $case2] = $this->student(state_machine::STATUS_SENT);
        $cmid = (int) $this->target->cmid;
        $this->assertTrue($this->available($cmid, $approved));
        $this->assertTrue($this->available($cmid, $cancelled));

        $manager = new case_manager();
        $manager->transition([$case1], state_machine::STATUS_APPROVED, 0);
        $manager->transition([$case2], state_machine::STATUS_CANCELLED, 0);
        $this->assertFalse($this->available($cmid, $approved));
        $this->assertFalse($this->available($cmid, $cancelled));
        $this->assertSame([[], []], $this->lists($cmid));

        $manager->transition([$case1], state_machine::STATUS_SENT, 0);
        $this->assertTrue($this->available($cmid, $approved));
        $this->assertSame([[$approved], [$approved]], $this->lists($cmid));
    }

    /**
     * D2 through the course API: the round deleted (async, then for good) closes the activity in the same request.
     */
    public function test_round_deleted_through_course_api(): void {
        global $DB;
        [$sent] = $this->student(state_machine::STATUS_SENT);
        $cmid = (int) $this->target->cmid;
        $this->assertTrue($this->available($cmid, $sent));
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $this->setAdminUser();
        $this->delete_cm((int) $this->course->id, (int) $this->round->cmid, true);
        $this->setUser(null);
        $this->assertSame(1, (int) $DB->get_field('course_modules', 'deletioninprogress', ['id' => $this->round->cmid]));
        $this->assertFalse($this->available($cmid, $sent));
        $this->assertSame([[], []], $this->lists($cmid));
        $this->setAdminUser();
        $this->runAdhocTasks('\core_course\task\course_delete_modules');
        $this->setUser(null);
        // Deleted for good: closed by hiding, the condition of the round removed (M5.1, ADR-035 P3, QA-M51-01).
        $this->assert_hidden_without_condition($cmid, $sent);
    }

    /**
     * An activity closed by its round (M5.1, ADR-035 P3, QA-M51-01): hidden, without the recuperacao condition, and
     * the student sent does not reach it.
     *
     * @param int $cmid Course module.
     * @param int $sent Student sent.
     * @return void
     */
    private function assert_hidden_without_condition(int $cmid, int $sent): void {
        global $DB;
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $cmid]));
        $json = (string) $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
        $this->assertStringNotContainsString('"recuperacao"', $json);
        get_fast_modinfo(0, 0, true);
        $this->assertFalse(get_fast_modinfo($this->course->id, $sent)->get_cm($cmid)->uservisible);
    }

    /**
     * D3: only the exact value "sent" opens, in is_available, in filter_user_list and in get_user_list_sql.
     */
    public function test_status_exact_everywhere(): void {
        global $DB;
        [$sent] = $this->student(state_machine::STATUS_SENT);
        $variants = ['SENT', 'Sent', 'sEnt', ' sent', 'sent ', "sent\t", 'sеnt'];
        $others = [];
        foreach ($variants as $variant) {
            [$userid] = $this->student(state_machine::STATUS_SENT);
            $DB->set_field('recuperacao_case', 'status', $variant, ['recuperacaoid' => $this->round->id, 'userid' => $userid]);
            $others[$variant] = $userid;
        }
        $cmid = (int) $this->target->cmid;
        $this->assertTrue($this->available($cmid, $sent));
        foreach ($others as $variant => $userid) {
            $this->assertFalse($this->available($cmid, $userid), "is_available with status '$variant'");
            $this->assertFalse(get_fast_modinfo($this->course->id, $userid)->get_cm($cmid)->uservisible, $variant);
        }
        $this->assertSame([[$sent], [$sent]], $this->lists($cmid));
    }

    /**
     * Query cost of a course page for a student: N activities each restricted by a round (one round per activity),
     * measured on the modinfo of the student with uservisible read for every activity. Prints the counts, also
     * for a core condition (availability_date, always true) and for no restriction.
     */
    public function test_course_page_query_count(): void {
        global $DB;
        $counts = [];
        foreach ([1, 30] as $n) {
            $course = $this->getDataGenerator()->create_course();
            $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
            $targets = [];
            for ($i = 0; $i < $n; $i++) {
                $target = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
                $targets[] = (int) $target->cmid;
                $round = $this->generator->create_instance(['course' => $course->id, 'targetcmid' => $target->cmid]);
                $this->generator->create_case([
                    'recuperacaoid' => $round->id,
                    'userid' => $user->id,
                    'status' => $i % 2 ? state_machine::STATUS_SENT : state_machine::STATUS_IDENTIFIED,
                ]);
                $root = tree::get_root_json([condition::get_json((int) $round->id)], tree::OP_AND, false);
                $DB->set_field('course_modules', 'availability', json_encode($root), ['id' => $target->cmid]);
            }
            // M1.CI (ADR-021): the trees above have no event; the guard is told, as a save of the settings would.
            \mod_recuperacao\local\target_guard::sync_cms($targets);
            rebuild_course_cache((int) $course->id, true);
            // First read after the course cache rebuild ("with", contexts cold), then the same page again ("warm").
            foreach (['with' => true, 'warm' => true, 'coredate' => false, 'without' => false] as $label => $restricted) {
                if (!$restricted) {
                    // Baseline: a core condition always true (date in the past), then no restriction at all.
                    $json = $label === 'coredate' ? json_encode(tree::get_root_json(
                        [\availability_date\condition::get_json('>=', 0)],
                        tree::OP_AND,
                        false
                    )) : null;
                    $DB->set_field('course_modules', 'availability', $json, ['course' => $course->id, 'module' =>
                        $DB->get_field('modules', 'id', ['name' => 'page'])]);
                    rebuild_course_cache((int) $course->id, true);
                }
                $this->setUser($user);
                get_fast_modinfo(0, 0, true);
                get_fast_modinfo($course->id); // Course cache warm, as on a normal page.
                get_fast_modinfo(0, 0, true);
                $before = $DB->perf_get_queries();
                $visible = 0;
                foreach (get_fast_modinfo($course->id)->get_cms() as $cm) {
                    if ($cm->modname === 'page' && $cm->uservisible) {
                        $visible++;
                    }
                }
                $counts["N=$n $label"] = $DB->perf_get_queries() - $before;
                $this->setUser(null);
                $this->assertSame($restricted ? intdiv($n, 2) : $n, $visible);
            }
        }
        fwrite(STDERR, "\nQA-M13-QUERIES " . json_encode($counts) . "\n");
        // Warm: one indexed query per restricted activity (target_access::is_eligible) beyond the page without
        // restrictions, never several queries per activity.
        $per = ($counts['N=30 warm'] - $counts['N=30 without'] - ($counts['N=1 warm'] - $counts['N=1 without'])) / 29;
        $this->assertLessThanOrEqual(1.1, $per);
    }
}
