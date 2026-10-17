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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

/**
 * Steps of the recovery activity guard (M1.5): switch the condition plugin as Manage restrictions does, and submit a
 * restriction tree without the frontend (M1.4).
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_availability_recuperacao extends behat_base {
    /**
     * Enables or disables an availability condition with the same API as Manage restrictions (config_log_created).
     *
     * @Given /^the availability condition "(?P<plugin_string>[a-z_]+)" is (?P<state>enabled|disabled)$/
     * @param string $plugin Condition plugin name, e.g. recuperacao.
     * @param string $state enabled or disabled.
     * @return void
     */
    public function the_availability_condition_is(string $plugin, string $state): void {
        \core\plugininfo\availability::enable_plugin($plugin, $state === 'enabled' ? 1 : 0);
        \core_plugin_manager::reset_caches();
    }

    /**
     * Puts in the restrictions field of the open form a tree that joins the condition of a round with a past date
     * by "or" (any of), as a teacher could build it, which the server must refuse (S-01).
     *
     * @When /^I set the restrictions field to an or tree of the recovery round "(?P<round_string>[^"]*)"$/
     * @param string $roundidnumber Round idnumber.
     * @return void
     */
    public function i_set_the_restrictions_to_an_or_tree(string $roundidnumber): void {
        global $DB;
        $roundid = (int) $DB->get_field('course_modules', 'instance', ['idnumber' => $roundidnumber], MUST_EXIST);
        $root = \core_availability\tree::get_root_json([
            \availability_recuperacao\condition::get_json($roundid),
            \availability_date\condition::get_json('>=', 1),
        ], \core_availability\tree::OP_OR, false);
        $this->execute('behat_forms::i_set_the_field_to', ['availabilityconditionsjson', json_encode($root)]);
    }
}
