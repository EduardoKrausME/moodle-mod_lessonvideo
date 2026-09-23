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

namespace mod_lessonvideo\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API implementation for Video Lesson.
 *
 * @package mod_lessonvideo
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describes stored personal data.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('lessonvideo_progress', [
            'userid' => 'privacy:metadata:lessonvideo_progress:userid',
            'lastposition' => 'privacy:metadata:lessonvideo_progress:lastposition',
            'totalwatchtime' => 'privacy:metadata:lessonvideo_progress:totalwatchtime',
            'watchedsegments' => 'privacy:metadata:lessonvideo_progress:watchedsegments',
            'percent' => 'privacy:metadata:lessonvideo_progress:percent',
            'completed' => 'privacy:metadata:lessonvideo_progress:completed',
        ], 'privacy:metadata:lessonvideo_progress');
        $collection->add_database_table('lessonvideo_chprogress', [
            'userid' => 'privacy:metadata:lessonvideo_chprogress',
            'watchedseconds' => 'privacy:metadata:lessonvideo_chprogress',
            'percent' => 'privacy:metadata:lessonvideo_chprogress',
            'completed' => 'privacy:metadata:lessonvideo_chprogress',
        ], 'privacy:metadata:lessonvideo_chprogress');
        $collection->add_database_table('lessonvideo_itemprogress', [
            'userid' => 'privacy:metadata:lessonvideo_itemprogress',
            'completed' => 'privacy:metadata:lessonvideo_itemprogress',
            'response' => 'privacy:metadata:lessonvideo_itemprogress',
        ], 'privacy:metadata:lessonvideo_itemprogress');
        $collection->add_database_table('lessonvideo_sessions', [
            'userid' => 'privacy:metadata:lessonvideo_sessions',
            'lastposition' => 'privacy:metadata:lessonvideo_sessions',
            'lastclienttime' => 'privacy:metadata:lessonvideo_sessions',
            'lastheartbeat' => 'privacy:metadata:lessonvideo_sessions',
        ], 'privacy:metadata:lessonvideo_sessions');
        return $collection;
    }

    /**
     * Returns module contexts containing data for a user.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {lessonvideo} v ON v.id = cm.instance
                 WHERE ctx.contextlevel = :contextlevel
                   AND (
                       EXISTS (SELECT 1 FROM {lessonvideo_progress} p
                                WHERE p.lessonvideoid = v.id AND p.userid = :userid1)
                       OR EXISTS (SELECT 1 FROM {lessonvideo_chprogress} cp
                                   JOIN {lessonvideo_chapters} c ON c.id = cp.chapterid
                                  WHERE c.lessonvideoid = v.id AND cp.userid = :userid2)
                       OR EXISTS (SELECT 1 FROM {lessonvideo_itemprogress} ip
                                   JOIN {lessonvideo_items} i ON i.id = ip.itemid
                                   JOIN {lessonvideo_chapters} c2 ON c2.id = i.chapterid
                                  WHERE c2.lessonvideoid = v.id AND ip.userid = :userid3)
                       OR EXISTS (SELECT 1 FROM {lessonvideo_sessions} s
                                  WHERE s.lessonvideoid = v.id AND s.userid = :userid4)
                   )";
        $contextlist->add_from_sql($sql, [
            'modname' => 'lessonvideo',
            'contextlevel' => CONTEXT_MODULE,
            'userid1' => $userid,
            'userid2' => $userid,
            'userid3' => $userid,
            'userid4' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Exports a user's lesson progress.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('lessonvideo', $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $progress = $DB->get_record('lessonvideo_progress', [
                'lessonvideoid' => $cm->instance,
                'userid' => $userid,
            ]);
            $chapters = $DB->get_records_sql(
                'SELECT cp.*, c.title FROM {lessonvideo_chprogress} cp
                   JOIN {lessonvideo_chapters} c ON c.id = cp.chapterid
                  WHERE c.lessonvideoid = ? AND cp.userid = ? ORDER BY c.starttime, c.id',
                [$cm->instance, $userid]
            );
            $items = $DB->get_records_sql(
                'SELECT ip.*, i.title, i.type FROM {lessonvideo_itemprogress} ip
                   JOIN {lessonvideo_items} i ON i.id = ip.itemid
                   JOIN {lessonvideo_chapters} c ON c.id = i.chapterid
                  WHERE c.lessonvideoid = ? AND ip.userid = ? ORDER BY c.starttime, i.sortorder, i.id',
                [$cm->instance, $userid]
            );
            $sessions = $DB->get_records('lessonvideo_sessions', [
                'lessonvideoid' => $cm->instance,
                'userid' => $userid,
            ], 'timecreated ASC');
            writer::with_context($context)->export_data([get_string('privacy:path', 'lessonvideo')], (object)[
                'progress' => $progress ?: null,
                'chapters' => array_values($chapters),
                'content' => array_values($items),
                'sessions' => array_values($sessions),
            ]);
        }
    }

    /**
     * Deletes all user data in one activity context.
     *
     * @param context $context Context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        if (!$context instanceof context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('lessonvideo', $context->instanceid, 0, false, IGNORE_MISSING);
        if ($cm) {
            self::delete_lesson_user_data((int)$cm->instance, null);
        }
    }

    /**
     * Deletes one user's data in approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('lessonvideo', $context->instanceid, 0, false, IGNORE_MISSING);
            if ($cm) {
                self::delete_lesson_user_data((int)$cm->instance, $userid);
            }
        }
    }

    /**
     * Adds users with stored data in an activity context.
     *
     * @param userlist $userlist User list.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('lessonvideo', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $sql = "SELECT p.userid FROM {lessonvideo_progress} p WHERE p.lessonvideoid = :lesson1
                UNION SELECT cp.userid FROM {lessonvideo_chprogress} cp
                      JOIN {lessonvideo_chapters} c ON c.id = cp.chapterid WHERE c.lessonvideoid = :lesson2
                UNION SELECT ip.userid FROM {lessonvideo_itemprogress} ip
                      JOIN {lessonvideo_items} i ON i.id = ip.itemid
                      JOIN {lessonvideo_chapters} c2 ON c2.id = i.chapterid WHERE c2.lessonvideoid = :lesson3
                UNION SELECT s.userid FROM {lessonvideo_sessions} s WHERE s.lessonvideoid = :lesson4";
        $userlist->add_from_sql('userid', $sql, [
            'lesson1' => $cm->instance,
            'lesson2' => $cm->instance,
            'lesson3' => $cm->instance,
            'lesson4' => $cm->instance,
        ]);
    }

    /**
     * Deletes data for an approved set of users in one context.
     *
     * @param approved_userlist $userlist Approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('lessonvideo', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_lesson_user_data((int)$cm->instance, (int)$userid);
        }
    }

    /**
     * Deletes progress rows for a lesson, optionally restricted to one user.
     *
     * @param int $lessonid Lesson id.
     * @param int|null $userid User id or null for all users.
     * @return void
     */
    private static function delete_lesson_user_data(int $lessonid, ?int $userid): void {
        global $DB;
        $chapterids = $DB->get_fieldset_select('lessonvideo_chapters', 'id', 'lessonvideoid = ?', [$lessonid]);
        if ($chapterids) {
            [$chaptersql, $chapterparams] = $DB->get_in_or_equal($chapterids, SQL_PARAMS_NAMED, 'ch');
            $itemids = $DB->get_fieldset_sql(
                "SELECT id FROM {lessonvideo_items} WHERE chapterid {$chaptersql}",
                $chapterparams
            );
            if ($itemids) {
                [$itemsql, $itemparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'it');
                if ($userid === null) {
                    $DB->delete_records_select('lessonvideo_itemprogress', "itemid {$itemsql}", $itemparams);
                } else {
                    $itemparams['userid'] = $userid;
                    $DB->delete_records_select('lessonvideo_itemprogress', "itemid {$itemsql} AND userid = :userid", $itemparams);
                }
            }
            if ($userid === null) {
                $DB->delete_records_select('lessonvideo_chprogress', "chapterid {$chaptersql}", $chapterparams);
            } else {
                $chapterparams['userid'] = $userid;
                $DB->delete_records_select('lessonvideo_chprogress',
                    "chapterid {$chaptersql} AND userid = :userid", $chapterparams);
            }
        }
        if ($userid === null) {
            $DB->delete_records('lessonvideo_sessions', ['lessonvideoid' => $lessonid]);
            $DB->delete_records('lessonvideo_progress', ['lessonvideoid' => $lessonid]);
        } else {
            $DB->delete_records('lessonvideo_sessions', ['lessonvideoid' => $lessonid, 'userid' => $userid]);
            $DB->delete_records('lessonvideo_progress', ['lessonvideoid' => $lessonid, 'userid' => $userid]);
        }
    }
}
