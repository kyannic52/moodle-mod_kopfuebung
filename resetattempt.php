<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
$student = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:resetattempts', $context);
if (!is_enrolled($context, $student, '', true) ||
        !has_capability('mod/kopfuebung:attempt', $context, $student->id)) {
    throw new moodle_exception('notenrolled', 'enrol');
}

$PAGE->set_url('/mod/kopfuebung/resetattempt.php', ['id' => $cm->id, 'userid' => $student->id]);
$PAGE->set_title(get_string('resetattempt', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if ($confirm) {
    require_sesskey();
    kopfuebung_reset_user_attempts($kopfuebung->id, $student->id);
    redirect(
        new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]),
        get_string('attemptreset', 'kopfuebung', fullname($student)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('resetattemptfor', 'kopfuebung', fullname($student)));
echo $OUTPUT->confirm(
    get_string('resetattemptconfirm', 'kopfuebung', fullname($student)),
    new moodle_url('/mod/kopfuebung/resetattempt.php', [
        'id' => $cm->id,
        'userid' => $student->id,
        'confirm' => 1,
        'sesskey' => sesskey(),
    ]),
    new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id])
);
echo $OUTPUT->footer();
