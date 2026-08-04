<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_kopfuebung_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        $mform->addElement('select', 'activitytype', get_string('activitytype', 'kopfuebung'), [
            'exercise' => get_string('exerciseactivity', 'kopfuebung'),
            'overview' => get_string('overviewactivity', 'kopfuebung'),
        ]);
        $mform->setDefault('activitytype', 'exercise');
        $mform->addHelpButton('activitytype', 'activitytype', 'kopfuebung');
        if ($this->_instance) {
            $mform->freeze('activitytype');
        }

        $mform->addElement('duration', 'timelimit', get_string('timelimit', 'kopfuebung'), ['optional' => false]);
        $mform->setDefault('timelimit', 300);
        $mform->addHelpButton('timelimit', 'timelimit', 'kopfuebung');
        $mform->addRule('timelimit', null, 'required', null, 'client');

        $mform->addElement('select', 'questioncount', get_string('questioncount', 'kopfuebung'), [
            8 => 8,
            9 => 9,
            10 => 10,
        ]);
        $mform->setDefault('questioncount', 10);
        $mform->addHelpButton('questioncount', 'questioncount', 'kopfuebung');

        $mform->hideIf('timelimit', 'activitytype', 'eq', 'overview');
        $mform->hideIf('questioncount', 'activitytype', 'eq', 'overview');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['activitytype'] ?? 'exercise') !== 'overview' &&
                !in_array((int) ($data['questioncount'] ?? 0), [8, 9, 10], true)) {
            $errors['questioncount'] = get_string('invalidquestioncount', 'kopfuebung');
        }
        return $errors;
    }
}
