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
 * Install steps for availability_recuperacao (M1.5, ADR-015).
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * After an install or a reinstall, gives back the visibility of the recovery activities the guard of mod_recuperacao
 * hid while this plugin was missing, where the protection works again (T14).
 *
 * The version is stored before this runs; the plugin list cache is reset so the guard sees the plugin installed.
 * On a new site, or when mod_recuperacao is upgraded after this plugin in the same run, the guard table may not exist
 * yet: its scheduled task does the same within minutes. Never stops the install.
 *
 * @return bool Always true.
 */
function xmldb_availability_recuperacao_install(): bool {
    global $DB;

    try {
        if (
            !class_exists(\mod_recuperacao\local\target_guard::class)
                || !$DB->get_manager()->table_exists(\mod_recuperacao\local\target_guard::TABLE)
        ) {
            return true;
        }
        \core_plugin_manager::reset_caches();
        \mod_recuperacao\local\target_guard::sync_all();
    } catch (\Throwable $e) {
        // The scheduled task of the guard checks again.
        debugging(
            'availability_recuperacao install: the recovery activity guard could not run; its task will.',
            DEBUG_DEVELOPER
        );
    }
    return true;
}
