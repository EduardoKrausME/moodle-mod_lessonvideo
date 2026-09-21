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
 * Backup structure for Video Lesson.
 *
 * @package mod_videolesson
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_videolesson_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the activity XML tree.
     *
     * @return backup_nested_element
     */
    protected function define_structure(): backup_nested_element {
        $userinfo = $this->get_setting_value('userinfo');

        $lesson = new backup_nested_element('videolesson', ['id'], [
            'course', 'name', 'intro', 'introformat', 'videosource', 'videourl', 'resumeplayback', 'allowseek',
            'maxplaybackrate', 'chapterpercent', 'completionmode', 'completionchapters', 'completionpercent', 'grade',
            'timecreated', 'timemodified',
        ]);
        $chapters = new backup_nested_element('chapters');
        $chapter = new backup_nested_element('chapter', ['id'], [
            'title', 'starttime', 'required', 'locknext', 'sortorder', 'timecreated', 'timemodified',
        ]);
        $items = new backup_nested_element('items');
        $item = new backup_nested_element('item', ['id'], [
            'type', 'title', 'content', 'contentformat', 'url', 'required', 'sortorder', 'timecreated', 'timemodified',
        ]);
        $progresses = new backup_nested_element('progresses');
        $progress = new backup_nested_element('progress', ['id'], [
            'userid', 'duration', 'lastposition', 'totalwatchtime', 'watchedsegments', 'percent', 'completed',
            'timecreated', 'timemodified',
        ]);
        $chapterprogresses = new backup_nested_element('chapterprogresses');
        $chapterprogress = new backup_nested_element('chapterprogress', ['id'], [
            'userid', 'watchedseconds', 'percent', 'completed', 'timecompleted', 'timemodified',
        ]);
        $itemprogresses = new backup_nested_element('itemprogresses');
        $itemprogress = new backup_nested_element('itemprogress', ['id'], [
            'userid', 'completed', 'response', 'timecompleted', 'timemodified',
        ]);

        $lesson->add_child($chapters);
        $chapters->add_child($chapter);
        $chapter->add_child($items);
        $items->add_child($item);
        $lesson->add_child($progresses);
        $progresses->add_child($progress);
        $chapter->add_child($chapterprogresses);
        $chapterprogresses->add_child($chapterprogress);
        $item->add_child($itemprogresses);
        $itemprogresses->add_child($itemprogress);

        $lesson->set_source_table('videolesson', ['id' => backup::VAR_ACTIVITYID]);
        $chapter->set_source_table('videolesson_chapters', ['videolessonid' => backup::VAR_PARENTID]);
        $item->set_source_table('videolesson_items', ['chapterid' => backup::VAR_PARENTID]);
        if ($userinfo) {
            $progress->set_source_table('videolesson_progress', ['videolessonid' => backup::VAR_PARENTID]);
            $chapterprogress->set_source_table('videolesson_chprogress', ['chapterid' => backup::VAR_PARENTID]);
            $itemprogress->set_source_table('videolesson_itemprogress', ['itemid' => backup::VAR_PARENTID]);
            $progress->annotate_ids('user', 'userid');
            $chapterprogress->annotate_ids('user', 'userid');
            $itemprogress->annotate_ids('user', 'userid');
        }

        $lesson->annotate_files('mod_videolesson', 'videofile', null);
        $lesson->annotate_files('mod_videolesson', 'captions', null);
        $item->annotate_files('mod_videolesson', 'itemfile', 'id');
        return $this->prepare_activity_structure($lesson);
    }
}
