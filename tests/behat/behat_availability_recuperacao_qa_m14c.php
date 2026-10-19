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
 * QA steps of M1.4c: check a saved tree with more conditions than the recovery round, and the guard row.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_availability_recuperacao_qa_m14c extends behat_base {
    /**
     * Checks the saved tree: root "and", every eye closed, the condition of the round as a direct child, and the given
     * number of direct children.
     *
     * @Then /^the restrictions of "([^"]*)" should join (\d+) conditions with the recovery round "([^"]*)" all hidden$/
     * @param string $activityidnumber Activity idnumber.
     * @param int $count Direct children expected.
     * @param string $roundidnumber Round idnumber.
     * @return void
     */
    public function the_restrictions_should_join_conditions_hidden(
        string $activityidnumber,
        int $count,
        string $roundidnumber
    ): void {
        global $DB;
        $roundid = (int) $DB->get_field('course_modules', 'instance', ['idnumber' => $roundidnumber], MUST_EXIST);
        $saved = (string) $DB->get_field('course_modules', 'availability', ['idnumber' => $activityidnumber], MUST_EXIST);
        $tree = json_decode($saved, true);
        $ok = is_array($tree) && ($tree['op'] ?? null) === '&' && isset($tree['c'], $tree['showc'])
            && count($tree['c']) === $count && count($tree['showc']) === $count
            && !in_array(true, $tree['showc'], true)
            && in_array(['type' => 'recuperacao', 'id' => $roundid], $tree['c'], true);
        if (!$ok) {
            throw new ExpectationException('Restrictions of "' . $activityidnumber . '" are ' . $saved, $this->getSession());
        }
    }

    /**
     * Checks the state of the guard of mod_recuperacao for the activity: "ok", or "engaged:<reason>".
     *
     * @Then /^the recovery guard of "(?P<activity_string>[^"]*)" should be "(?P<reason_string>[a-z_:]*)"$/
     * @param string $activityidnumber Activity idnumber.
     * @param string $reason Expected state, "ok" or "engaged:<reason>".
     * @return void
     */
    public function the_guard_should_be(string $activityidnumber, string $reason): void {
        global $DB;
        $cmid = (int) $DB->get_field('course_modules', 'id', ['idnumber' => $activityidnumber], MUST_EXIST);
        $row = $DB->get_record('recuperacao_target_guard', ['cmid' => $cmid]);
        $actual = $row ? $row->state . ($row->state === 'ok' ? '' : ':' . $row->reason) : '(no row)';
        if ($actual !== $reason) {
            throw new ExpectationException(
                "Guard of \"$activityidnumber\" is \"$actual\", expected \"$reason\"",
                $this->getSession()
            );
        }
    }
}
