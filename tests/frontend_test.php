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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Editor of the restriction (M1.4c, U11 of docs/M1_4_DESIGN.md §5): offered only in the recovery activity of a round,
 * with only the rounds that use it, and the strings and build the YUI module needs.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(frontend::class)]
final class frontend_test extends \advanced_testcase {
    /** @var \mod_recuperacao_generator Plugin generator. */
    private \mod_recuperacao_generator $generator;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Recovery activity of the round. */
    private \stdClass $target;

    /** @var \stdClass Round. */
    private \stdClass $round;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_recuperacao');
        $this->course = $this->getDataGenerator()->create_course();
        $this->target = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->round = $this->generator->create_instance(['course' => $this->course->id, 'name' => 'Round A',
            'targetcmid' => $this->target->cmid]);
    }

    /**
     * Calls a protected method of a new frontend.
     *
     * @param string $method Method name.
     * @param array $args Arguments.
     * @return mixed
     */
    private function call(string $method, array $args = []): mixed {
        $frontend = new frontend();
        $reflection = new \ReflectionMethod($frontend, $method);
        return $reflection->invokeArgs($frontend, $args);
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
     * Offered in the recovery activity of a round; never in a section, an activity being created or an activity no
     * round of the course uses.
     */
    public function test_allow_add_only_in_the_recovery_activity(): void {
        $cm = $this->cminfo((int) $this->target->cmid);
        $this->assertTrue($this->call('allow_add', [$this->course, $cm, null]));

        $section = get_fast_modinfo($this->course->id)->get_section_info(1);
        $this->assertFalse($this->call('allow_add', [$this->course, null, $section]), 'section');
        $this->assertFalse($this->call('allow_add', [$this->course, null, null]), 'activity being created');

        $other = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->assertFalse($this->call('allow_add', [$this->course, $this->cminfo((int) $other->cmid), null]), 'not a target');

        $params = $this->call('get_javascript_init_params', [$this->course, $this->cminfo((int) $other->cmid), null]);
        $this->assertSame([[]], $params, 'no round offered in another activity');
        $this->assertSame([[]], $this->call('get_javascript_init_params', [$this->course, null, $section]));
        $this->assertSame([[]], $this->call('get_javascript_init_params', [$this->course, null, null]));
    }

    /**
     * A round being deleted, or one of another course pointing to the activity (only by a direct write), offers
     * nothing.
     */
    public function test_allow_add_ignores_rounds_being_deleted_and_of_other_courses(): void {
        global $DB;
        $roundcm = get_coursemodule_from_instance(
            'recuperacao',
            (int) $this->round->id,
            (int) $this->course->id,
            false,
            MUST_EXIST
        );
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $roundcm->id]);
        $this->assertFalse($this->call('allow_add', [$this->course, $this->cminfo((int) $this->target->cmid), null]));
        $DB->set_field('course_modules', 'deletioninprogress', 0, ['id' => $roundcm->id]);

        $course2 = $this->getDataGenerator()->create_course();
        $foreign = $this->generator->create_instance(['course' => $course2->id]);
        $page2 = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->set_field('recuperacao', 'targetcmid', $page2->cmid, ['id' => $foreign->id]);
        $this->assertFalse($this->call('allow_add', [$this->course, $this->cminfo((int) $page2->cmid), null]));
    }

    /**
     * The editor gets only the rounds using this activity, by id, with names formatted for the course.
     */
    public function test_init_params_only_rounds_of_the_activity_formatted(): void {
        $second = $this->generator->create_instance(['course' => $this->course->id,
            'name' => 'Round <b>B</b> & <script>alert(1)</script>', 'targetcmid' => $this->target->cmid]);
        $other = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->generator->create_instance(['course' => $this->course->id, 'name' => 'Round of another activity',
            'targetcmid' => $other->cmid]);

        $params = $this->call('get_javascript_init_params', [$this->course, $this->cminfo((int) $this->target->cmid), null]);
        $this->assertCount(1, $params);
        $rounds = $params[0];
        $this->assertSame([(int) $this->round->id, (int) $second->id], array_map(fn($r) => $r->id, $rounds));
        $context = \context_course::instance($this->course->id);
        $this->assertSame(format_string('Round A', true, ['context' => $context]), $rounds[0]->name);
        $this->assertSame(
            format_string('Round <b>B</b> & <script>alert(1)</script>', true, ['context' => $context]),
            $rounds[1]->name
        );
        $this->assertStringNotContainsString('<script', $rounds[1]->name);
        $this->assertStringNotContainsString('Round of another activity', json_encode($params));
    }

    /**
     * Every string the YUI module asks for exists in English and Brazilian Portuguese (en is mandatory).
     */
    public function test_javascript_strings_exist(): void {
        $identifiers = $this->call('get_javascript_strings');
        $this->assertNotEmpty($identifiers);
        $sm = get_string_manager();
        foreach (array_merge($identifiers, ['title', 'description']) as $identifier) {
            $this->assertTrue($sm->string_exists($identifier, 'availability_recuperacao'), $identifier);
            $en = $sm->get_string($identifier, 'availability_recuperacao', null, 'en');
            $this->assertNotSame('', trim($en), $identifier);
        }
        // The files themselves: the string manager would fill pt_br from en.
        $read = function (string $lang): array {
            global $CFG;
            $string = [];
            include($CFG->dirroot . '/availability/condition/recuperacao/lang/' . $lang . '/availability_recuperacao.php');
            return $string;
        };
        $en = $read('en');
        $pt = $read('pt_br');
        $this->assertSame([], array_diff_key($en, $pt), 'pt_br covers every English string');
        $this->assertSame([], array_diff_key($pt, $en), 'no pt_br string without English');
        foreach ($identifiers as $identifier) {
            $this->assertNotSame($en[$identifier], $pt[$identifier], "$identifier is translated");
        }
    }

    /**
     * Every error the YUI module may submit is turned by the core into a form error, without a missing string.
     */
    public function test_submitted_errors_are_reported_by_the_core(): void {
        $errors = [];
        $json = json_encode([
            'op' => '!&',
            'c' => [['type' => 'recuperacao', 'id' => (int) $this->round->id]],
            'showc' => [true],
            'errors' => [
                'availability_recuperacao:error_selectround',
                'availability_recuperacao:error_unavailableround',
                'availability_recuperacao:error_toplevel',
                'availability_recuperacao:error_rootand',
                'availability_recuperacao:error_hidden',
            ],
        ]);
        \core_availability\frontend::report_validation_errors(['availabilityconditionsjson' => $json], $errors);
        $this->assertArrayHasKey('availabilityconditionsjson', $errors);
        $this->assertStringNotContainsString('[[', $errors['availabilityconditionsjson']);
        $this->assertStringContainsString(
            get_string('error_rootand', 'availability_recuperacao'),
            $errors['availabilityconditionsjson']
        );
    }

    /**
     * The editor page loads the built YUI module of the plugin with the parameters of the activity.
     */
    public function test_editor_loads_the_built_module(): void {
        global $CFG, $PAGE;
        $dir = $CFG->dirroot . '/availability/condition/recuperacao/yui/build/moodle-availability_recuperacao-form/';
        foreach (['', '-min', '-debug'] as $suffix) {
            $this->assertFileExists($dir . 'moodle-availability_recuperacao-form' . $suffix . '.js');
        }
        $PAGE->set_url('/course/modedit.php');
        $PAGE->set_context(\context_module::instance((int) $this->target->cmid));
        \core_availability\frontend::include_all_javascript($this->course, $this->cminfo((int) $this->target->cmid));
        $js = $PAGE->requires->get_end_code();
        $this->assertStringContainsString('moodle-availability_recuperacao-form', $js);
        $this->assertStringContainsString('"availability_recuperacao",true', $js);
    }
}
