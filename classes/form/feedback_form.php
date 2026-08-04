<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_kopfuebung\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Form for course and individual feedback messages.
 */
class feedback_form extends \moodleform {
    /**
     * Define the feedback form.
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->addElement('hidden', 'editfeedback', 0);
        $mform->setType('editfeedback', PARAM_INT);
        $mform->addElement('hidden', 'action', 'postfeedback');
        $mform->setType('action', PARAM_ALPHA);

        $mform->addElement('editor', 'message_editor', get_string('feedbackmessage', 'kopfuebung'), [
            'rows' => 8,
        ], [
            'maxfiles' => 0,
            'maxbytes' => 0,
            'context' => $this->_customdata['context'],
        ]);
        $mform->addRule('message_editor', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(
            !empty($this->_customdata['editmode']),
            get_string($this->_customdata['editmode'] ? 'savefeedbackchanges' : 'sendfeedback', 'kopfuebung')
        );
    }
}
