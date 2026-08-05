<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:startactivity', $context);
require_sesskey();

if ($action === 'start') {
    $kopfuebung->activitystate = 1;
    $kopfuebung->timestarted = time();
} else if ($action === 'stop') {
    kopfuebung_finish_open_attempts($kopfuebung);
    $kopfuebung->activitystate = 0;
    $DB->delete_records('kopfuebung_ready', ['kopfuebungid' => $kopfuebung->id]);
} else {
    throw new moodle_exception('invalidrequest');
}

$kopfuebung->timemodified = time();
$DB->update_record('kopfuebung', $kopfuebung);

redirect(new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]));
