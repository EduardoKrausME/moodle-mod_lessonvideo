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
 * @package   mod_videolesson
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videolesson\progress_manager;

/**
 * Declares Moodle features supported by this module.
 *
 * @param string $feature Feature constant.
 * @return mixed
 */
function videolesson_supports(string $feature) {
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
function videolesson_add_instance(stdClass $data, $mform = null): int {
    global $DB;
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->id = $DB->insert_record('videolesson', $data);
    $context = context_module::instance((int)$data->coursemodule);
    videolesson_save_activity_files($data, $context);
    videolesson_grade_item_update($data);
    return (int)$data->id;
}

/**
 * Updates a Video Lesson instance.
 *
 * @param stdClass $data Submitted activity data.
 * @param mixed $mform Activity form.
 * @return bool
 */
function videolesson_update_instance(stdClass $data, $mform = null): bool {
    global $DB;
    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record('videolesson', $data);
    $context = context_module::instance((int)$data->coursemodule);
    videolesson_save_activity_files($data, $context);
    videolesson_grade_item_update($data);
    return true;
}

/**
 * Saves video and caption drafts into protected File API areas.
 *
 * @param stdClass $data Activity data.
 * @param context_module $context Module context.
 * @return void
 */
function videolesson_save_activity_files(stdClass $data, context_module $context): void {
    foreach (['videofile', 'captions'] as $area) {
        if (isset($data->{$area})) {
            file_save_draft_area_files((int)$data->{$area}, $context->id, 'mod_videolesson', $area, 0, [
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
function videolesson_delete_instance(int $id): bool {
    global $DB;
    $activity = $DB->get_record('videolesson', ['id' => $id]);
    if (!$activity) {
        return false;
    }
    $chapters = $DB->get_records('videolesson_chapters', ['videolessonid' => $id]);
    foreach ($chapters as $chapter) {
        $items = $DB->get_records('videolesson_items', ['chapterid' => $chapter->id]);
        foreach ($items as $item) {
            $DB->delete_records('videolesson_itemprogress', ['itemid' => $item->id]);
        }
        $DB->delete_records('videolesson_items', ['chapterid' => $chapter->id]);
        $DB->delete_records('videolesson_chprogress', ['chapterid' => $chapter->id]);
    }
    $DB->delete_records('videolesson_chapters', ['videolessonid' => $id]);
    $DB->delete_records('videolesson_progress', ['videolessonid' => $id]);
    $DB->delete_records('videolesson_sessions', ['videolessonid' => $id]);
    $DB->delete_records('videolesson', ['id' => $id]);
    videolesson_grade_item_delete($activity);
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
function videolesson_pluginfile($course, $cm, $context, string $filearea, array $args,
                                bool $forcedownload, array $options = []): bool {
    global $DB;
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }
    require_login($course, true, $cm);
    require_capability('mod/videolesson:view', $context);
    if (!in_array($filearea, ['videofile', 'captions', 'itemfile'], true)) {
        return false;
    }
    $itemid = (int)array_shift($args);
    if ($filearea !== 'itemfile' && $itemid !== 0) {
        return false;
    }
    if ($filearea === 'itemfile') {
        $item = $DB->get_record('videolesson_items', ['id' => $itemid], '*', MUST_EXIST);
        $chapter = $DB->get_record('videolesson_chapters', ['id' => $item->chapterid], '*', MUST_EXIST);
        if ((int)$chapter->videolessonid !== (int)$cm->instance) {
            return false;
        }
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_videolesson', $filearea, $itemid, $filepath, $filename);
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
function videolesson_get_file_areas($course, $cm, $context): array {
    return [
        'videofile' => get_string('videofile', 'videolesson'),
        'captions' => get_string('captions', 'videolesson'),
        'itemfile' => get_string('itemfile', 'videolesson'),
    ];
}

/**
 * Returns course module information.
 *
 * @param stdClass $cm Course module record.
 * @return cached_cm_info|null
 */
function videolesson_get_coursemodule_info(stdClass $cm): ?cached_cm_info {
    global $DB;
    $activity = $DB->get_record('videolesson', ['id' => $cm->instance], 'id,name,intro,introformat,completionchapters');
    if (!$activity) {
        return null;
    }
    $info = new cached_cm_info();
    $info->name = $activity->name;
    if ($cm->showdescription) {
        $info->content = format_module_intro('videolesson', $activity, $cm->id, false);
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
function videolesson_get_completion_active_rule_descriptions(cached_cm_info $cm): array {
    if ((int)$cm->completion !== COMPLETION_TRACKING_AUTOMATIC ||
        empty($cm->customdata['customcompletionrules']['completionchapters'])) {
        return [];
    }
    return [get_string('completiondetail:chapters', 'videolesson')];
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
function videolesson_get_completion_state($course, $cm, int $userid, bool $type): bool {
    global $DB;
    $progress = $DB->get_record('videolesson_progress', [
        'videolessonid' => $cm->instance,
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
function videolesson_grade_item_update(stdClass $activity, ?array $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $item = [
        'itemname' => $activity->name,
        'gradetype' => ((float)$activity->grade > 0) ? GRADE_TYPE_VALUE : GRADE_TYPE_NONE,
        'grademin' => 0,
        'grademax' => 100,
    ];
    return grade_update('mod/videolesson', $activity->course, 'mod', 'videolesson', $activity->id, 0, $grades, $item);
}

/**
 * Pushes current progress to gradebook.
 *
 * @param stdClass $activity Activity record.
 * @param int $userid Optional user id.
 * @param bool $nullifnone Whether to push null when no record exists.
 * @return void
 */
function videolesson_update_grades(stdClass $activity, int $userid = 0, bool $nullifnone = true): void {
    global $DB;
    $conditions = ['videolessonid' => $activity->id];
    if ($userid) {
        $conditions['userid'] = $userid;
    }
    $records = $DB->get_records('videolesson_progress', $conditions);
    $grades = [];
    foreach ($records as $record) {
        $grades[$record->userid] = (object)['userid' => $record->userid, 'rawgrade' => (float)$record->percent];
    }
    if (!$grades && $userid && $nullifnone) {
        $grades[$userid] = (object)['userid' => $userid, 'rawgrade' => null];
    }
    videolesson_grade_item_update($activity, $grades);
}

/**
 * Deletes grade item.
 *
 * @param stdClass $activity Activity record.
 * @return int
 */
function videolesson_grade_item_delete(stdClass $activity): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update('mod/videolesson', $activity->course, 'mod', 'videolesson', $activity->id, 0, null, ['deleted' => 1]);
}
