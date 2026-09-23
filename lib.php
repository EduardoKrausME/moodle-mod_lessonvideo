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
 * Core callbacks for Video Lesson.
 *
 * @package   mod_lessonvideo
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_lessonvideo\progress_manager;

/**
 * Declares Moodle features supported by this module.
 *
 * @param string $feature Feature constant.
 * @return mixed
 */
function lessonvideo_supports(string $feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_COMPLETION_HAS_RULES => true,
        FEATURE_GRADE_HAS_GRADE => true,
        FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_CONTENT,
        default => null,
    };
}

/**
 * Creates a Video Lesson instance.
 *
 * @param stdClass $data Submitted activity data.
 * @param mixed $mform Activity form.
 * @return int New instance id.
 */
function lessonvideo_add_instance(stdClass $data, $mform = null): int {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->id = $DB->insert_record('lessonvideo', $data);
    $context = context_module::instance((int)$data->coursemodule);
    lessonvideo_save_activity_files($data, $context);
    lessonvideo_grade_item_update($data);
    return (int)$data->id;
}

/**
 * Updates a Video Lesson instance.
 *
 * @param stdClass $data Submitted activity data.
 * @param mixed $mform Activity form.
 * @return bool
 */
function lessonvideo_update_instance(stdClass $data, $mform = null): bool {
    global $DB;
    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record('lessonvideo', $data);
    $context = context_module::instance((int)$data->coursemodule);
    lessonvideo_save_activity_files($data, $context);
    lessonvideo_grade_item_update($data);
    return true;
}

/**
 * Saves video and caption drafts into protected File API areas.
 *
 * @param stdClass $data Activity data.
 * @param context_module $context Module context.
 * @return void
 */
function lessonvideo_save_activity_files(stdClass $data, context_module $context): void {
    foreach (['videofile', 'captions'] as $area) {
        if (isset($data->{$area})) {
            file_save_draft_area_files((int)$data->{$area}, $context->id, 'mod_lessonvideo', $area, 0, [
                'subdirs' => 0,
                'maxfiles' => $area === 'videofile' ? 1 : 20,
            ]);
        }
    }
}

/**
 * Deletes a Video Lesson instance and all dependent data.
 *
 * @param int $id Instance id.
 * @return bool
 */
function lessonvideo_delete_instance(int $id): bool {
    global $DB;
    $activity = $DB->get_record('lessonvideo', ['id' => $id]);
    if (!$activity) {
        return false;
    }
    $chapters = $DB->get_records('lessonvideo_chapters', ['lessonvideoid' => $id]);
    foreach ($chapters as $chapter) {
        $items = $DB->get_records('lessonvideo_items', ['chapterid' => $chapter->id]);
        foreach ($items as $item) {
            $DB->delete_records('lessonvideo_itemprogress', ['itemid' => $item->id]);
        }
        $DB->delete_records('lessonvideo_items', ['chapterid' => $chapter->id]);
        $DB->delete_records('lessonvideo_chprogress', ['chapterid' => $chapter->id]);
    }
    $DB->delete_records('lessonvideo_chapters', ['lessonvideoid' => $id]);
    $DB->delete_records('lessonvideo_progress', ['lessonvideoid' => $id]);
    $DB->delete_records('lessonvideo_sessions', ['lessonvideoid' => $id]);
    $DB->delete_records('lessonvideo', ['id' => $id]);
    lessonvideo_grade_item_delete($activity);
    return true;
}

/**
 * Serves protected files.
 *
 * @param stdClass $course Course record.
 * @param stdClass $cm Course module.
 * @param context $context Context.
 * @param string $filearea File area.
 * @param array $args Remaining path arguments.
 * @param bool $forcedownload Force download.
 * @param array $options Send options.
 * @return bool
 */
function lessonvideo_pluginfile($course, $cm, $context, string $filearea, array $args,
                                bool $forcedownload, array $options = []): bool {
    global $DB;
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }
    require_login($course, true, $cm);
    require_capability('mod/lessonvideo:view', $context);
    if (!in_array($filearea, ['videofile', 'captions', 'itemfile'], true)) {
        return false;
    }
    $itemid = (int)array_shift($args);
    if ($filearea !== 'itemfile' && $itemid !== 0) {
        return false;
    }
    if ($filearea === 'itemfile') {
        $item = $DB->get_record('lessonvideo_items', ['id' => $itemid], '*', MUST_EXIST);
        $chapter = $DB->get_record('lessonvideo_chapters', ['id' => $item->chapterid], '*', MUST_EXIST);
        if ((int)$chapter->lessonvideoid !== (int)$cm->instance) {
            return false;
        }
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_lessonvideo', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
    return true;
}

/**
 * Returns File API areas exposed by this module.
 *
 * @param stdClass $course Course record.
 * @param stdClass $cm Course module record.
 * @param context $context Module context.
 * @return array
 */
function lessonvideo_get_file_areas($course, $cm, $context): array {
    return [
        'videofile' => get_string('videofile', 'lessonvideo'),
        'captions' => get_string('captions', 'lessonvideo'),
        'itemfile' => get_string('itemfile', 'lessonvideo'),
    ];
}

/**
 * Returns course module information.
 *
 * @param stdClass $cm Course module record.
 * @return cached_cm_info|null
 */
function lessonvideo_get_coursemodule_info(stdClass $cm): ?cached_cm_info {
    global $DB;
    $activity = $DB->get_record('lessonvideo', ['id' => $cm->instance], 'id,name,intro,introformat,completionchapters');
    if (!$activity) {
        return null;
    }
    $info = new cached_cm_info();
    $info->name = $activity->name;
    if ($cm->showdescription) {
        $info->content = format_module_intro('lessonvideo', $activity, $cm->id, false);
    }
    if ((int)$cm->completion === COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules'] = [
            'completionchapters' => !empty($activity->completionchapters),
        ];
    }
    return $info;
}

/**
 * Returns active custom completion descriptions for the course page.
 *
 * @param cached_cm_info $cm Course module info.
 * @return array
 */
function lessonvideo_get_completion_active_rule_descriptions(cached_cm_info $cm): array {
    if ((int)$cm->completion !== COMPLETION_TRACKING_AUTOMATIC ||
        empty($cm->customdata['customcompletionrules']['completionchapters'])) {
        return [];
    }
    return [get_string('completiondetail:chapters', 'lessonvideo')];
}

/**
 * Legacy completion callback.
 *
 * @param stdClass $course Course record.
 * @param stdClass $cm Course module record.
 * @param int $userid User id.
 * @param bool $type Expected state.
 * @return bool
 */
function lessonvideo_get_completion_state($course, $cm, int $userid, bool $type): bool {
    global $DB;
    $progress = $DB->get_record('lessonvideo_progress', [
        'lessonvideoid' => $cm->instance,
        'userid' => $userid,
    ]);
    return $progress ? !empty($progress->completed) : false;
}

/**
 * Creates or updates the grade item.
 *
 * @param stdClass $activity Activity record.
 * @param array|null $grades Optional grades.
 * @return int
 */
function lessonvideo_grade_item_update(stdClass $activity, ?array $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $item = [
        'itemname' => $activity->name,
        'gradetype' => ((float)$activity->grade > 0) ? GRADE_TYPE_VALUE : GRADE_TYPE_NONE,
        'grademin' => 0,
        'grademax' => 100,
    ];
    return grade_update('mod/lessonvideo', $activity->course, 'mod', 'lessonvideo', $activity->id, 0, $grades, $item);
}

/**
 * Pushes current progress to gradebook.
 *
 * @param stdClass $activity Activity record.
 * @param int $userid Optional user id.
 * @param bool $nullifnone Whether to push null when no record exists.
 * @return void
 */
function lessonvideo_update_grades(stdClass $activity, int $userid = 0, bool $nullifnone = true): void {
    global $DB;
    $conditions = ['lessonvideoid' => $activity->id];
    if ($userid) {
        $conditions['userid'] = $userid;
    }
    $records = $DB->get_records('lessonvideo_progress', $conditions);
    $grades = [];
    foreach ($records as $record) {
        $grades[$record->userid] = (object)['userid' => $record->userid, 'rawgrade' => (float)$record->percent];
    }
    if (!$grades && $userid && $nullifnone) {
        $grades[$userid] = (object)['userid' => $userid, 'rawgrade' => null];
    }
    lessonvideo_grade_item_update($activity, $grades);
}

/**
 * Deletes grade item.
 *
 * @param stdClass $activity Activity record.
 * @return int
 */
function lessonvideo_grade_item_delete(stdClass $activity): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update('mod/lessonvideo', $activity->course, 'mod', 'lessonvideo', $activity->id, 0, null, ['deleted' => 1]);
}
