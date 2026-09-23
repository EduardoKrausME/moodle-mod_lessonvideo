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

namespace mod_lessonvideo;

use context_module;
use moodle_url;
use stdClass;

/**
 * Builds player configuration from activity settings and protected files.
 *
 * @package mod_lessonvideo
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class player_config {
    /**
     * Builds browser-safe player data.
     *
     * @param stdClass $activity Activity record.
     * @param context_module $context Module context.
     * @return array
     */
    public static function build(stdClass $activity, context_module $context): array {
        $source = clean_param((string)$activity->videosource, PARAM_ALPHA);
        $data = [
            'source' => $source,
            'ishtml5' => in_array($source, ['upload', 'url'], true),
            'isyoutube' => $source === 'youtube',
            'isvimeo' => $source === 'vimeo',
            'url' => '',
            'videoid' => '',
            'tracks' => [],
        ];

        if ($source === 'upload') {
            $data['url'] = self::first_file_url($context, 'videofile');
        } else if ($source === 'url') {
            $data['url'] = clean_param((string)$activity->videourl, PARAM_URL);
        } else if ($source === 'youtube') {
            $data['videoid'] = self::youtube_id((string)$activity->videourl);
        } else if ($source === 'vimeo') {
            $data['videoid'] = self::vimeo_id((string)$activity->videourl);
        }

        if ($data['ishtml5']) {
            $data['tracks'] = self::caption_tracks($context);
        }
        return $data;
    }

    /**
     * Returns first protected file URL in an activity file area.
     *
     * @param context_module $context Module context.
     * @param string $filearea File area.
     * @return string
     */
    private static function first_file_url(context_module $context, string $filearea): string {
        $files = get_file_storage()->get_area_files($context->id, 'mod_lessonvideo', $filearea, 0, 'filename', false);
        if (!$files) {
            return '';
        }
        $file = reset($files);
        return moodle_url::make_pluginfile_url(
            $context->id,
            'mod_lessonvideo',
            $filearea,
            0,
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }

    /**
     * Returns WebVTT tracks stored for the lesson.
     *
     * @param context_module $context Module context.
     * @return array
     */
    private static function caption_tracks(context_module $context): array {
        $tracks = [];
        $files = get_file_storage()->get_area_files($context->id, 'mod_lessonvideo', 'captions', 0, 'filename', false);
        foreach ($files as $file) {
            $filename = $file->get_filename();
            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'vtt') {
                continue;
            }
            $basename = pathinfo($filename, PATHINFO_FILENAME);
            $parts = preg_split('/[-_.]/', $basename);
            $lang = clean_param((string)($parts[0] ?? 'en'), PARAM_ALPHANUMEXT);
            $tracks[] = [
                'src' => moodle_url::make_pluginfile_url(
                    $context->id,
                    'mod_lessonvideo',
                    'captions',
                    0,
                    $file->get_filepath(),
                    $filename
                )->out(false),
                'srclang' => $lang ?: 'en',
                'label' => $basename,
                'default' => empty($tracks),
            ];
        }
        return $tracks;
    }

    /**
     * Extracts a YouTube id.
     *
     * @param string $url Video URL.
     * @return string
     */
    public static function youtube_id(string $url): string {
        if (preg_match('~(?:youtu\\.be/|youtube\\.com/(?:watch\\?v=|embed/))([A-Za-z0-9_-]{6,})~', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Extracts a Vimeo id.
     *
     * @param string $url Video URL.
     * @return string
     */
    public static function vimeo_id(string $url): string {
        if (preg_match('~vimeo\\.com/(?:video/)?([0-9]+)~', $url, $m)) {
            return $m[1];
        }
        return '';
    }
}
