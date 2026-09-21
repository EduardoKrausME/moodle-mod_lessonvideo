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
require_once($CFG->dirroot . '/mod/videolesson/backup/moodle2/backup_videolesson_stepslib.php');

/**
 * Backup task for Video Lesson.
 *
 * @package mod_videolesson
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_videolesson_activity_task extends backup_activity_task {

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
        $this->add_step(new backup_videolesson_activity_structure_step('videolesson_structure', 'videolesson.xml'));
    }

    /**
     * Encodes links to this activity.
     *
     * @param string $content Content.
     * @return string
     */
    public static function encode_content_links($content): string {
        global $CFG;
        $base = preg_quote($CFG->wwwroot . '/mod/videolesson/index.php?id=', '#');
        $content = preg_replace("#({$base})([0-9]+)#", '$@VIDELESSONINDEX*$2@$', $content);
        $base = preg_quote($CFG->wwwroot . '/mod/videolesson/view.php?id=', '#');
        return preg_replace("#({$base})([0-9]+)#", '$@VIDELESSONVIEWBYID*$2@$', $content);
    }
}
