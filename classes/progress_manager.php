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

namespace mod_videolesson;

use context_module;
use stdClass;

/**
 * Server-authoritative progress calculator for lessons and chapters.
 *
 * @package mod_videolesson
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_manager {
    /**
     * Maximum heartbeat interval accepted for adding watched time.
     */
    private const MAX_INTERVAL = 30.0;
    /**
     * Playback timing tolerance.
     */
    private const TOLERANCE = 3.0;

    /**
     * Returns or creates the user's lesson progress row.
     *
     * @param int $lessonid Lesson id.
     * @param int $userid User id.
     * @return stdClass
     */
    public function get_progress(int $lessonid, int $userid): stdClass {
        global $DB;
        $record = $DB->get_record('videolesson_progress', ['videolessonid' => $lessonid, 'userid' => $userid]);
        if ($record) {
            return $record;
        }
        return (object)[
            'id' => 0,
            'videolessonid' => $lessonid,
            'userid' => $userid,
            'duration' => 0,
            'lastposition' => 0,
            'totalwatchtime' => 0,
            'watchedsegments' => '[]',
            'percent' => 0,
            'completed' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
    }

    /**
     * Validates and stores a playback heartbeat.
     *
     * @param stdClass $activity Lesson record.
     * @param stdClass $cm Course module.
     * @param int $userid User id.
     * @param array $data Heartbeat data.
     * @return array Current client state.
     */
    public function update(stdClass $activity, stdClass $cm, int $userid, array $data): array {
        global $DB;
        $now = time();
        $duration = max(0.0, (float)$data['duration']);
        $position = max(0.0, min($duration ?: PHP_FLOAT_MAX, (float)$data['currentposition']));
        $start = max(0.0, (float)$data['segmentstart']);
        $end = max($start, (float)$data['segmentend']);
        $sequence = max(1, (int)$data['sequence']);
        $clienttime = max(0, (int)$data['clienttime']);
        $rate = max(0.25, min(4.0, (float)$data['playbackrate']));
        $sessionkey = clean_param((string)$data['sessionkey'], PARAM_ALPHANUMEXT);
        if ($sessionkey === '') {
            $sessionkey = sha1($userid . ':' . $activity->id . ':' . sesskey());
        }

        $progress = $this->get_progress((int)$activity->id, $userid);
        $session = $DB->get_record('videolesson_sessions', [
            'videolessonid' => $activity->id,
            'userid' => $userid,
            'sessionkey' => $sessionkey,
        ]);

        if ($session && $sequence <= (int)$session->sequence) {
            $state = $this->recalculate($activity, $userid, $progress);
            $state['seekto'] = (float)$session->lastposition;
            $state['accepted'] = false;
            $state['watchtime'] = 0.0;
            return $state;
        }

        $maxrate = (float)$activity->maxplaybackrate;
        if ($maxrate > 0 && $rate > $maxrate) {
            $rate = $maxrate;
        }

        // Trust server elapsed time, not a client-supplied clock, when deciding how much
        // video content can be credited. The client timestamp is retained only as session
        // telemetry. This prevents rapid forged heartbeats from manufacturing watch time.
        $elapsed = $session
            ? max(0.0, min(self::MAX_INTERVAL, (float)($now - (int)$session->lastheartbeat)))
            : 0.0;
        $allowedlength = $session ? ($elapsed * $rate + 1.0) : 0.0;
        $segments = $this->decode_segments($progress->watchedsegments);

        // Sequential chapter locks are authoritative on the server.
        $maxallowed = $this->get_unlocked_max($activity, $userid, $duration);
        if ($maxallowed !== null && $position > $maxallowed + self::TOLERANCE) {
            $position = max(0.0, $maxallowed);
        }
        $end = min($end, $duration > 0 ? $duration : $end);
        if ($maxallowed !== null) {
            $end = min($end, $maxallowed);
        }

        // A claimed watched interval must start at the last server-accepted position or
        // inside content already watched. Seeking may move the current position, but it
        // must never turn the skipped distance into watched content.
        $anchored = false;
        if ($session) {
            $anchored = abs($start - (float)$session->lastposition) <= self::TOLERANCE + $rate
                || $this->contains_position($segments, $start, self::TOLERANCE);
        }

        $acceptedsegment = null;
        if ($session && $anchored && $end > $start && $allowedlength > 0.05) {
            $acceptedend = min($end, $start + $allowedlength, $position + self::TOLERANCE);
            if ($acceptedend > $start + 0.05) {
                $acceptedsegment = [$start, $acceptedend];
            }
        }

        if (!$activity->allowseek) {
            $alreadywatched = $this->contains_position($segments, $position, 0.5);
            $legitimateend = $session
                ? (float)$session->lastposition + max(1.0, $allowedlength)
                : $this->furthest_watched($progress->watchedsegments);
            if ($position > $legitimateend + 0.5 && !$alreadywatched) {
                $position = $session
                    ? (float)$session->lastposition
                    : $this->furthest_watched($progress->watchedsegments);
                $acceptedsegment = null;
            }
        }

        // Close only the tiny final player timing gap after a genuine ended event.
        if ($data['playerstate'] === 'ended' && $acceptedsegment && $duration > 0
            && $position >= $duration - 2.0 && $acceptedsegment[1] >= $duration - 2.0) {
            $acceptedsegment[1] = $duration;
            $position = $duration;
        }

        $watchtime = 0.0;
        if ($acceptedsegment) {
            $segments = $this->merge_segments($segments, $acceptedsegment);
            $progress->watchedsegments = json_encode($segments, JSON_UNESCAPED_SLASHES);
            $contentseconds = $acceptedsegment[1] - $acceptedsegment[0];
            $watchtime = min($elapsed + 1.0, $contentseconds / max(0.25, $rate));
            $progress->totalwatchtime = (float)$progress->totalwatchtime + $watchtime;
        }

        $progress->duration = max((float)$progress->duration, $duration);
        $progress->lastposition = $position;
        $progress->timemodified = $now;
        if (empty($progress->id)) {
            $progress->timecreated = $now;
            $progress->id = $DB->insert_record('videolesson_progress', $progress);
        } else {
            $DB->update_record('videolesson_progress', $progress);
        }

        if (!$session) {
            $session = (object)[
                'videolessonid' => $activity->id,
                'userid' => $userid,
                'sessionkey' => $sessionkey,
                'sequence' => $sequence,
                'lastposition' => $position,
                'lastclienttime' => $clienttime,
                'lastheartbeat' => $now,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $session->id = $DB->insert_record('videolesson_sessions', $session);
        } else {
            $session->sequence = $sequence;
            $session->lastposition = $position;
            $session->lastclienttime = $clienttime;
            $session->lastheartbeat = $now;
            $session->timemodified = $now;
            $DB->update_record('videolesson_sessions', $session);
        }

        $state = $this->recalculate($activity, $userid, $progress);
        $this->update_moodle_completion($activity, $cm, $userid, $state['completed']);
        if (function_exists('videolesson_update_grades')) {
            videolesson_update_grades($activity, $userid, false);
        }
        $state['seekto'] = $position;
        $state['accepted'] = true;
        $state['watchtime'] = $watchtime;
        return $state;
    }

    /**
     * Recalculates every chapter and overall completion.
     *
     * @param stdClass $activity Lesson record.
     * @param int $userid User id.
     * @param stdClass|null $progress Existing progress.
     * @return array
     */
    public function recalculate(stdClass $activity, int $userid, ?stdClass $progress = null): array {
        global $DB;
        $progress = $progress ?? $this->get_progress((int)$activity->id, $userid);
        $chapters = array_values($DB->get_records('videolesson_chapters',
            ['videolessonid' => $activity->id], 'starttime ASC, sortorder ASC, id ASC'));
        $segments = $this->decode_segments($progress->watchedsegments);
        $chapterstates = [];
        $weightedwatched = 0.0;
        $weightedtotal = 0.0;
        $allrequired = true;
        $requiredcount = 0;

        foreach ($chapters as $index => $chapter) {
            $start = max(0.0, (float)$chapter->starttime);
            $end = isset($chapters[$index + 1]) ?
                max($start, (float)$chapters[$index + 1]->starttime) : max($start, (float)$progress->duration);
            $length = max(0.0, $end - $start);
            $watched = $this->intersection_length($segments, $start, $end);
            $percent = $length > 0 ? min(100.0, ($watched / $length) * 100.0) : 0.0;
            $requireditemsok = $this->required_items_complete((int)$chapter->id, $userid);
            $complete = $percent + 0.01 >= (float)$activity->chapterpercent && $requireditemsok;
            $this->store_chapter_progress($chapter, $userid, $watched, $percent, $complete);

            if (!empty($chapter->required)) {
                $requiredcount++;
                if (!$complete) {
                    $allrequired = false;
                }
            }
            if ($length > 0) {
                $weightedwatched += $watched;
                $weightedtotal += $length;
            }
            $chapterstates[] = [
                'id' => (int)$chapter->id,
                'title' => format_string($chapter->title),
                'starttime' => (float)$chapter->starttime,
                'percent' => round($percent, 2),
                'percentrounded' => (int)round($percent),
                'completed' => $complete,
                'inprogress' => !$complete && $percent > 0,
                'notstarted' => $percent <= 0,
                'required' => !empty($chapter->required),
                'locknext' => !empty($chapter->locknext),
                'itemscomplete' => $requireditemsok,
            ];
        }

        $overall = $weightedtotal > 0 ? min(100.0, ($weightedwatched / $weightedtotal) * 100.0) : 0.0;
        if (!$chapters && (float)$progress->duration > 0) {
            $overall = min(100.0,
                ($this->intersection_length($segments, 0, (float)$progress->duration) / (float)$progress->duration) * 100.0);
        }
        $completed = $activity->completionmode === 'percent'
            ? $overall + 0.01 >= (float)$activity->completionpercent
            : ($requiredcount > 0 && $allrequired);
        $progress->percent = round($overall, 2);
        $progress->completed = $completed ? 1 : 0;
        $progress->timemodified = time();
        if (!empty($progress->id)) {
            $DB->update_record('videolesson_progress', $progress);
        }

        $unlockedmax = $this->get_unlocked_max($activity, $userid, (float)$progress->duration);
        return [
            'percent' => round($overall, 2),
            'percentrounded' => (int)round($overall),
            'completed' => $completed,
            'chapters' => $chapterstates,
            'unlockedmax' => $unlockedmax === null ? -1.0 : (float)$unlockedmax,
            'lastposition' => (float)$progress->lastposition,
        ];
    }

    /**
     * Marks a content item complete and stores optional response text.
     *
     * @param stdClass $activity Lesson record.
     * @param stdClass $cm Course module.
     * @param int $itemid Item id.
     * @param int $userid User id.
     * @param string $response Optional response.
     * @return array
     */
    public function complete_item(stdClass $activity, stdClass $cm, int $itemid, int $userid, string $response): array {
        global $DB;
        $item = $DB->get_record('videolesson_items', ['id' => $itemid], '*', MUST_EXIST);
        $chapter = $DB->get_record('videolesson_chapters', ['id' => $item->chapterid], '*', MUST_EXIST);
        if ((int)$chapter->videolessonid !== (int)$activity->id) {
            throw new \moodle_exception('invaliditem', 'videolesson');
        }
        $progress = $this->get_progress((int)$activity->id, $userid);
        $unlockedmax = $this->get_unlocked_max($activity, $userid, (float)$progress->duration);
        if ($unlockedmax !== null && (float)$chapter->starttime > $unlockedmax + 0.1) {
            throw new \moodle_exception('chapterlocked', 'videolesson');
        }
        $response = trim(clean_param($response, PARAM_TEXT));
        if ($item->type === 'question' && $response === '') {
            throw new \moodle_exception('questionresponseempty', 'videolesson');
        }
        $record = $DB->get_record('videolesson_itemprogress', ['itemid' => $itemid, 'userid' => $userid]);
        $now = time();
        if (!$record) {
            $record = (object)[
                'itemid' => $itemid,
                'userid' => $userid,
                'completed' => 1,
                'response' => $response,
                'timecompleted' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('videolesson_itemprogress', $record);
        } else {
            $record->completed = 1;
            $record->response = $response;
            $record->timecompleted = $record->timecompleted ?: $now;
            $record->timemodified = $now;
            $DB->update_record('videolesson_itemprogress', $record);
        }
        $state = $this->recalculate($activity, $userid);
        $this->update_moodle_completion($activity, $cm, $userid, $state['completed']);
        return $state;
    }

    /**
     * Returns maximum seek position unlocked by sequential chapter rules.
     *
     * @param stdClass $activity Lesson record.
     * @param int $userid User id.
     * @param float $duration Video duration.
     * @return float|null Null means no chapter lock.
     */
    public function get_unlocked_max(stdClass $activity, int $userid, float $duration): ?float {
        global $DB;
        $chapters = array_values($DB->get_records('videolesson_chapters',
            ['videolessonid' => $activity->id], 'starttime ASC, sortorder ASC, id ASC'));
        if (!$chapters) {
            return null;
        }
        foreach ($chapters as $index => $chapter) {
            if (empty($chapter->locknext)) {
                continue;
            }
            $cp = $DB->get_record('videolesson_chprogress', ['chapterid' => $chapter->id, 'userid' => $userid]);
            if (!$cp || empty($cp->completed)) {
                if (isset($chapters[$index + 1])) {
                    return max(0.0, (float)$chapters[$index + 1]->starttime - 0.05);
                }
                return $duration > 0 ? $duration : null;
            }
        }
        return null;
    }

    /**
     * Returns whether every required item in a chapter is complete.
     *
     * @param int $chapterid Chapter id.
     * @param int $userid User id.
     * @return bool
     */
    private function required_items_complete(int $chapterid, int $userid): bool {
        global $DB;
        $items = $DB->get_records('videolesson_items', ['chapterid' => $chapterid, 'required' => 1]);
        foreach ($items as $item) {
            if (!$DB->record_exists('videolesson_itemprogress', ['itemid' => $item->id, 'userid' => $userid, 'completed' => 1])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Stores chapter progress.
     *
     * @param stdClass $chapter Chapter record.
     * @param int $userid User id.
     * @param float $watched Watched seconds.
     * @param float $percent Percentage.
     * @param bool $complete Completion state.
     * @return void
     */
    private function store_chapter_progress(stdClass $chapter, int $userid, float $watched, float $percent, bool $complete): void {
        global $DB;
        $record = $DB->get_record('videolesson_chprogress', ['chapterid' => $chapter->id, 'userid' => $userid]);
        $now = time();
        if (!$record) {
            $record = (object)[
                'chapterid' => $chapter->id,
                'userid' => $userid,
                'watchedseconds' => $watched,
                'percent' => $percent,
                'completed' => $complete ? 1 : 0,
                'timecompleted' => $complete ? $now : 0,
                'timemodified' => $now,
            ];
            $DB->insert_record('videolesson_chprogress', $record);
        } else {
            $wascomplete = !empty($record->completed);
            $record->watchedseconds = $watched;
            $record->percent = $percent;
            $record->completed = $complete ? 1 : 0;
            if ($complete && !$wascomplete) {
                $record->timecompleted = $now;
            }
            $record->timemodified = $now;
            $DB->update_record('videolesson_chprogress', $record);
        }
    }

    /**
     * Updates Moodle completion state.
     *
     * @param stdClass $activity Activity record.
     * @param stdClass $cm Course module.
     * @param int $userid User id.
     * @param bool $completed Completion state.
     * @return void
     */
    private function update_moodle_completion(stdClass $activity, stdClass $cm, int $userid, bool $completed): void {
        global $DB;
        $course = $DB->get_record('course', ['id' => $activity->course], '*', MUST_EXIST);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, $completed ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE, $userid);
        }
    }

    /**
     * Decodes stored segments.
     *
     * @param string|null $json JSON segments.
     * @return array
     */
    private function decode_segments(?string $json): array {
        $segments = json_decode((string)$json, true);
        if (!is_array($segments)) {
            return [];
        }
        $valid = [];
        foreach ($segments as $segment) {
            if (is_array($segment) && count($segment) === 2 && is_numeric($segment[0]) && is_numeric($segment[1])) {
                $start = max(0.0, (float)$segment[0]);
                $end = max($start, (float)$segment[1]);
                if ($end > $start) {
                    $valid[] = [$start, $end];
                }
            }
        }
        return $this->merge_segments($valid);
    }

    /**
     * Merges overlapping intervals.
     *
     * @param array $segments Existing intervals.
     * @param array|null $append Optional interval to add.
     * @return array
     */
    private function merge_segments(array $segments, ?array $append = null): array {
        if ($append) {
            $segments[] = [(float)$append[0], (float)$append[1]];
        }
        usort($segments, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($segments as $segment) {
            if (!$merged || $segment[0] > $merged[count($merged) - 1][1] + 0.25) {
                $merged[] = $segment;
            } else {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $segment[1]);
            }
        }
        return $merged;
    }

    /**
     * Measures watched interval intersection.
     *
     * @param array $segments Watched segments.
     * @param float $start Range start.
     * @param float $end Range end.
     * @return float
     */
    private function intersection_length(array $segments, float $start, float $end): float {
        $total = 0.0;
        foreach ($segments as $segment) {
            $a = max($start, (float)$segment[0]);
            $b = min($end, (float)$segment[1]);
            if ($b > $a) {
                $total += $b - $a;
            }
        }
        return $total;
    }

    /**
     * Returns whether a position is inside an already accepted segment.
     *
     * @param array $segments Watched segments.
     * @param float $position Position in seconds.
     * @param float $tolerance Accepted edge tolerance.
     * @return bool
     */
    private function contains_position(array $segments, float $position, float $tolerance = 0.0): bool {
        foreach ($segments as $segment) {
            if ($position >= (float)$segment[0] - $tolerance && $position <= (float)$segment[1] + $tolerance) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns furthest watched second.
     *
     * @param string|null $json Stored watched segments.
     * @return float
     */
    private function furthest_watched(?string $json): float {
        $max = 0.0;
        foreach ($this->decode_segments($json) as $segment) {
            $max = max($max, (float)$segment[1]);
        }
        return $max;
    }
}
