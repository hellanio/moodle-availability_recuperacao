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

use mod_recuperacao\local\access\target_access;

/**
 * Editor of the restriction "Recovery round" (M1.4c, ADR-018).
 *
 * The restriction never chooses the activity: the recovery activity of a round is chosen only in the round settings,
 * and the restriction is offered only in that very activity, naming one of the rounds that use it. Anything else
 * (a section, an activity being created, an activity no round uses, the contract of mod_recuperacao missing or
 * failing) offers nothing (fail closed for the editor). The server is the real barrier: mod_recuperacao refuses a
 * tree that would open the activity (target_guard::form_problem()).
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class frontend extends \core_availability\frontend {
    /** @var array<int, array<int, string>> Raw round names by round id, by course module id (one page, one editor). */
    protected array $rounds = [];

    /**
     * Strings used by the YUI module.
     *
     * @return string[]
     */
    protected function get_javascript_strings() {
        return [
            'error_hidden',
            'error_rootand',
            'error_selectround',
            'error_toplevel',
            'error_unavailableround',
            'hiddennote',
            'unavailableround',
        ];
    }

    /**
     * The rounds whose recovery activity is the activity being edited, for the YUI module: [[{id, name}, ...]].
     *
     * A restriction already saved with another round is shown by the editor as an unavailable round.
     *
     * @param \stdClass $course Course object.
     * @param \cm_info|null $cm Course module being edited, null if none.
     * @param \section_info|null $section Section being edited, null if none.
     * @return array
     */
    protected function get_javascript_init_params($course, ?\cm_info $cm = null, ?\section_info $section = null) {
        $list = [];
        if ($section === null && $cm !== null) {
            $context = \context_course::instance((int) $course->id);
            foreach ($this->rounds_of($cm) as $id => $name) {
                $list[] = (object) ['id' => $id, 'name' => format_string($name, true, ['context' => $context])];
            }
        }
        return [$list];
    }

    /**
     * Offered only in the recovery activity of a round of the course, never in a section or a new activity.
     *
     * @param \stdClass $course Course object.
     * @param \cm_info|null $cm Course module being edited, null if none.
     * @param \section_info|null $section Section being edited, null if none.
     * @return bool
     */
    protected function allow_add($course, ?\cm_info $cm = null, ?\section_info $section = null) {
        if ($section !== null || $cm === null || empty($cm->id) || (int) $cm->course !== (int) $course->id) {
            return false;
        }
        return $this->rounds_of($cm) !== [];
    }

    /**
     * Rounds using the course module as their recovery activity, through the contract of mod_recuperacao.
     *
     * @param \cm_info $cm Course module.
     * @return array<int, string> Raw name by round id; empty when there is none or on any error.
     */
    protected function rounds_of(\cm_info $cm): array {
        $cmid = (int) $cm->id;
        if (!array_key_exists($cmid, $this->rounds)) {
            $rounds = [];
            try {
                if (class_exists(target_access::class) && method_exists(target_access::class, 'rounds_for_target')) {
                    $rounds = target_access::rounds_for_target($cmid);
                }
            } catch (\Throwable $e) {
                $rounds = [];
            }
            $this->rounds[$cmid] = $rounds;
        }
        return $this->rounds[$cmid];
    }
}
