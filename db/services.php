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
 * External services for Video Lesson.
 *
 * @package   mod_videolesson
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_videolesson_update_progress' => [
        'classname' => '\\mod_videolesson\\external\\update_progress',
        'methodname' => 'execute',
        'description' => 'Stores validated video playback progress and returns chapter state.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/videolesson:view',
    ],
    'mod_videolesson_complete_item' => [
        'classname' => '\\mod_videolesson\\external\\complete_item',
        'methodname' => 'execute',
        'description' => 'Completes a chapter content item or stores a question response.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/videolesson:view',
    ],
];
