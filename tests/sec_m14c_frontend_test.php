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
use mod_recuperacao\local\access\target_access;
use mod_recuperacao\local\state_machine;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Security gate M1.4c (docs/M1_4C_SECURITY.md, focus 1): the editor of the restriction.
 *
 * Round names reach only the page that edits the recovery activity (no other activity, section, course or round of
 * another course, no round being deleted); names with markup are escaped/cleaned before the YUI module puts them in
 * HTML; texts shown to students and teachers never carry a round or activity name; a forged round id in a saved tree
 * shows no name.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(frontend::class)]
#[CoversClass(target_access::class)]
final class sec_m14c_frontend_test extends \advanced_testcase {
    /** @var string[] Hostile round names (stored by a direct write: the form would strip tags). */
    private const PAYLOADS = [
        '<script>alert("r1")</script>Round',
        '<img src=x onerror=alert(2)>',
        '"><svg onload=alert(3)>',
        '</option></select><iframe src="javascript:alert(4)"></iframe>',
        '<a href="javascript:alert(5)">x</a>',
        '{{#str}}x{{/str}} ${alert(6)} <script>',
    ];

    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass */
    private \stdClass $target;

    /** @var \mod_recuperacao_generator */
    private \mod_recuperacao_generator $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_recuperacao');
        $this->course = $this->getDataGenerator()->create_course();
        $this->target = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id,
            'name' => 'SEC14C target']);
    }

    /**
     * Calls a protected method of a new frontend.
     *
     * @param string $method Method.
     * @param array $args Arguments.
     * @return mixed
     */
    private function call(string $method, array $args): mixed {
        $frontend = new frontend();
        return (new \ReflectionMethod($frontend, $method))->invokeArgs($frontend, $args);
    }

    /**
     * The course module as the editor gets it.
     *
     * @param int $cmid Course module.
     * @return \cm_info
     */
    private function cminfo(int $cmid): \cm_info {
        get_fast_modinfo(0, 0, true);
        return get_fast_modinfo($this->course->id)->get_cm($cmid);
    }

    /**
     * JavaScript the editor page of a course module (or section) gets.
     *
     * @param \cm_info|null $cm Course module.
     * @param \section_info|null $section Section.
     * @return string
     */
    private function page_js(?\cm_info $cm, ?\section_info $section = null): string {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_url('/course/modedit.php');
        $PAGE->set_context($cm ? \context_module::instance((int) $cm->id) : \context_course::instance($this->course->id));
        \core_availability\frontend::include_all_javascript($this->course, $cm, $section);
        return $PAGE->requires->get_end_code();
    }

    /**
     * Asserts that an HTML fragment carries no active markup.
     *
     * @param string $html Fragment.
     * @param string $label Label.
     * @return void
     */
    private function assert_inert(string $html, string $label): void {
        $active = '~<\s*(script|svg|iframe|object|embed|style|form|select|option)\b~i';
        $this->assertDoesNotMatchRegularExpression($active, $html, $label);
        $this->assertDoesNotMatchRegularExpression('~<[^>]*\son[a-z]+\s*=~i', $html, "$label: event handler");
        $this->assertDoesNotMatchRegularExpression('~<[^>]*=\s*["\']?\s*javascript:~i', $html, "$label: javascript URL");
        $this->assertStringNotContainsString('</option>', $html, $label);
        $this->assertStringNotContainsString('"><', $html, "$label: attribute break");
    }

    /**
     * Hostile round names, with tags stripped (default) or cleaned: inert in the parameters given to the YUI module.
     */
    public function test_hostile_round_names_are_inert(): void {
        global $DB;
        $ids = [];
        foreach (self::PAYLOADS as $i => $payload) {
            $round = $this->generator->create_instance(['course' => $this->course->id, 'name' => 'R' . $i,
                'targetcmid' => $this->target->cmid]);
            $DB->set_field('recuperacao', 'name', $payload, ['id' => $round->id]);
            $ids[] = (int) $round->id;
        }
        foreach ([1, 0] as $striptags) {
            set_config('formatstringstriptags', $striptags);
            $params = $this->call('get_javascript_init_params', [$this->course, $this->cminfo((int) $this->target->cmid), null]);
            $this->assertSame($ids, array_map(fn($r) => $r->id, $params[0]));
            foreach ($params[0] as $round) {
                $this->assertIsInt($round->id, 'id is an integer, put in attributes as is');
                $this->assert_inert($round->name, "striptags=$striptags #{$round->id}");
            }
        }
        // One round: the name goes into a <strong>; the same escaping applies.
        $DB->delete_records_select('recuperacao', 'id <> :id AND targetcmid = :cmid', ['id' => $ids[1],
            'cmid' => $this->target->cmid]);
        $params = $this->call('get_javascript_init_params', [$this->course, $this->cminfo((int) $this->target->cmid), null]);
        $this->assertCount(1, $params[0]);
        $this->assert_inert($params[0][0]->name, 'single round');
    }

    /**
     * Round names reach only the editor of the recovery activity; never another activity, a section, a round of
     * another course pointing here (direct write), nor a round being deleted.
     */
    public function test_round_names_only_in_editor_of_the_target(): void {
        global $DB;
        $mine = $this->generator->create_instance(['course' => $this->course->id, 'name' => 'NAME-MINE',
            'targetcmid' => $this->target->cmid]);
        $deleting = $this->generator->create_instance(['course' => $this->course->id, 'name' => 'NAME-DELETING',
            'targetcmid' => $this->target->cmid]);
        $deletingcm = get_coursemodule_from_instance('recuperacao', (int) $deleting->id, (int) $this->course->id);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $deletingcm->id]);
        $course2 = $this->getDataGenerator()->create_course();
        $foreign = $this->generator->create_instance(['course' => $course2->id, 'name' => 'NAME-FOREIGN']);
        $DB->set_field('recuperacao', 'targetcmid', $this->target->cmid, ['id' => $foreign->id]);
        $other = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->generator->create_instance(['course' => $this->course->id, 'name' => 'NAME-OTHER',
            'targetcmid' => $other->cmid]);
        rebuild_course_cache((int) $this->course->id, true);

        $js = $this->page_js($this->cminfo((int) $this->target->cmid));
        $this->assertStringContainsString('NAME-MINE', $js);
        foreach (['NAME-DELETING', 'NAME-FOREIGN', 'NAME-OTHER'] as $name) {
            $this->assertStringNotContainsString($name, $js, $name);
        }
        $this->assertSame([(int) $mine->id], array_keys(target_access::rounds_for_target((int) $this->target->cmid)));

        $free = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $section = get_fast_modinfo($this->course->id)->get_section_info(0);
        foreach (
            ['free activity' => $this->page_js($this->cminfo((int) $free->cmid)),
                'section' => $this->page_js(null, $section),
                'new activity' => $this->page_js(null)] as $label => $page
        ) {
            $this->assertStringNotContainsString('NAME-', $page, $label);
            $this->assertStringContainsString('"availability_recuperacao",false', $page, "$label: not offered");
        }
        // Contract input that is not a course module id.
        $this->assertSame([], target_access::rounds_for_target(0));
        $this->assertSame([], target_access::rounds_for_target(-1));
    }

    /**
     * Texts of the condition (course page, editor list, student view) never carry a round or activity name, for the
     * student without access, the sent student nor the teacher; a saved tree with a forged round id names nothing.
     */
    public function test_descriptions_carry_no_name(): void {
        global $DB;
        $round = $this->generator->create_instance(['course' => $this->course->id, 'name' => 'NAME-ROUND-SECRET',
            'targetcmid' => $this->target->cmid]);
        $course2 = $this->getDataGenerator()->create_course();
        $foreign = $this->generator->create_instance(['course' => $course2->id, 'name' => 'NAME-FOREIGN-SECRET']);
        $sent = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $none = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->generator->create_case(['recuperacaoid' => (int) $round->id, 'userid' => $sent->id,
            'status' => state_machine::STATUS_SENT]);
        foreach ([(int) $round->id, (int) $foreign->id, 999999] as $id) {
            $DB->set_field(
                'course_modules',
                'availability',
                '{"op":"&","c":[{"type":"recuperacao","id":' . $id
                . '},{"type":"date","d":"<","t":' . (time() + YEARSECS) . '}],"showc":[false,true]}',
                ['id' => $this->target->cmid]
            );
            $DB->set_field('course_modules', 'visible', 1, ['id' => $this->target->cmid]);
            rebuild_course_cache((int) $this->course->id, true);
            foreach ([$sent, $none, $teacher] as $user) {
                $this->setUser($user);
                get_fast_modinfo(0, 0, true);
                $cm = get_fast_modinfo($this->course->id, (int) $user->id)->get_cm((int) $this->target->cmid);
                $info = new info_module($cm);
                $texts = [
                    \core_availability\info::format_info($cm->availableinfo, $this->course),
                    \core_availability\info::format_info($info->get_full_information(), $this->course),
                ];
                $text = '';
                $info->is_available($text, false, (int) $user->id);
                $texts[] = \core_availability\info::format_info($text, $this->course);
                foreach ($texts as $t) {
                    $this->assertStringNotContainsString('NAME-ROUND-SECRET', $t);
                    $this->assertStringNotContainsString('NAME-FOREIGN-SECRET', $t);
                }
                if ($id !== (int) $round->id && $user !== $teacher) {
                    $this->assertFalse($cm->uservisible, "forged id $id: nobody gets in");
                }
            }
            $this->setUser(null);
        }
        // The editor of the activity lists only its own round; the forged id is shown as "#id" by the YUI module.
        $js = $this->page_js($this->cminfo((int) $this->target->cmid));
        $this->assertStringNotContainsString('NAME-FOREIGN-SECRET', $js);
    }
}
