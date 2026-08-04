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
require_capability('mod/kopfuebung:attempt', $context);

$attempt = kopfuebung_get_latest_finished_attempt($kopfuebung->id, $USER->id);
if (!$attempt || $attempt->status !== 'finished') {
    redirect(new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]));
}

$remainingseconds = $kopfuebung->activitystate
    ? max(0, $kopfuebung->timestarted + $kopfuebung->timelimit - time())
    : 0;

$PAGE->set_url('/mod/kopfuebung/complete.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('attemptsubmitted', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if ($kopfuebung->activitystate && $remainingseconds > 0) {
    $timerlabel = json_encode(get_string('activityremaining', 'kopfuebung', '__TIME__'));
    $PAGE->requires->js_init_code("
var timer = document.getElementById('kopfuebung-completion-timer');
if (timer) {
    var seconds = parseInt(timer.getAttribute('data-seconds'), 10) || 0;
    var template = $timerlabel;
    var updateTimer = function() {
        var minutes = Math.floor(seconds / 60);
        var remainder = seconds % 60;
        var formatted = (minutes < 10 ? '0' : '') + minutes + ':' +
            (remainder < 10 ? '0' : '') + remainder;
        timer.textContent = template.replace('__TIME__', formatted);
        if (seconds > 0) {
            seconds--;
        }
    };
    updateTimer();
    window.setInterval(updateTimer, 1000);
}
");
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($kopfuebung->name));
echo $OUTPUT->notification(get_string('attemptsubmittedmessage', 'kopfuebung'), 'notifysuccess');

if ($kopfuebung->activitystate && $remainingseconds > 0) {
    echo html_writer::div(
        get_string('resultsavailableafteractivity', 'kopfuebung') . ' ' .
            html_writer::span('', '', [
                'id' => 'kopfuebung-completion-timer',
                'data-seconds' => $remainingseconds,
            ]),
        'alert alert-info'
    );
}

$buttons = [
    $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]),
        get_string('backtoactivity', 'kopfuebung'),
        'get'
    ),
    $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id]),
        get_string('courseoverview', 'kopfuebung'),
        'get'
    ),
    $OUTPUT->single_button(
        new moodle_url('/course/view.php', ['id' => $course->id]),
        get_string('backtocourse', 'kopfuebung'),
        'get'
    ),
];

if (!$kopfuebung->activitystate) {
    array_unshift($buttons, $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/review.php', ['id' => $cm->id]),
        get_string('reviewattempt', 'kopfuebung'),
        'get',
        ['primary' => true]
    ));
}

echo html_writer::div(implode(' ', $buttons), 'kopfuebung-actions');
echo $OUTPUT->footer();
