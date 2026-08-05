<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', $USER->id, PARAM_INT);

$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:view', $context);

$isownattempt = (int) $userid === (int) $USER->id && has_capability('mod/kopfuebung:attempt', $context);
$canreviewothers = has_capability('mod/kopfuebung:viewreports', $context);
if (!$isownattempt && !$canreviewothers) {
    throw new moodle_exception('nopermissions', 'error');
}
if ($isownattempt && $kopfuebung->activitystate) {
    throw new moodle_exception('reviewnotavailableuntilclosed', 'kopfuebung');
}

$reviewuser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
if (!$isownattempt && !is_enrolled($context, $reviewuser, '', true)) {
    throw new moodle_exception('notenrolled', 'enrol');
}
$attempt = kopfuebung_get_latest_finished_attempt($kopfuebung->id, $reviewuser->id);
if (!$attempt || empty($attempt->questionusageid)) {
    throw new moodle_exception('noreviewableattempt', 'kopfuebung');
}

$quba = question_engine::load_questions_usage_by_activity($attempt->questionusageid);
$selectedquestions = $DB->get_records(
    'kopfuebung_questions',
    ['kopfuebungid' => $kopfuebung->id],
    'sortorder ASC, id ASC',
    'id, sortorder'
);
$selectedquestions = array_values($selectedquestions);
$positions = array_map(static function($selectedquestion): int {
    return (int) $selectedquestion->sortorder;
}, $selectedquestions);
$reflectionrecords = $DB->get_records('kopfuebung_reflections', ['attemptid' => $attempt->id]);
$reflectionsbyquestion = [];
foreach ($reflectionrecords as $reflection) {
    $reflectionsbyquestion[(int) $reflection->kopfquestionid] = $reflection;
}

$PAGE->set_url('/mod/kopfuebung/review.php', ['id' => $cm->id, 'userid' => $reviewuser->id]);
$PAGE->set_title(get_string('attemptreview', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->requires->js_init_code(<<<JS
window.addEventListener('load', function() {
    if (window.location.hash) {
        var target = document.getElementById(window.location.hash.substring(1));
        if (target) {
            window.setTimeout(function() {
                target.scrollIntoView({behavior: 'smooth', block: 'center'});
                target.focus({preventScroll: true});
            }, 100);
        }
    }
});
JS
);

$options = new question_display_options();
$options->readonly = true;
$options->marks = question_display_options::MARK_AND_MAX;
$options->correctness = question_display_options::VISIBLE;
$options->feedback = question_display_options::VISIBLE;
$options->generalfeedback = question_display_options::VISIBLE;
$options->rightanswer = question_display_options::VISIBLE;
$options->history = question_display_options::HIDDEN;

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('attemptreviewfor', 'kopfuebung', fullname($reviewuser)));
echo html_writer::tag('p', get_string('attemptreviewintro', 'kopfuebung'));

foreach (array_values($quba->get_slots()) as $index => $slot) {
    $position = $positions[$index] ?? ($index + 1);
    $selectedquestion = $selectedquestions[$index] ?? null;
    $reflection = $selectedquestion ? ($reflectionsbyquestion[(int) $selectedquestion->id] ?? null) : null;
    echo html_writer::start_div('kopfuebung-review-question', [
        'id' => 'question-' . $position,
        'tabindex' => '-1',
    ]);
    echo $quba->render_question($slot, $options, $index + 1);
    echo html_writer::start_div('kopfuebung-reflection-review card card-body mt-2');
    echo html_writer::tag('strong', get_string('selfreflection', 'kopfuebung'));
    $assessment = !empty($kopfuebung->selfassessment) && $reflection && $reflection->predictedcorrect !== null
        ? get_string($reflection->predictedcorrect ? 'assessedcorrect' : 'assessedincorrect', 'kopfuebung')
        : get_string('notavailableabbr', 'kopfuebung');
    $difficulty = !empty($kopfuebung->difficultyassessment) && $reflection && $reflection->difficulty !== null
        ? get_string('difficultyoutof', 'kopfuebung', $reflection->difficulty)
        : get_string('notavailableabbr', 'kopfuebung');
    echo html_writer::div(get_string('selfassessment', 'kopfuebung') . ': ' . $assessment);
    echo html_writer::div(get_string('difficultyassessment', 'kopfuebung') . ': ' . $difficulty);
    echo html_writer::end_div();
    echo html_writer::end_div();
}

$backurl = $canreviewothers
    ? new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id, 'userid' => $reviewuser->id])
    : new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]);
echo $OUTPUT->single_button($backurl, get_string('back', 'moodle'), 'get');
echo $OUTPUT->footer();
