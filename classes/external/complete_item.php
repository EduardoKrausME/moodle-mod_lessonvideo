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

namespace mod_lessonvideo\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_lessonvideo\progress_manager;

/**
 * AJAX endpoint for chapter content completion.
 *
 * @package mod_lessonvideo
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class complete_item extends external_api {

    /**
     * Method execute_parameters.
     *
     * @return external_function_parameters Return value.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'itemid' => new external_value(PARAM_INT, 'Content item id'),
            'response' => new external_value(PARAM_TEXT, 'Question response', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Completes an item.
     *
     * @param int $cmid Course module id.
     * @param int $itemid Item id.
     * @param string $response Optional response.
     * @return array
     */
    public static function execute(int $cmid, int $itemid, string $response = ''): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact('cmid', 'itemid', 'response'));
        $cm = get_coursemodule_from_id('lessonvideo', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/lessonvideo:view', $context);
        $activity = $DB->get_record('lessonvideo', ['id' => $cm->instance], '*', MUST_EXIST);
        return (new progress_manager())->complete_item($activity, $cm, $params['itemid'], $USER->id, $params['response']);
    }

    /**
     * Method execute_returns.
     *
     * @return external_single_structure Return value.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'percent' => new external_value(PARAM_FLOAT, 'Overall progress'),
            'percentrounded' => new external_value(PARAM_INT, 'Rounded progress'),
            'completed' => new external_value(PARAM_BOOL, 'Lesson complete'),
            'chapters' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Chapter id'),
                'title' => new external_value(PARAM_TEXT, 'Chapter title'),
                'starttime' => new external_value(PARAM_FLOAT, 'Start time'),
                'percent' => new external_value(PARAM_FLOAT, 'Chapter progress'),
                'percentrounded' => new external_value(PARAM_INT, 'Rounded progress'),
                'completed' => new external_value(PARAM_BOOL, 'Chapter complete'),
                'inprogress' => new external_value(PARAM_BOOL, 'Chapter in progress'),
                'notstarted' => new external_value(PARAM_BOOL, 'Chapter not started'),
                'required' => new external_value(PARAM_BOOL, 'Required'),
                'locknext' => new external_value(PARAM_BOOL, 'Locks next'),
                'itemscomplete' => new external_value(PARAM_BOOL, 'Required items complete'),
            ])),
            'unlockedmax' => new external_value(PARAM_FLOAT, 'Maximum unlocked position', VALUE_OPTIONAL),
            'lastposition' => new external_value(PARAM_FLOAT, 'Stored position'),
        ]);
    }
}
