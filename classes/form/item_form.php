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

namespace mod_videolesson\form;

defined('MOODLE_INTERNAL') || die;

require_once("{$CFG->libdir}/formslib.php");

/**
 * Complementary chapter item form.
 *
 * @package mod_videolesson
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class item_form extends \moodleform {

    /**
     * Method definition.
     *
     * @return void Return value.
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'chapterid', $this->_customdata['chapterid']);
        $mform->setType('chapterid', PARAM_INT);
        $mform->addElement('hidden', 'itemid', $this->_customdata['itemid'] ?? 0);
        $mform->setType('itemid', PARAM_INT);

        $mform->addElement('select', 'type', get_string('itemtype', 'videolesson'), [
            'text' => get_string('itemtypetext', 'videolesson'),
            'image' => get_string('itemtypeimage', 'videolesson'),
            'pdf' => get_string('itemtypepdf', 'videolesson'),
            'file' => get_string('itemtypefile', 'videolesson'),
            'link' => get_string('itemtypelink', 'videolesson'),
            'question' => get_string('itemtypequestion', 'videolesson'),
            'activity' => get_string('itemtypeactivity', 'videolesson'),
        ]);
        $mform->setDefault('type', 'text');
        $mform->addElement('text', 'title', get_string('itemtitle', 'videolesson'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');

        $mform->addElement('editor', 'content_editor', get_string('itemcontent', 'videolesson'), null, [
            'maxfiles' => 0,
            'noclean' => false,
        ]);
        $mform->hideIf('content_editor', 'type', 'in', ['image', 'pdf', 'file', 'link']);

        $mform->addElement('url', 'url', get_string('itemurl', 'videolesson'), ['size' => 60], ['usefilepicker' => false]);
        $mform->setType('url', PARAM_URL);
        $mform->hideIf('url', 'type', 'neq', 'link');

        $mform->addElement('filemanager', 'itemfile', get_string('itemfile', 'videolesson'), null, [
            'subdirs' => 0,
            'maxfiles' => 1,
        ]);
        $mform->hideIf('itemfile', 'type', 'notin', ['image', 'pdf', 'file']);

        $mform->addElement('advcheckbox', 'required', get_string('itemrequired', 'videolesson'));
        $mform->addHelpButton('required', 'itemrequired', 'videolesson');
        $this->add_action_buttons(true, get_string('saveitem', 'videolesson'));
    }

    /**
     * Validates type-specific fields.
     *
     * @param array $data Form data.
     * @param array $files Files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $type = (string)($data['type'] ?? '');
        if ($type === 'link' && empty($data['url'])) {
            $errors['url'] = get_string('required');
        }
        $content = trim((string)($data['content_editor']['text'] ?? ''));
        if (in_array($type, ['text', 'question', 'activity'], true) && $content === '') {
            $errors['content_editor'] = get_string('required');
        }
        if (in_array($type, ['image', 'pdf', 'file'], true)) {
            $draftid = (int)($data['itemfile'] ?? 0);
            $draftinfo = $draftid ? file_get_draft_area_info($draftid) : ['filecount' => 0];
            if (empty($draftinfo['filecount'])) {
                $errors['itemfile'] = get_string('itemfilerequired', 'videolesson');
            }
        }
        return $errors;
    }
}
