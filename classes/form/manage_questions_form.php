<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_kopfuebung\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class manage_questions_form extends \moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'questionid', get_string('questionid', 'kopfuebung'));
        $mform->setType('questionid', PARAM_INT);
        $mform->addRule('questionid', null, 'required', null, 'client');
        $mform->addHelpButton('questionid', 'questionid', 'kopfuebung');

        $mform->addElement('text', 'tag', get_string('questiontag', 'kopfuebung'));
        $mform->setType('tag', PARAM_TAG);
        $mform->addRule('tag', null, 'required', null, 'client');
        $mform->addHelpButton('tag', 'questiontag', 'kopfuebung');

        $this->add_action_buttons(false, get_string('addquestion', 'kopfuebung'));
    }
}
