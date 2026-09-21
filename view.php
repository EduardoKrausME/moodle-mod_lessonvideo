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
 * Student view for Video Lesson.
 *
 * @package   mod_videolesson
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videolesson\item_presenter;
use mod_videolesson\player_config;
use mod_videolesson\progress_manager;
use mod_videolesson\timecode;

require('../../config.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('videolesson', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$activity = $DB->get_record('videolesson', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/videolesson:view', $context);

$PAGE->set_url('/mod/videolesson/view.php', ['id' => $cm->id]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(format_string($course->fullname));

$completion = new completion_info($course);
if ($completion->is_enabled($cm)) {
    $completion->set_module_viewed($cm);
}
$event = \mod_videolesson\event\course_module_viewed::create([
    'objectid' => $activity->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('videolesson', $activity);
$event->trigger();

$manager = new progress_manager();
$progress = $manager->get_progress((int)$activity->id, $USER->id);
$state = $manager->recalculate($activity, $USER->id, $progress);
$player = player_config::build($activity, $context);
$chapters = array_values($DB->get_records(
    'videolesson_chapters',
    ['videolessonid' => $activity->id],
    'starttime ASC, sortorder ASC, id ASC'
));
$states = [];
foreach ($state['chapters'] as $chapterstate) {
    $states[(int)$chapterstate['id']] = $chapterstate;
}

$currentid = 0;
foreach ($chapters as $chapter) {
    if ((float)$chapter->starttime <= (float)$progress->lastposition + 0.01) {
        $currentid = (int)$chapter->id;
    }
}
if (!$currentid && $chapters) {
    $currentid = (int)$chapters[0]->id;
}

$chapterdata = [];
foreach ($chapters as $chapter) {
    $chapterstate = $states[(int)$chapter->id] ?? [
        'percent' => 0,
        'percentrounded' => 0,
        'completed' => false,
        'inprogress' => false,
        'notstarted' => true,
        'itemscomplete' => true,
    ];
    $locked = (float)$state['unlockedmax'] >= 0
        && (float)$chapter->starttime > (float)$state['unlockedmax'] + 0.1;
    $items = $DB->get_records('videolesson_items', ['chapterid' => $chapter->id], 'sortorder ASC, id ASC');
    $itemdata = [];
    foreach ($items as $item) {
        $itemdata[] = item_presenter::build($item, $context, $USER->id);
    }
    $status = get_string('notstarted', 'videolesson');
    if (!empty($chapterstate['completed'])) {
        $status = get_string('completed', 'videolesson');
    } else if (!empty($chapterstate['inprogress'])) {
        $status = get_string('inprogress', 'videolesson');
    }
    if ($locked) {
        $status = get_string('locked', 'videolesson');
    }
    $chapterdata[] = [
        'id' => (int)$chapter->id,
        'title' => format_string($chapter->title),
        'start' => timecode::format((float)$chapter->starttime),
        'startseconds' => (float)$chapter->starttime,
        'required' => !empty($chapter->required),
        'locknext' => !empty($chapter->locknext),
        'percent' => (float)$chapterstate['percent'],
        'percentrounded' => (int)$chapterstate['percentrounded'],
        'completed' => !empty($chapterstate['completed']),
        'inprogress' => !empty($chapterstate['inprogress']),
        'notstarted' => !empty($chapterstate['notstarted']),
        'itemscomplete' => !empty($chapterstate['itemscomplete']),
        'locked' => $locked,
        'current' => (int)$chapter->id === $currentid,
        'status' => $status,
        'items' => $itemdata,
        'hasitems' => !empty($itemdata),
    ];
}

$config = [
    'cmid' => (int)$cm->id,
    'source' => (string)$player['source'],
    'videoid' => (string)$player['videoid'],
    'url' => (string)$player['url'],
    'lastposition' => (float)$progress->lastposition,
    'resumeplayback' => !empty($activity->resumeplayback),
    'allowseek' => !empty($activity->allowseek),
    'maxplaybackrate' => (float)$activity->maxplaybackrate,
    'unlockedmax' => (float)$state['unlockedmax'],
    'chapters' => array_map(static function (array $chapter): array {
        return [
            'id' => $chapter['id'],
            'title' => $chapter['title'],
            'start' => $chapter['startseconds'],
            'locked' => $chapter['locked'],
        ];
    }, $chapterdata),
];

$templatedata = [
    'name' => format_string($activity->name),
    'intro' => trim((string)$activity->intro) !== '' ? format_module_intro('videolesson', $activity, $cm->id) : '',
    'hasintro' => trim((string)$activity->intro) !== '',
    'player' => $player,
    'chapters' => $chapterdata,
    'haschapters' => !empty($chapterdata),
    'progress' => [
        'percent' => (float)$state['percent'],
        'percentrounded' => (int)$state['percentrounded'],
        'completed' => !empty($state['completed']),
    ],
    'configjson' => json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
    'canmanage' => has_capability('mod/videolesson:managechapters', $context),
    'manageurl' => (new moodle_url('/mod/videolesson/manage.php', ['id' => $cm->id]))->out(false),
    'canviewreport' => has_capability('mod/videolesson:viewreport', $context),
    'reporturl' => (new moodle_url('/mod/videolesson/report.php', ['id' => $cm->id]))->out(false),
];

$PAGE->requires->strings_for_js([
    'completed', 'inprogress', 'notstarted', 'locked', 'trackingerror', 'seekblocked', 'itemcomplete', 'itemerror',
], 'videolesson');
$PAGE->requires->js_call_amd('mod_videolesson/player', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videolesson/view', $templatedata);
echo $OUTPUT->footer();
