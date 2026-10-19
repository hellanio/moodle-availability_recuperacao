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
 * Security gate M1.1+M1.2: adversarial negative tests (docs/M1_1_SECURITY.md).
 *
 * Tests named test_exposure_* document a CURRENT exposure found by the gate (they assert today's behaviour so the
 * finding is reproducible); the slice that mitigates it must invert the assertion.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(condition::class)]
#[CoversClass(target_access::class)]
final class sec_m1_condition_test extends \advanced_testcase {
    /** @var string Name of the recovery activity. */
    private const TARGET_NAME = 'SECGATE hidden target';

    /** @var \mod_recuperacao_generator Plugin generator. */
    private \mod_recuperacao_generator $generator;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Recovery activity. */
    private \stdClass $target;

    /** @var \stdClass Round. */
    private \stdClass $round;

    /** @var int[] Status => user id of enrolled students. */
    private array $users = [];

    /** @var \stdClass Group used as sibling condition. */
    private \stdClass $group;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        target_access::reset_caches();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_recuperacao');
        $this->course = $this->getDataGenerator()->create_course();
        $this->target = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $this->course->id, 'name' => self::TARGET_NAME]
        );
        $this->round = $this->generator->create_instance(['course' => $this->course->id,
            'targetcmid' => $this->target->cmid]);
        $this->group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $statuses = [
            'none',
            state_machine::STATUS_IDENTIFIED,
            state_machine::STATUS_SENT,
            state_machine::STATUS_APPROVED,
            state_machine::STATUS_CANCELLED,
        ];
        foreach ($statuses as $status) {
            $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
            if ($status !== 'none') {
                $this->generator->create_case(['recuperacaoid' => (int) $this->round->id,
                    'userid' => $user->id, 'status' => $status]);
            }
            $this->users[$status] = (int) $user->id;
        }
        // The identified student is also in the sibling group (to test OR composition).
        $this->getDataGenerator()->create_group_member(['groupid' => $this->group->id,
            'userid' => $this->users[state_machine::STATUS_IDENTIFIED]]);
        // M1.CI (ADR-021): the round hid its unprotected activity. The tests write their trees without an event, so
        // the guard is told once here about the canonical tree, as a save of the activity settings would.
        $this->set_availability($this->tree('and'));
        \mod_recuperacao\local\target_guard::sync_cms([(int) $this->target->cmid]);
    }

    /**
     * Writes a raw availability JSON on the target (test setup only).
     *
     * @param \stdClass|string|null $json Availability.
     */
    private function set_availability($json): void {
        global $DB;
        $value = $json === null ? null : (is_string($json) ? $json : json_encode($json));
        $DB->set_field('course_modules', 'availability', $value, ['id' => $this->target->cmid]);
        rebuild_course_cache((int) $this->course->id, true);
        target_access::reset_caches();
    }

    /**
     * Target as seen by a user.
     *
     * @param int $userid User.
     * @return \cm_info
     */
    private function cm(int $userid): \cm_info {
        get_fast_modinfo(0, 0, true);
        return get_fast_modinfo($this->course->id, $userid)->get_cm((int) $this->target->cmid);
    }

    /**
     * Rec condition JSON.
     *
     * @return \stdClass
     */
    private function rec(): \stdClass {
        return condition::get_json((int) $this->round->id);
    }

    /**
     * Trees (root JSON) and the set of statuses expected to get access.
     *
     * @return array
     */
    public static function tree_provider(): array {
        return [
            'AND[rec]' => ['and', [state_machine::STATUS_SENT]],
            '!&[rec]' => ['notand', []],
            '!|[rec]' => ['notor', []],
            'AND[!&[rec]]' => ['nested_notand', []],
            'AND[!|[rec]]' => ['nested_notor', []],
            '!&[!&[rec]] (double negation = rec)' => ['double', [state_machine::STATUS_SENT]],
            'AND[rec,rec] (param uniqueness)' => ['andtwice', [state_machine::STATUS_SENT]],
            'OR[rec,group] (composition)' => ['orgroup', [state_machine::STATUS_SENT, state_machine::STATUS_IDENTIFIED]],
            '!&[rec,group] (composition)' => ['notandgroup',
                ['none', state_machine::STATUS_SENT, state_machine::STATUS_APPROVED, state_machine::STATUS_CANCELLED]],
        ];
    }

    /**
     * Builds the tree of a provider key.
     *
     * @param string $kind Provider key.
     * @return \stdClass
     */
    private function tree(string $kind): \stdClass {
        $rec = $this->rec();
        $grp = \availability_group\condition::get_json((int) $this->group->id);
        switch ($kind) {
            case 'and':
                return tree::get_root_json([$rec], tree::OP_AND, false);
            case 'notand':
                return tree::get_root_json([$rec], tree::OP_NOT_AND, false);
            case 'notor':
                return tree::get_root_json([$rec], tree::OP_NOT_OR, false);
            case 'nested_notand':
                return tree::get_root_json([tree::get_nested_json([$rec], tree::OP_NOT_AND)], tree::OP_AND, false);
            case 'nested_notor':
                return tree::get_root_json([tree::get_nested_json([$rec], tree::OP_NOT_OR)], tree::OP_AND, false);
            case 'double':
                return tree::get_root_json([tree::get_nested_json([$rec], tree::OP_NOT_AND)], tree::OP_NOT_AND, false);
            case 'andtwice':
                return tree::get_root_json([$rec, $this->rec()], tree::OP_AND, false);
            case 'orgroup':
                return tree::get_root_json([$rec, $grp], tree::OP_OR, false);
            case 'notandgroup':
                return tree::get_root_json([$rec, $grp], tree::OP_NOT_AND, false);
        }
        throw new \coding_exception('kind');
    }

    /**
     * is_available, filter_user_list and get_user_list_sql agree for every tree, including negated/nested ones;
     * the rec condition never adds a non-sent student by itself.
     *
     * @param string $kind Tree kind.
     * @param string[] $expected Statuses with access.
     */
    #[DataProvider('tree_provider')]
    public function test_tree_coherence(string $kind, array $expected): void {
        global $DB;
        $this->set_availability($this->tree($kind));
        $users = array_fill_keys(array_values($this->users), 1);

        $byavail = [];
        foreach ($this->users as $status => $userid) {
            $cm = $this->cm($userid);
            if ($cm->uservisible) {
                $byavail[] = $status;
            }
            $this->assertStringNotContainsString(self::TARGET_NAME, (string) $cm->availableinfo);
        }
        $this->assertEqualsCanonicalizing($expected, $byavail, 'is_available');

        $info = new info_module($this->cm($this->users[state_machine::STATUS_SENT]));
        $flip = array_flip($this->users);
        $byfilter = array_map(fn($id) => $flip[$id], array_keys($info->filter_user_list($users)));
        $this->assertEqualsCanonicalizing($expected, $byfilter, 'filter_user_list');

        [$sql, $params] = $info->get_user_list_sql(true);
        $this->assertNotSame('', $sql, 'SQL must never be empty (empty = no restriction)');
        $ids = array_intersect(array_map('intval', $DB->get_fieldset_sql($sql, $params)), $this->users);
        $bysql = array_map(fn($id) => $flip[$id], array_values(array_unique($ids)));
        $this->assertEqualsCanonicalizing($expected, $bysql, 'get_user_list_sql');
    }

    /**
     * The same info object evaluated for different users never reuses another user's answer.
     */
    public function test_no_cross_user_reuse(): void {
        $this->set_availability($this->tree('and'));
        $info = new info_module($this->cm($this->users[state_machine::STATUS_SENT]));
        $cond = new condition($this->rec());
        $this->assertTrue($cond->is_available(false, $info, true, $this->users[state_machine::STATUS_SENT]));
        $this->assertFalse($cond->is_available(false, $info, true, $this->users[state_machine::STATUS_IDENTIFIED]));
        $this->assertTrue($cond->is_available(false, $info, true, $this->users[state_machine::STATUS_SENT]));
        $this->assertFalse($cond->is_available(false, $info, true, $this->users['none']));
    }

    /**
     * Revocation in another request (e.g. cron) is effective at the next evaluation: no per-user cache.
     */
    public function test_revocation_is_immediate(): void {
        global $DB;
        $this->set_availability($this->tree('and'));
        $sent = $this->users[state_machine::STATUS_SENT];
        $this->assertTrue($this->cm($sent)->uservisible);
        $DB->set_field(
            'recuperacao_case',
            'status',
            state_machine::STATUS_APPROVED,
            ['recuperacaoid' => $this->round->id, 'userid' => $sent]
        );
        // No reset_caches(), no rebuild_course_cache(): only the per-request modinfo is dropped by cm().
        $this->assertFalse($this->cm($sent)->uservisible);
    }

    /**
     * Malformed condition JSON: the activity stays closed for everybody (core catches the constructor exception).
     */
    public function test_malformed_json_is_closed(): void {
        $sent = $this->users[state_machine::STATUS_SENT];
        $bad = [
            '{"op":"&","c":[{"type":"recuperacao","id":"' . $this->round->id . '"}],"showc":[false]}',
            '{"op":"&","c":[{"type":"recuperacao"}],"showc":[false]}',
            '{"op":"&","c":[{"type":"recuperacao","id":' . $this->round->id . '.0}],"showc":[false]}',
            '{"op":"&","c":[{"type":"recuperacao","id":' . $this->round->id . '}],"showc":[false]',
        ];
        foreach ($bad as $json) {
            $this->set_availability($json);
            $this->assertFalse($this->cm($sent)->uservisible, $json);
            $this->assertFalse($this->cm($this->users['none'])->uservisible, $json);
            $this->resetDebugging();
            // User lists: core does not catch here; it must throw (closed), never list everyone.
            $info = new info_module($this->cm($sent));
            try {
                $listed = $info->filter_user_list([$sent => 1, $this->users['none'] => 1]);
                $this->assertArrayNotHasKey($this->users['none'], $listed, $json);
            } catch (\coding_exception $e) {
                $this->assertTrue(true);
            }
            $this->resetDebugging();
        }
    }

    /**
     * Deleting the round module keeps the target closed when the condition (not the legacy grouping) protects it: since
     * M5.1 by hiding it, the condition of the round removed (ADR-035 P3, QA-M51-01).
     */
    public function test_round_deleted_target_stays_closed(): void {
        global $DB;
        $this->set_availability($this->tree('and'));
        $sent = $this->users[state_machine::STATUS_SENT];
        $this->assertTrue($this->cm($sent)->uservisible);
        $roundcm = get_coursemodule_from_instance('recuperacao', $this->round->id, $this->course->id, false, MUST_EXIST);
        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            \core_courseformat\formatactions::cm($this->course->id)->delete((int) $roundcm->id);
        } else {
            course_delete_module((int) $roundcm->id); // Moodle 5.1.
        }
        target_access::reset_caches();
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $this->target->cmid]));
        $json = (string) $DB->get_field('course_modules', 'availability', ['id' => $this->target->cmid]);
        $this->assertStringNotContainsString('"recuperacao"', $json);
        $this->assertFalse($this->cm($sent)->uservisible);
        $this->assertFalse($this->cm($this->users['none'])->uservisible);
    }

    /**
     * S-02 (= SEC-F3), fixed by the M1.5 guard: the core still drops the condition silently when
     * availability_recuperacao is disabled or availability is off, but the guard of mod_recuperacao hides the target:
     * at once for the plugin disabled (config_log_created), at the next run of its task for set_config() without event
     * (F7, documented window). Updated by M1.5 from the original exposure test, which asserted the target opened.
     */
    public function test_exposure_plugin_disabled_closed_by_guard(): void {
        // The guard observer is external: it runs outside a transaction, as after a real request commits.
        $this->preventResetByRollback();
        $this->set_availability($this->tree('and'));
        $none = $this->users['none'];
        $this->assertFalse($this->cm($none)->uservisible);

        \core\plugininfo\availability::enable_plugin('recuperacao', 0);
        \core_plugin_manager::reset_caches();
        rebuild_course_cache((int) $this->course->id, true);
        $this->assertFalse($this->cm($none)->uservisible, 'S-02: the guard hides the target of a disabled condition');

        \core\plugininfo\availability::enable_plugin('recuperacao', 1);
        \core_plugin_manager::reset_caches();
        rebuild_course_cache((int) $this->course->id, true);
        $this->assertFalse($this->cm($none)->uservisible);

        set_config('enableavailability', 0);
        rebuild_course_cache((int) $this->course->id, true);
        $this->assertTrue($this->cm($none)->uservisible, 'F7: set_config() without event stays open until the task');
        ob_start();
        (new \mod_recuperacao\task\guard_targets())->execute();
        ob_end_clean();
        $this->assertFalse($this->cm($none)->uservisible, 'S-02: the guard task hides the target');
    }

    /**
     * EXPOSURE S-01 (M1.4/M1.5 guard): the condition only restricts on an AND path; an OR sibling, or a "!&" with a
     * sibling false for the user, opens the target to non-sent students. Also showc=true shows the target name.
     */
    public function test_exposure_composition_and_showc(): void {
        $none = $this->users['none'];
        $past = time() - DAYSECS;
        $future = time() + DAYSECS;
        $date = '\availability_date\condition';

        $this->set_availability(tree::get_root_json([$this->rec(), $date::get_json('>=', $past)], tree::OP_OR, false));
        $this->assertTrue($this->cm($none)->uservisible, 'S-01: OR[rec, date past] opens to everyone');

        $this->set_availability(
            tree::get_root_json([$this->rec(), $date::get_json('>=', $future)], tree::OP_NOT_AND, false)
        );
        $this->assertTrue($this->cm($none)->uservisible, 'S-01: !&[rec, date future] opens to everyone');

        // Root AND with showc=true: closed, but the target name is on the course page (P0-1 requires showc=false).
        $this->set_availability(tree::get_root_json([$this->rec()], tree::OP_AND, true));
        $cm = $this->cm($none);
        $this->assertFalse($cm->uservisible);
        $this->assertTrue($cm->is_visible_on_course_page(), 'S-01: showc=true lists the target');
        $this->assertSame(self::TARGET_NAME, $cm->name);
    }

    /**
     * S-03 (fixed in M1.3): target_access caches nothing, so a target change in the same process (long cron runs
     * too) closes the OLD target and opens the new one at once, without reset_caches().
     */
    public function test_round_target_change_applies_in_process(): void {
        global $DB;
        $this->set_availability($this->tree('and'));
        $sent = $this->users[state_machine::STATUS_SENT];
        $other = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->assertTrue(target_access::is_eligible((int) $this->round->id, $sent, (int) $this->target->cmid));
        $this->assertTrue($this->cm($sent)->uservisible);
        $DB->set_field('recuperacao', 'targetcmid', $other->cmid, ['id' => $this->round->id]);
        $oldtarget = target_access::is_eligible((int) $this->round->id, $sent, (int) $this->target->cmid);
        $this->assertFalse($oldtarget, 'S-03: old target closed at once');
        $this->assertTrue(target_access::is_eligible((int) $this->round->id, $sent, (int) $other->cmid));
        $this->assertFalse($this->cm($sent)->uservisible);
    }

    /**
     * Description and debug string never carry names, for showc true and negated conditions.
     */
    public function test_descriptions_have_no_names(): void {
        $cond = new condition($this->rec());
        $info = new info_module($this->cm($this->users['none']));
        foreach ([false, true] as $full) {
            foreach ([false, true] as $not) {
                $text = $cond->get_description($full, $not, $info) . $cond->get_standalone_description($full, $not, $info);
                $this->assertStringNotContainsString(self::TARGET_NAME, $text);
                $this->assertStringNotContainsString((string) $this->round->id, $text);
            }
        }
        $this->set_availability(tree::get_root_json([$this->rec()], tree::OP_AND, true));
        $full = (new info_module($this->cm($this->users['none'])))->get_full_information();
        $this->assertStringNotContainsString('SECGATE', \core_availability\info::format_info($full, $this->course));
    }
}
