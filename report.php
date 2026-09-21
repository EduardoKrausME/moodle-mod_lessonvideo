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
 * Per-student, per-chapter progress report.
 *
 * @package   mod_videolesson
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videolesson\timecode;

require('../../config.php');

$id = required_param('id', PARAM_INT);
$resetuser = optional_param('resetuser', 0, PARAM_INT);

$cm = get_coursemodule_from_id('videolesson', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$activity = $DB->get_record('videolesson', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/videolesson:viewreport', $context);

$PAGE->set_url('/mod/videolesson/report.php', ['id' => $cm->id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('report', 'videolesson'));
$PAGE->set_heading(format_string($course->fullname));

if ($resetuser) {
    require_capability('mod/videolesson:resetprogress', $context);
    require_sesskey();
    $user = core_user::get_user($resetuser, '*', MUST_EXIST);
    if (!is_enrolled($context, $user, 'mod/videolesson:view', true)) {
        throw new moodle_exception('notenrolled', 'error');
    }
    $chapterids = $DB->get_fieldset_select('videolesson_chapters', 'id', 'videolessonid = ?', [$activity->id]);
    if ($chapterids) {
        [$insql, $chapterparams] = $DB->get_in_or_equal($chapterids, SQL_PARAMS_NAMED, 'ch');
        $itemids = $DB->get_fieldset_sql(
            "SELECT i.id FROM {videolesson_items} i WHERE i.chapterid {$insql}",
            $chapterparams
        );
        if ($itemids) {
            [$itemsql, $itemparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'it');
            $itemparams['userid'] = $resetuser;
            $DB->delete_records_select('videolesson_itemprogress', "itemid {$itemsql} AND userid = :userid", $itemparams);
        }
        $deletechapterparams = $chapterparams;
        $deletechapterparams['userid'] = $resetuser;
        $DB->delete_records_select(
            'videolesson_chprogress',
            "chapterid {$insql} AND userid = :userid",
            $deletechapterparams
        );
    }
    $DB->delete_records('videolesson_sessions', ['videolessonid' => $activity->id, 'userid' => $resetuser]);
    $DB->delete_records('videolesson_progress', ['videolessonid' => $activity->id, 'userid' => $resetuser]);
    videolesson_update_grades($activity, $resetuser, true);
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm)) {
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $resetuser);
    }
    redirect($PAGE->url, get_string('progressreset', 'videolesson'));
}

$chapters = array_values($DB->get_records(
    'videolesson_chapters',
    ['videolessonid' => $activity->id],
    'starttime ASC, sortorder ASC, id ASC'
));
$chapterheaders = [];
foreach ($chapters as $chapter) {
    $chapterheaders[] = [
        'title' => format_string($chapter->title),
        'time' => timecode::format((float)$chapter->starttime),
        'required' => !empty($chapter->required),
    ];
}

$users = get_enrolled_users($context, 'mod/videolesson:view', 0, 'u.*', 'u.lastname ASC, u.firstname ASC');
$rows = [];
foreach ($users as $user) {
    if (is_siteadmin($user) || has_capability('mod/videolesson:managechapters', $context, $user->id, false)) {
        continue;
    }
    $overall = $DB->get_record('videolesson_progress', [
        'videolessonid' => $activity->id,
        'userid' => $user->id,
    ]);
    $cells = [];
    foreach ($chapters as $chapter) {
        $cp = $DB->get_record('videolesson_chprogress', [
            'chapterid' => $chapter->id,
            'userid' => $user->id,
        ]);
        $percent = $cp ? (float)$cp->percent : 0.0;
        $completed = $cp && !empty($cp->completed);
        $status = $completed ? get_string('completed', 'videolesson')
            : ($percent > 0 ? get_string('inprogress', 'videolesson') : get_string('notstarted', 'videolesson'));
        $cells[] = [
            'percent' => round($percent, 2),
            'percentrounded' => (int)round($percent),
            'completed' => $completed,
            'inprogress' => !$completed && $percent > 0,
            'notstarted' => $percent <= 0,
            'status' => $status,
        ];
    }
    $overallpercent = $overall ? (float)$overall->percent : 0.0;
    $overallcompleted = $overall && !empty($overall->completed);
    $rows[] = [
        'fullname' => fullname($user),
        'profileurl' => (new moodle_url('/user/view.php', ['id' => $user->id, 'course' => $course->id]))->out(false),
        'percent' => round($overallpercent, 2),
        'percentrounded' => (int)round($overallpercent),
        'completed' => $overallcompleted,
        'status' => $overallcompleted ? get_string('completed', 'videolesson')
            : ($overallpercent > 0 ? get_string('inprogress', 'videolesson') : get_string('notstarted', 'videolesson')),
        'chapters' => $cells,
        'canreset' => has_capability('mod/videolesson:resetprogress', $context),
        'reseturl' => (new moodle_url('/mod/videolesson/report.php', [
            'id' => $cm->id,
            'resetuser' => $user->id,
            'sesskey' => sesskey(),
        ]))->out(false),
    ];
}

$data = [
    'name' => format_string($activity->name),
    'chapters' => $chapterheaders,
    'haschapters' => !empty($chapterheaders),
    'students' => $rows,
    'hasstudents' => !empty($rows),
    'viewurl' => (new moodle_url('/mod/videolesson/view.php', ['id' => $cm->id]))->out(false),
    'manageurl' => (new moodle_url('/mod/videolesson/manage.php', ['id' => $cm->id]))->out(false),
    'canmanage' => has_capability('mod/videolesson:managechapters', $context),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videolesson/report', $data);
echo $OUTPUT->footer();
