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

namespace mod_lessonvideo\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion implementation for Video Lesson.
 *
 * @package mod_lessonvideo
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Returns state of the chapter completion rule.
     *
     * @param string $rule Rule name.
     * @return int
     */
    public function get_state(string $rule): int {
        global $DB;
        $this->validate_rule($rule);
        $progress = $DB->get_record('lessonvideo_progress', [
            'lessonvideoid' => $this->cm->instance,
            'userid' => $this->userid,
        ]);
        return ($progress && !empty($progress->completed)) ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Method get_defined_custom_rules.
     *
     * @return array Return value.
     */
    public static function get_defined_custom_rules(): array {
        return ['completionchapters'];
    }

    /**
     * Method get_custom_rule_descriptions.
     *
     * @return array Return value.
     */
    public function get_custom_rule_descriptions(): array {
        return ['completionchapters' => get_string('completiondetail:chapters', 'lessonvideo')];
    }

    /**
     * Method get_sort_order.
     *
     * @return array Return value.
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionchapters', 'completionusegrade', 'completionpassgrade'];
    }
}

