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

use Behat\Mink\Exception\ExpectationException;

/**
 * Steps of the recovery activity guard (M1.5): switch the condition plugin as Manage restrictions does, submit a
 * restriction tree without the frontend (M1.4), and check the tree saved by the editor (M1.4c).
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
        // The core field type refuses to replace existing restrictions, so the raw form field is written, as a
        // browser without the frontend would submit it.
        $field = $this->find('css', '[name="availabilityconditionsjson"]');
        $field->setValue(json_encode($root));
    }

    /**
     * Puts in the restrictions field of the open form the tree the editor builds for a round (root "and", the condition
     * as its only child, eye closed), as a browser without JavaScript would submit it.
     *
     * @When /^I set the restrictions field to the editor tree of the recovery round "(?P<round_string>[^"]*)"$/
     * @param string $roundidnumber Round idnumber.
     * @return void
     */
    public function i_set_the_restrictions_to_the_editor_tree(string $roundidnumber): void {
        global $DB;
        $roundid = (int) $DB->get_field('course_modules', 'instance', ['idnumber' => $roundidnumber], MUST_EXIST);
        $root = \core_availability\tree::get_root_json(
            [\availability_recuperacao\condition::get_json($roundid)],
            \core_availability\tree::OP_AND,
            false
        );
        $this->find('css', '[name="availabilityconditionsjson"]')->setValue(json_encode($root));
    }

    /**
     * Checks that the activity is restricted exactly as the editor saves the restriction "Recovery round": root "and",
     * the condition of the round as its only child, eye closed (J2 of docs/M1_4_DESIGN.md §5).
     *
     * @Then /^the restrictions of "(?P<activity_string>[^"]*)" should be only the recovery round "(?P<round_string>[^"]*)" hidden$/
     * @param string $activityidnumber Activity idnumber.
     * @param string $roundidnumber Round idnumber.
     * @return void
     */
    public function the_restrictions_should_be_only_the_round(string $activityidnumber, string $roundidnumber): void {
        global $DB;
        $roundid = (int) $DB->get_field('course_modules', 'instance', ['idnumber' => $roundidnumber], MUST_EXIST);
        $saved = (string) $DB->get_field('course_modules', 'availability', ['idnumber' => $activityidnumber], MUST_EXIST);
        $expected = ['op' => '&', 'c' => [['type' => 'recuperacao', 'id' => $roundid]], 'showc' => [false]];
        if (json_decode($saved, true) !== $expected) {
            throw new ExpectationException(
                'Restrictions of "' . $activityidnumber . '" are ' . $saved . ', expected ' . json_encode($expected),
                $this->getSession()
            );
        }
    }

    /**
     * Checks that the error shown on the restriction "Recovery round", its round and its note fit in the width of the
     * window, the error wrapping instead of running off the form (WCAG 1.4.10, UX-4C-01).
     *
     * @Then /^the restriction "Recovery round" should show its error within the window width$/
     * @return void
     */
    public function the_restriction_should_show_its_error_within_the_window(): void {
        $script = <<<'JS'
(function() {
    var width = document.documentElement.clientWidth;
    var problems = [];
    var badges = document.querySelectorAll('.availability-item .availability_recuperacao ~ .badge');
    var shown = 0;
    badges.forEach(function(badge) {
        if (!badge.textContent.trim()) {
            return;
        }
        shown++;
        if (window.getComputedStyle(badge).whiteSpace === 'nowrap') {
            problems.push('error does not wrap');
        }
        if (badge.scrollWidth > badge.clientWidth + 1) {
            problems.push('error is cut');
        }
    });
    if (!shown) {
        problems.push('no error shown');
    }
    var parts = '.availability_recuperacao ~ .badge, .availability-recuperacao-round, .availability-recuperacao-hiddennote';
    document.querySelectorAll(parts).forEach(function(part) {
        if (part.textContent.trim() && part.getBoundingClientRect().right > width + 1) {
            problems.push(part.className + ' beyond the window');
        }
    });
    return problems.join('; ');
})()
JS;
        $problems = (string) $this->evaluate_script($script);
        if ($problems !== '') {
            throw new ExpectationException('Restriction "Recovery round": ' . $problems, $this->getSession());
        }
    }

    /**
     * Checks that the restriction "Recovery round", with its list of rounds, fits in the width of the window and the
     * page does not scroll sideways (WCAG 1.4.10, UX-4C-14).
     *
     * @Then /^the restriction "Recovery round" should fit in the window width without horizontal scrolling$/
     * @return void
     */
    public function the_restriction_should_fit_without_horizontal_scrolling(): void {
        $script = <<<'JS'
(function() {
    var width = document.documentElement.clientWidth;
    var problems = [];
    if (document.documentElement.scrollWidth > width + 1) {
        problems.push('page scrolls sideways (' + document.documentElement.scrollWidth + ' > ' + width + ')');
    }
    var parts = document.querySelectorAll('.availability_recuperacao, .availability_recuperacao label, ' +
        '.availability_recuperacao select');
    if (!document.querySelector('.availability_recuperacao select')) {
        problems.push('no list of rounds');
    }
    parts.forEach(function(part) {
        var right = part.getBoundingClientRect().right;
        if (right > width + 1) {
            problems.push(part.tagName.toLowerCase() + ' beyond the window (' + Math.round(right) + ')');
        }
    });
    return problems.join('; ');
})()
JS;
        $problems = (string) $this->evaluate_script($script);
        if ($problems !== '') {
            throw new ExpectationException('Restriction "Recovery round": ' . $problems, $this->getSession());
        }
    }
}
