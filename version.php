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
 * Version information for availability_recuperacao.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'availability_recuperacao';
$plugin->version   = 2026101710;
$plugin->release   = '0.3.0';
$plugin->requires  = 2025100600; // Moodle 5.1 (ADR-013).
$plugin->supported = [501, 502];
$plugin->maturity  = MATURITY_ALPHA;
// The access contract is mod_recuperacao\local\access\target_access, and its guard is mod_recuperacao\local\target_guard
// (install and uninstall callbacks, M1.5); the condition fails closed without them. The editor (M1.4c) reads the
// rounds of an activity through target_access::rounds_for_target(), new in mod_recuperacao 2026101708 (ADR-018).
$plugin->dependencies = ['mod_recuperacao' => 2026101708];
