<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);

/**
 * Create a Moodle question-engine usage for a Kopfübung attempt.
 *
 * @param stdClass[] $questions
 * @param context_module $context
 * @return int
 */
function kopfuebung_create_question_usage(array $questions, context_module $context): int {
    $quba = question_engine::make_questions_usage_by_activity('mod_kopfuebung', $context);
    $quba->set_preferred_behaviour('deferredfeedback');

    foreach ($questions as $selected) {
        $question = question_bank::load_question($selected->questionid);
        $quba->add_question($question, 1);
    }

    $quba->start_all_questions();
    question_engine::save_questions_usage_by_activity($quba);

    return $quba->get_id();
}

/**
 * Format remaining seconds as MM:SS.
 *
 * @param int $seconds
 * @return string
 */
function kopfuebung_format_timer(int $seconds): string {
    $seconds = max(0, $seconds);

    return sprintf('%02d:%02d', floor($seconds / 60), $seconds % 60);
}

$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:attempt', $context);

if (!$kopfuebung->activitystate) {
    redirect(new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]), get_string('activityisclosed', 'kopfuebung'));
}

$questions = $DB->get_records_select(
    'kopfuebung_questions',
    'kopfuebungid = :kopfuebungid AND sortorder <= :questioncount',
    ['kopfuebungid' => $kopfuebung->id, 'questioncount' => $kopfuebung->questioncount],
    'sortorder ASC, id ASC'
);
if (!$questions) {
    redirect(new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]), get_string('missingquestions', 'kopfuebung'));
}

$attempt = kopfuebung_get_current_attempt($kopfuebung, $USER->id);

if ($attempt && $attempt->status === 'finished') {
    redirect(new moodle_url('/mod/kopfuebung/complete.php', ['id' => $cm->id]));
}

if (!$attempt) {
    $attempt = (object) [
        'kopfuebungid' => $kopfuebung->id,
        'userid' => $USER->id,
        'questionusageid' => 0,
        'timestarted' => $kopfuebung->timestarted ?: time(),
        'timefinished' => null,
        'status' => 'inprogress',
    ];
    $attempt->questionusageid = kopfuebung_create_question_usage($questions, $context);
    $attempt->id = $DB->insert_record('kopfuebung_attempts', $attempt);
} else if (empty($attempt->questionusageid)) {
    $attempt->questionusageid = kopfuebung_create_question_usage($questions, $context);
    $DB->update_record('kopfuebung_attempts', $attempt);
}

$deadline = $attempt->timestarted + $kopfuebung->timelimit;
$expired = time() >= $deadline;
$quba = question_engine::load_questions_usage_by_activity($attempt->questionusageid);

if (data_submitted() && confirm_sesskey()) {
    $timenow = time();
    $transaction = $DB->start_delegated_transaction();
    $quba->process_all_actions($timenow);

    if (optional_param('finish', 0, PARAM_BOOL) || $expired) {
        $quba->finish_all_questions($timenow);
        $attempt->status = 'finished';
        $attempt->timefinished = $timenow;
        $DB->update_record('kopfuebung_attempts', $attempt);
        question_engine::save_questions_usage_by_activity($quba);
        $transaction->allow_commit();
        redirect(new moodle_url('/mod/kopfuebung/complete.php', ['id' => $cm->id]));
    }

    question_engine::save_questions_usage_by_activity($quba);
    $transaction->allow_commit();
    redirect(new moodle_url('/mod/kopfuebung/attempt.php', ['id' => $cm->id]), get_string('answersaved', 'kopfuebung'));
}

if ($expired) {
    $timenow = time();
    $transaction = $DB->start_delegated_transaction();
    $quba->finish_all_questions($timenow);
    $attempt->status = 'finished';
    $attempt->timefinished = $timenow;
    $DB->update_record('kopfuebung_attempts', $attempt);
    question_engine::save_questions_usage_by_activity($quba);
    $transaction->allow_commit();
    redirect(new moodle_url('/mod/kopfuebung/complete.php', ['id' => $cm->id]));
}

$PAGE->set_url('/mod/kopfuebung/attempt.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($kopfuebung->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($kopfuebung->name));
$remainingseconds = max(0, $deadline - time());
echo html_writer::div(
    get_string('remainingtime', 'kopfuebung') . ': ' . kopfuebung_format_timer($remainingseconds),
    'alert alert-info shadow-sm kopfuebung-timer-fixed',
    ['id' => 'kopfuebung-timer', 'data-seconds' => $remainingseconds]
);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'id' => 'responseform',
    'enctype' => 'multipart/form-data',
    'accept-charset' => 'utf-8',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
$slots = $quba->get_slots();
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'slots', 'value' => implode(',', $slots)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'scrollpos', 'value' => '']);

$options = new question_display_options();
$options->marks = question_display_options::HIDDEN;
$options->feedback = question_display_options::HIDDEN;
$options->generalfeedback = question_display_options::HIDDEN;
$options->rightanswer = question_display_options::HIDDEN;
$options->history = question_display_options::HIDDEN;

foreach ($slots as $displaynumber => $slot) {
    echo $quba->render_question($slot, $options, $displaynumber + 1);
}

echo html_writer::tag('button', get_string('submitanswers', 'kopfuebung'), ['type' => 'submit', 'class' => 'btn btn-secondary mr-2']);
echo html_writer::tag('button', get_string('finishattempt', 'kopfuebung'), [
    'type' => 'submit',
    'name' => 'finish',
    'value' => 1,
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

$remaininglabel = json_encode(get_string('remainingtime', 'kopfuebung') . ': ');
$PAGE->requires->js_init_code("
var timer = document.getElementById('kopfuebung-timer');
var form = document.getElementById('responseform');
if (timer && form) {
    var seconds = parseInt(timer.getAttribute('data-seconds'), 10);
    var label = $remaininglabel;
    var interval;
    var updateTimer = function() {
        var minutes = Math.floor(seconds / 60);
        var remainder = seconds % 60;
        var formatted = (minutes < 10 ? '0' : '') + minutes + ':' +
            (remainder < 10 ? '0' : '') + remainder;
        timer.textContent = label + formatted;
        if (seconds <= 0) {
            window.clearInterval(interval);
            var finish = document.createElement('input');
            finish.type = 'hidden';
            finish.name = 'finish';
            finish.value = '1';
            form.appendChild(finish);
            form.submit();
            return;
        }
        seconds--;
    };
    updateTimer();
    interval = window.setInterval(updateTimer, 1000);
}
");
$PAGE->requires->js_init_call('M.core_question_engine.init_form', ['#responseform']);
echo $OUTPUT->footer();
