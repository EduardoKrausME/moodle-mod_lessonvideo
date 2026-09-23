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
 * Restore structure for Video Lesson.
 *
 * @package mod_lessonvideo
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_lessonvideo_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines restore paths.
     *
     * @return restore_path_element[]
     */
    protected function define_structure(): array {
        $paths = [
            new restore_path_element('lessonvideo', '/activity/lessonvideo'),
            new restore_path_element('lessonvideo_chapter', '/activity/lessonvideo/chapters/chapter'),
            new restore_path_element('lessonvideo_item', '/activity/lessonvideo/chapters/chapter/items/item'),
        ];
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('lessonvideo_progress',
                '/activity/lessonvideo/progresses/progress');
            $paths[] = new restore_path_element('lessonvideo_chprogress',
                '/activity/lessonvideo/chapters/chapter/chapterprogresses/chapterprogress');
            $paths[] = new restore_path_element('lessonvideo_itemprogress',
                '/activity/lessonvideo/chapters/chapter/items/item/itemprogresses/itemprogress');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * process_lessonvideo
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    protected function process_lessonvideo($data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $newid = $DB->insert_record('lessonvideo', $data);
        $this->apply_activity_instance($newid);
        $this->set_mapping('lessonvideo', $oldid, $newid, true);
    }

    /**
     * process_lessonvideo_chapter
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    protected function process_lessonvideo_chapter($data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->lessonvideoid = $this->get_new_parentid('lessonvideo');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $newid = $DB->insert_record('lessonvideo_chapters', $data);
        $this->set_mapping('lessonvideo_chapter', $oldid, $newid);
    }

    /**
     * process_lessonvideo_item
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    protected function process_lessonvideo_item($data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->chapterid = $this->get_new_parentid('lessonvideo_chapter');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $newid = $DB->insert_record('lessonvideo_items', $data);
        $this->set_mapping('lessonvideo_item', $oldid, $newid, true);
    }

    /**
     * process_lessonvideo_progress
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    protected function process_lessonvideo_progress($data): void {
        global $DB;
        $data = (object)$data;
        $data->lessonvideoid = $this->get_new_parentid('lessonvideo');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        unset($data->id);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $DB->insert_record('lessonvideo_progress', $data);
    }

    /**
     * process_lessonvideo_chprogress
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    protected function process_lessonvideo_chprogress($data): void {
        global $DB;
        $data = (object)$data;
        $data->chapterid = $this->get_new_parentid('lessonvideo_chapter');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        unset($data->id);
        $data->timecompleted = $data->timecompleted ? $this->apply_date_offset($data->timecompleted) : 0;
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $DB->insert_record('lessonvideo_chprogress', $data);
    }

    /**
     * process_lessonvideo_itemprogress
     *
     * @param $data
     * @return void
     * @throws dml_exception
     */
    protected function process_lessonvideo_itemprogress($data): void {
        global $DB;
        $data = (object)$data;
        $data->itemid = $this->get_new_parentid('lessonvideo_item');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->userid) {
            return;
        }
        unset($data->id);
        $data->timecompleted = $data->timecompleted ? $this->apply_date_offset($data->timecompleted) : 0;
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $DB->insert_record('lessonvideo_itemprogress', $data);
    }

    /**
     * Method after_execute.
     *
     * @return void Return value.
     */
    protected function after_execute(): void {
        $this->add_related_files('mod_lessonvideo', 'videofile', null);
        $this->add_related_files('mod_lessonvideo', 'captions', null);
        $this->add_related_files('mod_lessonvideo', 'itemfile', 'lessonvideo_item');
    }
}
