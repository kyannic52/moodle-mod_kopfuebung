<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$position = required_param('position', PARAM_INT);

if ($position < 1 || $position > 10) {
    throw new moodle_exception('invalidposition', 'kopfuebung');
}

$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$modulecontext = context_module::instance($cm->id);
$coursecontext = context_course::instance($course->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:manageoverview', $coursecontext);

$PAGE->set_url('/mod/kopfuebung/resultdetail.php', ['id' => $cm->id, 'position' => $position]);
$PAGE->set_title(get_string('participantresults', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($modulecontext);

$activity = (object) [
    'id' => $kopfuebung->id,
    'name' => $kopfuebung->name,
    'cmid' => $cm->id,
    'activitystate' => $kopfuebung->activitystate,
    'timestarted' => $kopfuebung->timestarted,
    'timelimit' => $kopfuebung->timelimit,
];
$activities = kopfuebung_get_course_activities($course);
$participants = kopfuebung_get_course_participants($course, $activities);
$labels = kopfuebung_get_effective_course_labels($course, $cm->id);
$selectedquestion = $DB->get_record('kopfuebung_questions', [
    'kopfuebungid' => $kopfuebung->id,
    'sortorder' => $position,
]);
$questionname = '';
if ($selectedquestion) {
    $questionname = $DB->get_field('question', 'name', ['id' => $selectedquestion->questionid]) ?: '';
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('participantresults', 'kopfuebung'));
echo html_writer::tag('p', get_string('resultdetailheading', 'kopfuebung', [
    'activity' => format_string($kopfuebung->name),
    'position' => $position,
    'label' => $labels[$position] !== '' ? s($labels[$position]) : get_string('unlabelled', 'kopfuebung'),
]));
if ($questionname !== '') {
    echo html_writer::tag('p', get_string('questionname', 'kopfuebung') . ': ' . format_string($questionname));
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kopfuebung-result-detail';
$table->head = [get_string('participant', 'kopfuebung'), get_string('result', 'kopfuebung')];

foreach ($participants as $participant) {
    $usermatrix = kopfuebung_get_user_matrix([$activity], $participant->id);
    $state = $usermatrix[$activity->id]['cells'][$position];
    $indicator = [
        'correct' => ['✓', 'success'],
        'partiallycorrect' => ['◐', 'warning'],
        'incorrect' => ['✕', 'danger'],
        'unanswered' => ['—', 'muted'],
        'notattempted' => ['—', 'muted'],
    ][$state];
    $result = html_writer::span(
        $indicator[0] . ' ' . get_string($state, 'kopfuebung'),
        'text-' . $indicator[1] . ' font-weight-bold'
    );
    $row = new html_table_row([fullname($participant), $result]);
    $row->attributes['class'] = 'kopfuebung-result-' . $state;
    $table->data[] = $row;
}

echo html_writer::div(html_writer::table($table), 'table-responsive');
echo $OUTPUT->single_button(
    new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id, 'userid' => -1]),
    get_string('backtooverview', 'kopfuebung'),
    'get'
);
echo $OUTPUT->footer();
