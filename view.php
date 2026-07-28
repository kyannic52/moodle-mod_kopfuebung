<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:view', $context);

$PAGE->set_url('/mod/kopfuebung/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($kopfuebung->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($kopfuebung->name));
echo format_module_intro('kopfuebung', $kopfuebung, $cm->id);

$statusclass = $kopfuebung->activitystate ? 'notifysuccess' : 'notifyproblem';
$statusstring = $kopfuebung->activitystate ? get_string('activityisopen', 'kopfuebung') : get_string('activityisclosed', 'kopfuebung');
echo $OUTPUT->notification($statusstring, $statusclass);

$buttons = [];
if (has_capability('mod/kopfuebung:viewoverview', context_course::instance($course->id))) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id]),
        get_string('courseoverview', 'kopfuebung'),
        'get'
    );
}
if (has_capability('mod/kopfuebung:managequestions', $context)) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/manage.php', ['id' => $cm->id]),
        get_string('managequestions', 'kopfuebung'),
        'get'
    );
}

if (has_capability('mod/kopfuebung:startactivity', $context)) {
    $action = $kopfuebung->activitystate ? 'stop' : 'start';
    $label = $kopfuebung->activitystate ? get_string('stopactivity', 'kopfuebung') : get_string('startactivity', 'kopfuebung');
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/start.php', ['id' => $cm->id, 'action' => $action, 'sesskey' => sesskey()]),
        $label,
        'post'
    );
}

if (has_capability('mod/kopfuebung:viewreports', $context)) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/report.php', ['id' => $cm->id]),
        get_string('report', 'kopfuebung'),
        'get'
    );
}

if ($kopfuebung->activitystate && has_capability('mod/kopfuebung:attempt', $context)) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/attempt.php', ['id' => $cm->id]),
        get_string('attemptactivity', 'kopfuebung'),
        'get',
        ['primary' => true]
    );
}

echo html_writer::div(implode(' ', $buttons), 'kopfuebung-actions');
echo $OUTPUT->footer();
