<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_kopfuebung\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/** Upload form for a HUE package. */
class hue_upload_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('filepicker', 'huefile', get_string('huefile', 'kopfuebung'), null, [
            'accepted_types' => ['.hue'],
            'maxbytes' => 20 * 1024 * 1024,
        ]);
        $mform->addRule('huefile', get_string('required'), 'required');
        $this->add_action_buttons(true, get_string('hueapply', 'kopfuebung'));
    }
}
