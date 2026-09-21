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
use moodle_url;
use stdClass;

/**
 * Prepares complementary chapter content for Mustache templates.
 *
 * @package mod_videolesson
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_presenter {
    /**
     * Builds one item for the student interface.
     *
     * @param stdClass $item Item record.
     * @param context_module $context Module context.
     * @param int $userid User id.
     * @return array
     */
    public static function build(stdClass $item, context_module $context, int $userid): array {
        global $DB;

        $progress = $DB->get_record('videolesson_itemprogress', [
            'itemid' => $item->id,
            'userid' => $userid,
        ]);
        $type = clean_param((string)$item->type, PARAM_ALPHA);
        $data = [
            'id' => (int)$item->id,
            'title' => format_string($item->title),
            'type' => $type,
            'required' => !empty($item->required),
            'completed' => !empty($progress->completed),
            'response' => $progress ? (string)$progress->response : '',
            'istext' => $type === 'text',
            'isimage' => $type === 'image',
            'ispdf' => $type === 'pdf',
            'isfile' => $type === 'file',
            'islink' => $type === 'link',
            'isquestion' => $type === 'question',
            'isactivity' => $type === 'activity',
            'html' => '',
            'url' => '',
            'filename' => '',
        ];

        if (in_array($type, ['text', 'question', 'activity'], true)) {
            $data['html'] = format_text((string)$item->content, (int)$item->contentformat, [
                'context' => $context,
                'para' => false,
            ]);
        }
        if ($type === 'link') {
            $data['url'] = clean_param((string)$item->url, PARAM_URL);
        }
        if (in_array($type, ['image', 'pdf', 'file'], true)) {
            $file = self::first_file($context, (int)$item->id);
            if ($file) {
                $data['filename'] = s($file->get_filename());
                $data['url'] = moodle_url::make_pluginfile_url(
                    $context->id,
                    'mod_videolesson',
                    'itemfile',
                    (int)$item->id,
                    $file->get_filepath(),
                    $file->get_filename(),
                    false
                )->out(false);
            }
        }
        return $data;
    }

    /**
     * Returns the first file attached to an item.
     *
     * @param context_module $context Module context.
     * @param int $itemid Item id.
     * @return stored_file|false
     */
    private static function first_file(context_module $context, int $itemid) {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_videolesson',
            'itemfile',
            $itemid,
            'filename',
            false
        );
        return $files ? reset($files) : false;
    }
}
