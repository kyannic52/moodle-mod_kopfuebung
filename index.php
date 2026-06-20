<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$PAGE->set_url('/mod/kopfuebung/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'kopfuebung'));
$PAGE->set_heading($course->fullname);

$instances = get_all_instances_in_course('kopfuebung', $course);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'kopfuebung'));

if (!$instances) {
    notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'kopfuebung')));
}

$table = new html_table();
$table->head = [get_string('name'), get_string('timelimit', 'kopfuebung')];

foreach ($instances as $instance) {
    $url = new moodle_url('/mod/kopfuebung/view.php', ['id' => $instance->coursemodule]);
    $table->data[] = [
        html_writer::link($url, format_string($instance->name)),
        format_time($instance->timelimit),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
