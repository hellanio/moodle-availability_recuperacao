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
 * Strings for availability_recuperacao, English.
 *
 * @package    availability_recuperacao
 * @copyright  2026 OrangeBR
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['description'] = 'Allow only the students the recovery round gives access to: those sent and, as the round settings allow, those who passed or failed.';
$string['error_hidden'] = 'The restriction "Recovery round" must hide the activity entirely from students who do not meet it (eye closed).';
$string['error_rootand'] = 'The restrictions must be combined with "Student must match all of the following", otherwise students who were not sent could open the activity.';
$string['error_selectround'] = 'Choose the recovery round.';
$string['error_toplevel'] = 'The restriction "Recovery round" must be at the top level, not inside a restriction set.';
$string['error_unavailableround'] = 'This round does not use this activity as its recovery activity, so nobody gets access through this restriction. Choose a round in the list, or remove the restriction if no round uses this activity.';
$string['pluginname'] = 'Restriction by recovery round';
$string['privacy:metadata'] = 'The Restriction by recovery round plugin does not store any personal data.';
$string['requires_notrecuperacao'] = 'This restriction cannot be negated: nobody gets access through it';
$string['requires_recuperacao'] = 'Available to students of the recovery round (sent, and passed or failed as the round settings allow)';
$string['title'] = 'Recovery round';
$string['unavailableround'] = 'Round not available (#{$a})';
