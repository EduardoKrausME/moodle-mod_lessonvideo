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

namespace mod_lessonvideo\form;

use mod_lessonvideo\timecode;

defined('MOODLE_INTERNAL') || die;

require_once("{$CFG->libdir}/formslib.php");

/**
 * Chapter editing form.
 *
 * @package mod_lessonvideo
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chapter_form extends \moodleform {

    /**
     * Method definition.
     *
     * @return void Return value.
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'chapterid', $this->_customdata['chapterid'] ?? 0);
        $mform->setType('chapterid', PARAM_INT);
        $mform->addElement('text', 'title', get_string('chaptertitle', 'lessonvideo'), ['size' => 60]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addElement('text', 'starttimecode', get_string('chapterstart', 'lessonvideo'), ['size' => 14]);
        $mform->setType('starttimecode', PARAM_TEXT);
        $mform->addHelpButton('starttimecode', 'chapterstart', 'lessonvideo');
        $mform->addRule('starttimecode', null, 'required', null, 'client');
        $mform->addElement('advcheckbox', 'required', get_string('chapterrequired', 'lessonvideo'));
        $mform->setDefault('required', 1);
        $mform->addElement('advcheckbox', 'locknext', get_string('chapterlocknext', 'lessonvideo'));
        $mform->addHelpButton('locknext', 'chapterlocknext', 'lessonvideo');
        $this->add_action_buttons(true, get_string('savechapter', 'lessonvideo'));
    }

    /**
     * Validates chapter timecode.
     *
     * @param array $data Form data.
     * @param array $files Files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (timecode::parse((string)($data['starttimecode'] ?? '')) === null) {
            $errors['starttimecode'] = get_string('invalidtimecode', 'lessonvideo');
        }
        return $errors;
    }
}
