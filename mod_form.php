<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_kopfuebung_mod_form extends moodleform_mod {
    public function definition() {
        global $DB;

        $mform = $this->_form;

        $canhueimport = !$this->_instance || !$DB->record_exists(
            'kopfuebung_attempts', ['kopfuebungid' => $this->_instance]
        );
        if ($canhueimport) {
            $mform->addElement('header', 'hueimportheader', get_string('hueimport', 'kopfuebung'));
            $mform->addElement('filepicker', 'huefile', get_string('huefile', 'kopfuebung'), null, [
                'accepted_types' => ['.hue'],
                'maxbytes' => 20 * 1024 * 1024,
            ]);
            $mform->addHelpButton('huefile', 'huefile', 'kopfuebung');
            $mform->addElement('submit', 'hueapply', get_string('hueapply', 'kopfuebung'));
        }

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);

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

        $mform->addElement('select', 'questioncount', get_string('questioncount', 'kopfuebung'), [
            8 => 8,
            9 => 9,
            10 => 10,
        ]);
        $mform->setDefault('questioncount', 10);
        $mform->addHelpButton('questioncount', 'questioncount', 'kopfuebung');

        $mform->addElement('advcheckbox', 'selfassessment', get_string('selfassessment', 'kopfuebung'));
        $mform->setDefault('selfassessment', 0);
        $mform->addHelpButton('selfassessment', 'selfassessment', 'kopfuebung');

        $mform->addElement('advcheckbox', 'difficultyassessment', get_string('difficultyassessment', 'kopfuebung'));
        $mform->setDefault('difficultyassessment', 0);
        $mform->addHelpButton('difficultyassessment', 'difficultyassessment', 'kopfuebung');

        $mform->addElement('advcheckbox', 'allowreadywithdraw', get_string('allowreadywithdraw', 'kopfuebung'));
        $mform->setDefault('allowreadywithdraw', 1);
        $mform->addHelpButton('allowreadywithdraw', 'allowreadywithdraw', 'kopfuebung');

        $mform->hideIf('timelimit', 'activitytype', 'eq', 'overview');
        $mform->hideIf('questioncount', 'activitytype', 'eq', 'overview');
        $mform->hideIf('selfassessment', 'activitytype', 'eq', 'overview');
        $mform->hideIf('difficultyassessment', 'activitytype', 'eq', 'overview');
        $mform->hideIf('allowreadywithdraw', 'activitytype', 'eq', 'overview');
        if ($canhueimport) {
            $mform->hideIf('hueimportheader', 'activitytype', 'eq', 'overview');
            $mform->hideIf('huefile', 'activitytype', 'eq', 'overview');
            $mform->hideIf('hueapply', 'activitytype', 'eq', 'overview');
        }

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        global $USER;

        $errors = parent::validation($data, $files);
        $isoverview = ($data['activitytype'] ?? 'exercise') === 'overview';
        if (trim((string) ($data['name'] ?? '')) === '' && empty($data['hueapply'])) {
            $errors['name'] = get_string('required');
        }
        if (!$isoverview && empty($data['timelimit'])) {
            $errors['timelimit'] = get_string('required');
        }
        if (!$isoverview &&
                !in_array((int) ($data['questioncount'] ?? 0), [8, 9, 10], true)) {
            $errors['questioncount'] = get_string('invalidquestioncount', 'kopfuebung');
        }
        $draftid = (int) ($data['huefile'] ?? 0);
        $huefiles = $draftid ? get_file_storage()->get_area_files(
            context_user::instance($USER->id)->id, 'user', 'draft', $draftid, 'id', false
        ) : [];
        if (!empty($data['hueapply']) && !$huefiles) {
            $errors['huefile'] = get_string('required');
        } else if ($huefiles) {
            try {
                $package = \mod_kopfuebung\local\hue\service::read_stored_file(reset($huefiles));
                $imported = new stdClass();
                \mod_kopfuebung\local\hue\service::apply_activity_settings($package, $imported);
                foreach (['name', 'timelimit', 'questioncount', 'selfassessment',
                        'difficultyassessment', 'allowreadywithdraw'] as $field) {
                    $this->_form->setValue($field, $imported->{$field});
                    unset($errors[$field]);
                }
                $this->_form->setValue('introeditor', [
                    'text' => $imported->intro,
                    'format' => $imported->introformat,
                    'itemid' => 0,
                ]);
            } catch (moodle_exception $exception) {
                $errors['huefile'] = $exception->getMessage();
            }
        }
        return $errors;
    }
}
