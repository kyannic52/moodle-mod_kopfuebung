<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:managequestions', $context);
if (($kopfuebung->activitytype ?? 'exercise') !== 'exercise') {
    throw new moodle_exception('hueexerciseonly', 'kopfuebung');
}

$path = \mod_kopfuebung\local\hue\service::export($course, $kopfuebung);
$basename = clean_param(format_string($kopfuebung->name), PARAM_FILE);
if ($basename === '') {
    $basename = 'kopfuebung';
}
send_temp_file($path, $basename . '.hue');
