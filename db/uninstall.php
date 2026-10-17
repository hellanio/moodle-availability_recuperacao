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

/**
 * Uninstall steps for availability_recuperacao (M1.5, ADR-015).
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Before the core removes this plugin, hides every recovery activity protected by its condition: the core keeps the
 * condition in course_modules.availability but stops applying it, which would open the activities to every student
 * (F9). The core ignores the result and an exception would leave the uninstall half done (F8), so this never throws
 * and cannot veto: if it fails, the scheduled task of the guard hides them within minutes, because the plugin version
 * is gone.
 *
 * @return bool Always true.
 */
function xmldb_availability_recuperacao_uninstall(): bool {
    global $DB;

    try {
        if (
            class_exists(\mod_recuperacao\local\target_guard::class)
                && $DB->get_manager()->table_exists(\mod_recuperacao\local\target_guard::TABLE)
        ) {
            \mod_recuperacao\local\target_guard::engage_all(\mod_recuperacao\local\target_guard::REASON_UNINSTALLED);
        }
    } catch (\Throwable $e) {
        debugging(
            'availability_recuperacao uninstall: the recovery activity guard could not run; its task will.',
            DEBUG_DEVELOPER
        );
    }
    return true;
}
