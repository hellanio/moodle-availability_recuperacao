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
use mod_recuperacao\local\access\target_access;
use mod_recuperacao\local\target_guard;
use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Install and uninstall of availability_recuperacao with the guard of mod_recuperacao (M1.5, T13-T15, ADR-015).
 *
 * The real command line uninstall runs only on the test site; here the same core function, uninstall_plugin(), runs
 * inside the PHPUnit reset.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('xmldb_availability_recuperacao_uninstall')]
#[CoversFunction('xmldb_availability_recuperacao_install')]
final class guard_integration_test extends \advanced_testcase {
    /**
     * T13 and T14: the uninstall hides the recovery activity before the core forgets the plugin, keeps the stored
     * condition, and the task does not release it while the version is gone; the install gives the access back.
     */
    public function test_uninstall_then_install(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');
        require_once($CFG->dirroot . '/availability/condition/recuperacao/db/install.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('enableavailability', 1);
        $generator = $this->getDataGenerator();
        $modgenerator = $generator->get_plugin_generator('mod_recuperacao');
        $course = $generator->create_course();
        $target = $generator->create_module('page', ['course' => $course->id]);
        $round = $modgenerator->create_instance(['course' => $course->id, 'targetcmid' => $target->cmid]);
        $root = tree::get_root_json([condition::get_json((int) $round->id)], tree::OP_AND, [false]);
        $json = json_encode($root);
        $DB->set_field('course_modules', 'availability', $json, ['id' => $target->cmid]);
        $sent = $generator->create_and_enrol($course, 'student');
        $modgenerator->create_case(['recuperacaoid' => $round->id, 'userid' => $sent->id, 'status' => 'sent']);
        $other = $generator->create_and_enrol($course, 'student');
        $sees = function (int $userid) use ($course, $target): bool {
            target_access::reset_caches();
            rebuild_course_cache((int) $course->id, true);
            get_fast_modinfo(0, 0, true);
            return get_fast_modinfo($course->id, $userid)->get_cm((int) $target->cmid)->uservisible;
        };
        target_guard::sync_all();
        $this->assertTrue($sees((int) $sent->id));
        $this->assertFalse($sees((int) $other->id));

        // Uninstall (the core function behind admin/cli/uninstall_plugins.php).
        $sink = $this->redirectMessages();
        ob_start();
        uninstall_plugin('availability', 'recuperacao');
        ob_end_clean();
        // The core uninstall_plugin() reads every install.xml of the site; notices of other plugins are not under test.
        $this->resetDebugging();
        \core_plugin_manager::reset_caches();

        $this->assertFalse(get_config('availability_recuperacao', 'version'));
        $row = $DB->get_record(target_guard::TABLE, ['cmid' => $target->cmid], '*', MUST_EXIST);
        $this->assertSame(target_guard::STATE_ENGAGED, $row->state);
        $this->assertSame(target_guard::REASON_UNINSTALLED, $row->reason);
        $this->assertSame($json, $DB->get_field('course_modules', 'availability', ['id' => $target->cmid]));
        $this->assertFalse($sees((int) $sent->id));
        $this->assertFalse($sees((int) $other->id));
        $this->assertNotEmpty($sink->get_messages());

        // The task does not release it: the version is gone.
        target_guard::sync_all();
        $this->assertSame(target_guard::STATE_ENGAGED, $DB->get_field(target_guard::TABLE, 'state', ['cmid' => $target->cmid]));

        // Install again: the version is stored before the install callback (upgrade_plugins()).
        set_config('version', 2026101701, 'availability_recuperacao');
        $this->assertTrue(xmldb_availability_recuperacao_install());
        $this->assertSame(target_guard::STATE_OK, $DB->get_field(target_guard::TABLE, 'state', ['cmid' => $target->cmid]));
        $this->assertTrue($sees((int) $sent->id));
        $this->assertFalse($sees((int) $other->id));
    }

    /**
     * T15: the core refuses to uninstall mod_recuperacao while this plugin, which requires it, is installed (F10).
     */
    public function test_mod_not_uninstallable_while_required(): void {
        $this->resetAfterTest();
        $pluginman = \core_plugin_manager::instance();
        $this->assertFalse($pluginman->can_uninstall_plugin('mod_recuperacao'));
        $this->assertTrue($pluginman->can_uninstall_plugin('availability_recuperacao'));
        $this->assertEquals(
            2026101708,
            $pluginman->get_plugin_info('availability_recuperacao')->dependencies['mod_recuperacao']
        );
    }
}
