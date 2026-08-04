<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_kopfuebung\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for an additional learning offer attached to a diagnostic grid row.
 */
class additional_offer_form extends \moodleform {
    /**
     * Define the form.
     */
    public function definition(): void {
        $mform = $this->_form;
        $activityoptions = $this->_customdata['activityoptions'];
        $participantoptions = $this->_customdata['participantoptions'];
        $thresholdmax = max(1, (int) $this->_customdata['thresholdmax']);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'gridid');
        $mform->setType('gridid', PARAM_INT);
        $mform->addElement('hidden', 'position');
        $mform->setType('position', PARAM_INT);

        $mform->addElement('editor', 'hint_editor', get_string('additionalofferhint', 'kopfuebung'), [
            'rows' => 6,
        ], [
            'maxfiles' => 0,
            'maxbytes' => 0,
            'context' => $this->_customdata['context'],
        ]);

        $thresholdoptions = [];
        for ($threshold = 1; $threshold <= $thresholdmax; $threshold++) {
            $thresholdoptions[$threshold] = $threshold;
        }
        $mform->addElement(
            'select',
            'threshold',
            get_string('additionalofferthreshold', 'kopfuebung'),
            $thresholdoptions
        );
        $mform->addHelpButton('threshold', 'additionalofferthreshold', 'kopfuebung');

        $this->add_target_fields('explanation', get_string('additionalexplanations', 'kopfuebung'), $activityoptions);
        $this->add_target_fields('practice', get_string('additionalpractice', 'kopfuebung'), $activityoptions);

        $mform->addElement('autocomplete', 'userids', get_string('alwaysshowforusers', 'kopfuebung'),
            $participantoptions, ['multiple' => true]);
        $mform->setType('userids', PARAM_INT);
        $mform->addHelpButton('userids', 'alwaysshowforusers', 'kopfuebung');

        if (!empty($this->_customdata['hasoffer'])) {
            $mform->addElement('advcheckbox', 'deleteoffer', get_string('deleteadditionaloffer', 'kopfuebung'));
        }

        $buttons = [];
        $buttons[] = $mform->createElement('submit', 'saveoffer', get_string('saveadditionaloffer', 'kopfuebung'));
        $buttons[] = $mform->createElement(
            'submit',
            'saveandreturn',
            get_string('saveadditionalofferandreturn', 'kopfuebung')
        );
        $buttons[] = $mform->createElement('cancel');
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
    }

    /**
     * Add activity-or-URL controls for one target.
     *
     * @param string $prefix
     * @param string $heading
     * @param array $activityoptions
     */
    private function add_target_fields(string $prefix, string $heading, array $activityoptions): void {
        $mform = $this->_form;
        $mform->addElement('header', $prefix . 'header', $heading);
        $mform->addElement('autocomplete', $prefix . 'cmids', get_string('courseactivities', 'kopfuebung'),
            $activityoptions, ['multiple' => true]);
        $mform->setType($prefix . 'cmids', PARAM_INT);
        $mform->addElement('textarea', $prefix . 'urls', get_string('externalurls', 'kopfuebung'), [
            'rows' => 5,
            'cols' => 64,
        ]);
        $mform->setType($prefix . 'urls', PARAM_RAW_TRIMMED);
        $mform->addHelpButton($prefix . 'urls', 'externalurls', 'kopfuebung');
    }

    /**
     * Validate conditional activity and URL targets.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $activityoptions = $this->_customdata['activityoptions'];

        foreach (['explanation', 'practice'] as $prefix) {
            foreach (array_map('intval', $data[$prefix . 'cmids'] ?? []) as $cmid) {
                if (!isset($activityoptions[$cmid])) {
                    $errors[$prefix . 'cmids'] = get_string('invalidcoursemodule', 'error');
                    break;
                }
            }
            $urls = preg_split('/\R/', trim($data[$prefix . 'urls'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($urls as $url) {
                $url = trim($url);
                if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                    $errors[$prefix . 'urls'] = get_string('invalidurl', 'kopfuebung');
                    break;
                }
            }
        }

        return $errors;
    }
}
