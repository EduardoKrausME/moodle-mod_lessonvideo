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
 * Activity settings form for Video Lesson.
 *
 * @package   mod_videolesson
 * @copyright 2026 Eduardo Kraus
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Video Lesson activity form.
 */
class mod_videolesson_mod_form extends moodleform_mod {
    /**
     * Defines activity fields.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('videolessonname', 'videolesson'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $this->standard_intro_elements();

        $mform->addElement('header', 'videosettings', get_string('videosettings', 'videolesson'));
        $mform->addElement('select', 'videosource', get_string('videosource', 'videolesson'), [
            'upload' => get_string('sourceupload', 'videolesson'),
            'url' => get_string('sourceurl', 'videolesson'),
            'youtube' => get_string('sourceyoutube', 'videolesson'),
            'vimeo' => get_string('sourcevimeo', 'videolesson'),
        ]);
        $mform->setDefault('videosource', 'upload');

        $mform->addElement('filemanager', 'videofile', get_string('videofile', 'videolesson'), null, [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['video'],
        ]);
        $mform->hideIf('videofile', 'videosource', 'neq', 'upload');

        $mform->addElement('url', 'videourl', get_string('videourl', 'videolesson'), ['size' => 64], ['usefilepicker' => false]);
        $mform->setType('videourl', PARAM_URL);
        $mform->hideIf('videourl', 'videosource', 'eq', 'upload');

        $mform->addElement('filemanager', 'captions', get_string('captions', 'videolesson'), null, [
            'subdirs' => 0,
            'maxfiles' => 20,
            'accepted_types' => ['.vtt'],
        ]);
        $mform->addHelpButton('captions', 'captions', 'videolesson');

        $mform->addElement('select', 'resumeplayback', get_string('resumeplayback', 'videolesson'), [
            1 => get_string('resumeautomatic', 'videolesson'),
            0 => get_string('resumefromstart', 'videolesson'),
        ]);
        $mform->setDefault('resumeplayback', 1);
        $mform->addElement('selectyesno', 'allowseek', get_string('allowseek', 'videolesson'));
        $mform->setDefault('allowseek', 1);
        $mform->addElement('select', 'maxplaybackrate', get_string('maxplaybackrate', 'videolesson'), [
            '0' => get_string('nolimit', 'videolesson'),
            '1' => '1x',
            '1.25' => '1.25x',
            '1.5' => '1.5x',
            '1.75' => '1.75x',
            '2' => '2x',
        ]);
        $mform->setDefault('maxplaybackrate', 0);

        $mform->addElement('header', 'progresssettings', get_string('progresssettings', 'videolesson'));
        $mform->addElement('text', 'chapterpercent', get_string('chapterpercent', 'videolesson'), ['size' => 5]);
        $mform->setType('chapterpercent', PARAM_INT);
        $mform->setDefault('chapterpercent', 90);
        $mform->addRule('chapterpercent', null, 'numeric', null, 'client');

        $mform->addElement('select', 'completionmode', get_string('completionmode', 'videolesson'), [
            'required' => get_string('completionmoderequired', 'videolesson'),
            'percent' => get_string('completionmodepercent', 'videolesson'),
        ]);
        $mform->setDefault('completionmode', 'required');
        $mform->addElement('text', 'completionpercent', get_string('completionpercent', 'videolesson'), ['size' => 5]);
        $mform->setType('completionpercent', PARAM_INT);
        $mform->setDefault('completionpercent', 100);
        $mform->addRule('completionpercent', null, 'numeric', null, 'client');
        $mform->hideIf('completionpercent', 'completionmode', 'neq', 'percent');

        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 100);
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Validates submitted fields.
     *
     * @param array $data Form data.
     * @param array $files Uploaded files.
     * @return array Validation errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        foreach (['chapterpercent', 'completionpercent'] as $field) {
            if (isset($data[$field]) && ((int)$data[$field] < 1 || (int)$data[$field] > 100)) {
                $errors[$field] = get_string('errorpercent', 'videolesson');
            }
        }
        if (($data['videosource'] ?? '') === 'upload') {
            $draftid = (int)($data['videofile'] ?? 0);
            $draftinfo = $draftid ? file_get_draft_area_info($draftid) : ['filecount' => 0];
            if (empty($draftinfo['filecount'])) {
                $errors['videofile'] = get_string('videofilerequired', 'videolesson');
            }
        } else if (empty($data['videourl'])) {
            $errors['videourl'] = get_string('required');
        }
        if (($data['videosource'] ?? '') === 'youtube' &&
            !preg_match(
                '~(?:youtu\\.be/|youtube\\.com/(?:watch\\?v=|embed/))([A-Za-z0-9_-]{6,})~',
                (string)($data['videourl'] ?? ''))) {
            $errors['videourl'] = get_string('invalidyoutube', 'videolesson');
        }
        if (($data['videosource'] ?? '') === 'vimeo' &&
            !preg_match('~vimeo\\.com/(?:video/)?([0-9]+)~', (string)($data['videourl'] ?? ''))) {
            $errors['videourl'] = get_string('invalidvimeo', 'videolesson');
        }
        return $errors;
    }

    /**
     * Prepares draft file areas.
     *
     * @param array $defaultvalues Form defaults.
     * @return void
     */
    public function data_preprocessing(&$defaultvalues): void {
        if (array_key_exists('completionchapters', $defaultvalues)) {
            $defaultvalues['completionchapters_videolesson'] = $defaultvalues['completionchapters'];
        }
        if (empty($this->current->instance)) {
            return;
        }
        foreach (['videofile', 'captions'] as $area) {
            $draftid = file_get_submitted_draft_itemid($area);
            file_prepare_draft_area($draftid, $this->context->id, 'mod_videolesson', $area, 0, ['subdirs' => 0]);
            $defaultvalues[$area] = $draftid;
        }
    }

    /**
     * Adds the custom completion rule used by Moodle automatic completion.
     *
     * @return array Rule field names.
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;
        $field = 'completionchapters_videolesson';
        $mform->addElement('advcheckbox', $field, get_string('completionchapters', 'videolesson'));
        $mform->setDefault($field, 1);
        return [$field];
    }

    /**
     * Tells Moodle whether the custom completion rule is active.
     *
     * @param array $data Form data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        return !empty($data['completionchapters_videolesson']);
    }

    /**
     * Maps the suffixed completion field back to the activity record.
     *
     * @return stdClass|null Submitted form data or null when cancelled.
     */
    public function get_data() {
        $data = parent::get_data();
        if (!$data) {
            return $data;
        }
        if (property_exists($data, 'completionchapters_videolesson')) {
            $data->completionchapters = (int)$data->completionchapters_videolesson;
            unset($data->completionchapters_videolesson);
        }
        return $data;
    }
}

