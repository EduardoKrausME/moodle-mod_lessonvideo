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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/videolesson/backup/moodle2/restore_videolesson_stepslib.php');

/**
 * Restore task for Video Lesson.
 *
 * @package mod_videolesson
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_videolesson_activity_task extends restore_activity_task {

    /**
     * Method define_my_settings.
     *
     * @return void Return value.
     */
    protected function define_my_settings(): void {
    }

    /**
     * Method define_my_steps.
     *
     * @return void Return value.
     */
    protected function define_my_steps(): void {
        $this->add_step(new restore_videolesson_activity_structure_step('videolesson_structure', 'videolesson.xml'));
    }

    /**
     * Defines content decoding rules.
     *
     * @return array
     */
    public static function define_decode_contents(): array {
        return [new restore_decode_content('videolesson', ['intro'], 'videolesson')];
    }

    /**
     * Defines URL decoding rules.
     *
     * @return array
     */
    public static function define_decode_rules(): array {
        return [
            new restore_decode_rule('VIDELESSONVIEWBYID', '/mod/videolesson/view.php?id=$1', 'course_module'),
            new restore_decode_rule('VIDELESSONINDEX', '/mod/videolesson/index.php?id=$1', 'course'),
        ];
    }
}
