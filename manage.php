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
 * Manages lesson chapters and complementary content.
 *
 * @package mod_videolesson
 * @copyright 2026 Eduardo Kraus
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

use mod_videolesson\form\chapter_form;
use mod_videolesson\form\item_form;
use mod_videolesson\timecode;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$chapterid = optional_param('chapterid', 0, PARAM_INT);
$itemid = optional_param('itemid', 0, PARAM_INT);
$deletechapter = optional_param('deletechapter', 0, PARAM_INT);
$deleteitem = optional_param('deleteitem', 0, PARAM_INT);

$cm = get_coursemodule_from_id('videolesson', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$activity = $DB->get_record('videolesson', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videolesson:managechapters', $context);

$PAGE->set_url('/mod/videolesson/manage.php', ['id' => $cm->id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('managechapters', 'videolesson'));
$PAGE->set_heading(format_string($course->fullname));

if ($deletechapter) {
    require_sesskey();
    $chapter = $DB->get_record('videolesson_chapters', ['id' => $deletechapter, 'videolessonid' => $activity->id], '*', MUST_EXIST);
    $items = $DB->get_records('videolesson_items', ['chapterid' => $chapter->id]);
    foreach ($items as $item) {
        $DB->delete_records('videolesson_itemprogress', ['itemid' => $item->id]);
        get_file_storage()->delete_area_files($context->id, 'mod_videolesson', 'itemfile', $item->id);
    }
    $DB->delete_records('videolesson_items', ['chapterid' => $chapter->id]);
    $DB->delete_records('videolesson_chprogress', ['chapterid' => $chapter->id]);
    $DB->delete_records('videolesson_chapters', ['id' => $chapter->id]);
    redirect($PAGE->url, get_string('chapterdeleted', 'videolesson'));
}

if ($deleteitem) {
    require_sesskey();
    $item = $DB->get_record_sql(
        'SELECT i.*
               FROM {videolesson_items} i
               JOIN {videolesson_chapters} c ON c.id = i.chapterid
              WHERE i.id = ?
                AND c.videolessonid = ?',
        [$deleteitem, $activity->id], MUST_EXIST
    );
    $DB->delete_records('videolesson_itemprogress', ['itemid' => $item->id]);
    $DB->delete_records('videolesson_items', ['id' => $item->id]);
    get_file_storage()->delete_area_files($context->id, 'mod_videolesson', 'itemfile', $item->id);
    redirect($PAGE->url, get_string('itemdeleted', 'videolesson'));
}

if ($action === 'chapter') {
    $chapter = $chapterid ? $DB->get_record('videolesson_chapters',
        ['id' => $chapterid, 'videolessonid' => $activity->id], '*', MUST_EXIST) : null;
    $form = new chapter_form(null, ['cmid' => $cm->id, 'chapterid' => $chapterid]);
    if ($form->is_cancelled()) {
        redirect($PAGE->url);
    }
    if ($data = $form->get_data()) {
        $start = timecode::parse($data->starttimecode);
        $record = (object)[
            'videolessonid' => $activity->id,
            'title' => $data->title,
            'starttime' => $start,
            'required' => !empty($data->required) ? 1 : 0,
            'locknext' => !empty($data->locknext) ? 1 : 0,
            'timemodified' => time(),
        ];
        if ($chapter) {
            $record->id = $chapter->id;
            $record->sortorder = $chapter->sortorder;
            $DB->update_record('videolesson_chapters', $record);
        } else {
            $record->sortorder = $DB->count_records('videolesson_chapters', ['videolessonid' => $activity->id]);
            $record->timecreated = time();
            $DB->insert_record('videolesson_chapters', $record);
        }
        redirect($PAGE->url, get_string('chaptersaved', 'videolesson'));
    }
    if ($chapter) {
        $form->set_data((object)[
            'id' => $cm->id,
            'chapterid' => $chapter->id,
            'title' => $chapter->title,
            'starttimecode' => timecode::format((float)$chapter->starttime),
            'required' => $chapter->required,
            'locknext' => $chapter->locknext,
        ]);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading($chapter ? get_string('editchapter', 'videolesson') : get_string('addchapter', 'videolesson'));
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'item') {
    $chapter = $DB->get_record('videolesson_chapters', ['id' => $chapterid, 'videolessonid' => $activity->id], '*', MUST_EXIST);
    $item = $itemid ? $DB->get_record('videolesson_items', ['id' => $itemid, 'chapterid' => $chapter->id], '*', MUST_EXIST) : null;
    $form = new item_form(null, ['cmid' => $cm->id, 'chapterid' => $chapter->id, 'itemid' => $itemid]);
    if ($form->is_cancelled()) {
        redirect($PAGE->url);
    }
    if ($data = $form->get_data()) {
        $record = (object)[
            'chapterid' => $chapter->id,
            'type' => $data->type,
            'title' => $data->title,
            'content' => $data->content_editor['text'] ?? '',
            'contentformat' => $data->content_editor['format'] ?? FORMAT_HTML,
            'url' => $data->url ?? '',
            'required' => !empty($data->required) ? 1 : 0,
            'timemodified' => time(),
        ];
        if ($item) {
            $record->id = $item->id;
            $record->sortorder = $item->sortorder;
            $DB->update_record('videolesson_items', $record);
            $savedid = $item->id;
        } else {
            $record->sortorder = $DB->count_records('videolesson_items', ['chapterid' => $chapter->id]);
            $record->timecreated = time();
            $savedid = $DB->insert_record('videolesson_items', $record);
        }
        if (isset($data->itemfile)) {
            file_save_draft_area_files((int)$data->itemfile, $context->id,
                'mod_videolesson', 'itemfile', $savedid, ['subdirs' => 0, 'maxfiles' => 1]);
        }
        redirect($PAGE->url, get_string('itemsaved', 'videolesson'));
    }
    if ($item) {
        $draftid = file_get_submitted_draft_itemid('itemfile');
        file_prepare_draft_area($draftid, $context->id, 'mod_videolesson', 'itemfile', $item->id, ['subdirs' => 0]);
        $form->set_data((object)[
            'id' => $cm->id,
            'chapterid' => $chapter->id,
            'itemid' => $item->id,
            'type' => $item->type,
            'title' => $item->title,
            'content_editor' => ['text' => $item->content, 'format' => $item->contentformat],
            'url' => $item->url,
            'required' => $item->required,
            'itemfile' => $draftid,
        ]);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading($item ? get_string('edititem', 'videolesson') : get_string('additem', 'videolesson'));
    echo $OUTPUT->heading(format_string($chapter->title), 3);
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

$chapters = array_values($DB->get_records('videolesson_chapters',
    ['videolessonid' => $activity->id], 'starttime ASC, sortorder ASC, id ASC'));
$rows = [];
foreach ($chapters as $chapter) {
    $items = $DB->get_records('videolesson_items', ['chapterid' => $chapter->id], 'sortorder ASC, id ASC');
    $itemdata = [];
    foreach ($items as $item) {
        $itemdata[] = [
            'title' => format_string($item->title),
            'type' => get_string('itemtype' . $item->type, 'videolesson'),
            'required' => !empty($item->required),
            'editurl' => (new moodle_url('/mod/videolesson/manage.php', [
                'id' => $cm->id, 'action' => 'item', 'chapterid' => $chapter->id, 'itemid' => $item->id,
            ]))->out(false),
            'deleteurl' => (new moodle_url('/mod/videolesson/manage.php', [
                'id' => $cm->id, 'deleteitem' => $item->id, 'sesskey' => sesskey(),
            ]))->out(false),
        ];
    }
    $rows[] = [
        'id' => $chapter->id,
        'title' => format_string($chapter->title),
        'start' => timecode::format((float)$chapter->starttime),
        'required' => !empty($chapter->required),
        'locknext' => !empty($chapter->locknext),
        'items' => $itemdata,
        'hasitems' => !empty($itemdata),
        'editurl' => (new moodle_url('/mod/videolesson/manage.php',
            ['id' => $cm->id, 'action' => 'chapter', 'chapterid' => $chapter->id]))->out(false),
        'deleteurl' => (new moodle_url('/mod/videolesson/manage.php',
            ['id' => $cm->id, 'deletechapter' => $chapter->id, 'sesskey' => sesskey()]))->out(false),
        'additemurl' => (new moodle_url('/mod/videolesson/manage.php',
            ['id' => $cm->id, 'action' => 'item', 'chapterid' => $chapter->id]))->out(false),
    ];
}
$data = [
    'name' => format_string($activity->name),
    'chapters' => $rows,
    'haschapters' => !empty($rows),
    'addchapterurl' => (new moodle_url('/mod/videolesson/manage.php', ['id' => $cm->id, 'action' => 'chapter']))->out(false),
    'viewurl' => (new moodle_url('/mod/videolesson/view.php', ['id' => $cm->id]))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videolesson/manage', $data);
echo $OUTPUT->footer();
