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

        $this->add_action_buttons(true, get_string('saveadditionaloffer', 'kopfuebung'));
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
        $mform->addElement('select', $prefix . 'type', get_string('linktype', 'kopfuebung'), [
            'none' => get_string('nolinkselected', 'kopfuebung'),
            'activity' => get_string('courseactivity', 'kopfuebung'),
            'url' => get_string('externalurl', 'kopfuebung'),
        ]);
        $mform->setDefault($prefix . 'type', 'none');

        $mform->addElement('select', $prefix . 'cmid', get_string('courseactivity', 'kopfuebung'),
            [0 => get_string('choose')] + $activityoptions);
        $mform->hideIf($prefix . 'cmid', $prefix . 'type', 'neq', 'activity');

        $mform->addElement('text', $prefix . 'url', get_string('externalurl', 'kopfuebung'), ['size' => 64]);
        $mform->setType($prefix . 'url', PARAM_RAW_TRIMMED);
        $mform->hideIf($prefix . 'url', $prefix . 'type', 'neq', 'url');
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
            $type = $data[$prefix . 'type'] ?? 'none';
            if ($type === 'activity') {
                $cmid = (int) ($data[$prefix . 'cmid'] ?? 0);
                if (!$cmid || !isset($activityoptions[$cmid])) {
                    $errors[$prefix . 'cmid'] = get_string('required');
                }
            } else if ($type === 'url') {
                $url = trim($data[$prefix . 'url'] ?? '');
                if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                    $errors[$prefix . 'url'] = get_string('invalidurl', 'kopfuebung');
                }
            } else if ($type !== 'none') {
                $errors[$prefix . 'type'] = get_string('invalidrequest');
            }
        }

        return $errors;
    }
}
