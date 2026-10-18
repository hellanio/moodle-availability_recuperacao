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

use core_availability\info_section;
use mod_recuperacao\local\state_machine;
use mod_recuperacao\local\target_guard;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Client/server parity of the editor (M1.4c, U12 and the server items of docs/M1_4_DESIGN.md §4.2): the tree the editor
 * builds is saved, and every tree the editor only points out (or a forged POST without its errors) is refused by the
 * server, whatever the browser sent.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class form_parity_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass Recovery activity. */
    private \stdClass $target;

    /** @var \stdClass Round. */
    private \stdClass $round;

    /** @var \stdClass Another round, using another activity. */
    private \stdClass $otherround;

    /** @var \stdClass Another activity of the course, used by $otherround. */
    private \stdClass $other;

    protected function setUp(): void {
        global $CFG, $DB;
        parent::setUp();
        require_once($CFG->dirroot . '/course/moodleform_mod.php');
        require_once($CFG->dirroot . '/mod/recuperacao/lib.php');
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_recuperacao');
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->target = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->round = $generator->create_instance(['course' => $this->course->id, 'targetcmid' => $this->target->cmid]);
        $this->other = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->otherround = $generator->create_instance(['course' => $this->course->id,
            'targetcmid' => $this->other->cmid]);
        // The recovery activity carries the restriction, as saved by the editor; the guard is told, as a save of the
        // activity settings would (ADR-021).
        $DB->set_field('course_modules', 'availability', '{"op":"&","c":[{"type":"recuperacao","id":'
            . (int) $this->round->id . '}],"showc":[false]}', ['id' => $this->target->cmid]);
        rebuild_course_cache((int) $this->course->id, true);
        target_guard::sync_cms([(int) $this->target->cmid]);
    }

    /**
     * Result of the form callback of mod_recuperacao for a submitted tree.
     *
     * @param int $cmid Course module of the form.
     * @param string $json Submitted availabilityconditionsjson.
     * @return array Errors.
     */
    private function validate(int $cmid, string $json): array {
        $form = $this->createMock(\moodleform_mod::class);
        $form->method('get_coursemodule')->willReturn(get_fast_modinfo($this->course)->get_cm($cmid));
        return recuperacao_coursemodule_validation($form, ['availabilityconditionsjson' => $json]);
    }

    /**
     * Expected error for a reason of the guard.
     *
     * @param string $reason Reason.
     * @return array
     */
    private function error(string $reason): array {
        return ['availabilityconditionsjson' => get_string('error:guard_' . $reason, 'mod_recuperacao')];
    }

    /**
     * The JSON the editor builds for the round: root "and", the condition as a direct child, eye closed.
     */
    public function test_tree_built_by_the_editor_is_saved(): void {
        $id = (int) $this->round->id;
        $this->assertSame([], $this->validate(
            (int) $this->target->cmid,
            '{"op":"&","c":[{"type":"recuperacao","id":' . $id . '}],"showc":[false]}'
        ));
        // With another restriction: the private rule closes every eye.
        $this->assertSame([], $this->validate((int) $this->target->cmid, json_encode([
            'op' => '&',
            'c' => [['type' => 'date', 'd' => '>=', 't' => 1], ['type' => 'recuperacao', 'id' => $id]],
            'showc' => [false, false],
        ])));
    }

    /**
     * Forged trees (POST without the errors of the editor) and what the server answers.
     *
     * @return array
     */
    public static function forged_provider(): array {
        $rec = '{"type":"recuperacao","id":ROUND}';
        return [
            'any of' => ['{"op":"|","c":[' . $rec . '],"show":false}', target_guard::REASON_TREE_INVALID],
            'must not, all' => ['{"op":"!&","c":[' . $rec . '],"show":false}', target_guard::REASON_TREE_INVALID],
            'must not, any' => ['{"op":"!|","c":[' . $rec . '],"showc":[false]}', target_guard::REASON_TREE_INVALID],
            'nested in a set' => ['{"op":"&","c":[{"op":"&","c":[' . $rec . ']}],"showc":[false]}',
                target_guard::REASON_TREE_INVALID],
            'eye open' => ['{"op":"&","c":[' . $rec . '],"showc":[true]}', target_guard::REASON_TREE_INVALID],
            'show instead of showc' => ['{"op":"&","c":[' . $rec . '],"show":false}', target_guard::REASON_TREE_INVALID],
            'id as a string' => ['{"op":"&","c":[{"type":"recuperacao","id":"ROUND"}],"showc":[false]}',
                target_guard::REASON_TREE_INVALID],
            'no id (nothing chosen)' => ['{"op":"&","c":[{"type":"recuperacao"}],"showc":[false]}',
                target_guard::REASON_TREE_INVALID],
            'round of another activity' => ['{"op":"&","c":[{"type":"recuperacao","id":OTHER}],"showc":[false]}',
                target_guard::REASON_TREE_INVALID],
            'errors of the editor kept' => ['{"op":"|","c":[' . $rec . '],"show":false,'
                . '"errors":["availability_recuperacao:error_rootand"]}', target_guard::REASON_TREE_INVALID],
            'condition removed' => ['', target_guard::REASON_CONDITION_MISSING],
            'condition replaced' => ['{"op":"&","c":[{"type":"date","d":">=","t":1}],"showc":[false]}',
                target_guard::REASON_CONDITION_MISSING],
            'unreadable' => ['{"op":"&","c":[{"type":"recuperacao"', target_guard::REASON_READ_ERROR],
        ];
    }

    /**
     * Every forged tree is refused with the reason the guard gives, and the activity stays as it was.
     *
     * @param string $json Tree, with ROUND and OTHER for the round ids.
     * @param string $reason Expected reason.
     */
    #[DataProvider('forged_provider')]
    public function test_forged_tree_is_refused(string $json, string $reason): void {
        global $DB;
        $before = $DB->get_field('course_modules', 'availability', ['id' => $this->target->cmid]);
        $json = str_replace(['ROUND', 'OTHER'], [(string) $this->round->id, (string) $this->otherround->id], $json);
        $this->assertSame($this->error($reason), $this->validate((int) $this->target->cmid, $json));
        $this->assertSame($before, $DB->get_field('course_modules', 'availability', ['id' => $this->target->cmid]));
    }

    /**
     * The restriction on an activity no round uses, even with a valid tree, is refused: the editor never offers it
     * there, and a forged POST does not get through.
     */
    public function test_condition_on_an_activity_that_is_not_a_target_is_refused(): void {
        $free = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $json = '{"op":"&","c":[{"type":"recuperacao","id":' . (int) $this->round->id . '}],"showc":[false]}';
        $this->assertSame($this->error(target_guard::REASON_TREE_INVALID), $this->validate((int) $free->cmid, $json));
    }

    /**
     * A restriction on a section (the section form does not call the callback of mod_recuperacao) never opens
     * anything: the condition refuses every user there, the sent student included.
     */
    public function test_condition_on_a_section_opens_nothing(): void {
        global $DB;
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_recuperacao');
        $sent = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $generator->create_case(['recuperacaoid' => (int) $this->round->id, 'userid' => $sent->id,
            'status' => state_machine::STATUS_SENT]);
        $json = '{"op":"&","c":[{"type":"recuperacao","id":' . (int) $this->round->id . '}],"showc":[false]}';
        $DB->set_field('course_sections', 'availability', $json, ['course' => $this->course->id, 'section' => 1]);
        rebuild_course_cache((int) $this->course->id, true);

        $section = get_fast_modinfo($this->course->id, (int) $sent->id)->get_section_info(1);
        $information = '';
        $this->assertFalse((new info_section($section))->is_available($information, false, (int) $sent->id));
        $this->assertFalse($section->uservisible);
    }
}
