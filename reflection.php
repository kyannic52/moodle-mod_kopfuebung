<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:attempt', $context);

kopfuebung_close_expired_activity($kopfuebung);

$attempt = kopfuebung_get_latest_finished_attempt($kopfuebung->id, $USER->id);
if (!$attempt || empty($attempt->questionusageid)) {
    redirect(new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]));
}
if (empty($kopfuebung->selfassessment) && empty($kopfuebung->difficultyassessment)) {
    redirect(new moodle_url('/mod/kopfuebung/complete.php', ['id' => $cm->id]));
}

$questions = array_values($DB->get_records_select(
    'kopfuebung_questions',
    'kopfuebungid = :id AND sortorder <= :count',
    ['id' => $kopfuebung->id, 'count' => $kopfuebung->questioncount],
    'sortorder ASC, id ASC'
));
$quba = question_engine::load_questions_usage_by_activity($attempt->questionusageid);

if (data_submitted() && confirm_sesskey()) {
    $predictions = optional_param_array('predictedcorrect', [], PARAM_INT);
    $difficulties = optional_param_array('difficulty', [], PARAM_INT);
    $transaction = $DB->start_delegated_transaction();
    foreach ($questions as $question) {
        $prediction = $predictions[$question->id] ?? null;
        $difficulty = $difficulties[$question->id] ?? null;
        if (!empty($kopfuebung->selfassessment) && !in_array((string) $prediction, ['0', '1'], true)) {
            continue;
        }
        if (!empty($kopfuebung->difficultyassessment) &&
                (!is_numeric($difficulty) || (int) $difficulty < 1 || (int) $difficulty > 5)) {
            continue;
        }
        $record = $DB->get_record('kopfuebung_reflections', [
            'attemptid' => $attempt->id,
            'kopfquestionid' => $question->id,
        ]);
        $data = (object) [
            'attemptid' => $attempt->id,
            'kopfquestionid' => $question->id,
            'predictedcorrect' => !empty($kopfuebung->selfassessment) ? (int) $prediction : null,
            'difficulty' => !empty($kopfuebung->difficultyassessment) ? (int) $difficulty : null,
            'timemodified' => time(),
        ];
        if ($record) {
            $data->id = $record->id;
            $DB->update_record('kopfuebung_reflections', $data);
        } else {
            $DB->insert_record('kopfuebung_reflections', $data);
        }
    }
    $transaction->allow_commit();
    if (!kopfuebung_reflection_pending($kopfuebung, $attempt)) {
        redirect(new moodle_url('/mod/kopfuebung/complete.php', ['id' => $cm->id]),
            get_string('reflectionssaved', 'kopfuebung'));
    }
}

$saved = $DB->get_records('kopfuebung_reflections', ['attemptid' => $attempt->id], '', '*');
$savedbyquestion = [];
foreach ($saved as $record) {
    $savedbyquestion[(int) $record->kopfquestionid] = $record;
}

$PAGE->set_url('/mod/kopfuebung/reflection.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('selfreflection', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$options = new question_display_options();
$options->readonly = true;
$options->marks = question_display_options::HIDDEN;
$options->correctness = question_display_options::HIDDEN;
$options->feedback = question_display_options::HIDDEN;
$options->generalfeedback = question_display_options::HIDDEN;
$options->rightanswer = question_display_options::HIDDEN;
$options->history = question_display_options::HIDDEN;

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('selfreflection', 'kopfuebung'));
echo html_writer::tag('p', get_string('selfreflectionintro', 'kopfuebung'));
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

foreach (array_values($quba->get_slots()) as $index => $slot) {
    if (!isset($questions[$index])) {
        continue;
    }
    $question = $questions[$index];
    $reflection = $savedbyquestion[$question->id] ?? null;
    echo html_writer::start_div('kopfuebung-reflection-question');
    echo html_writer::div($quba->render_question($slot, $options, $index + 1), 'kopfuebung-reflection-question-content');
    echo html_writer::start_div('kopfuebung-reflection-fields card card-body');
    if (!empty($kopfuebung->selfassessment)) {
        $name = 'predictedcorrect[' . $question->id . ']';
        echo html_writer::tag('p', get_string('selfassessmentquestion', 'kopfuebung'), ['class' => 'font-weight-bold']);
        foreach ([1 => 'assessedcorrect', 0 => 'assessedincorrect'] as $value => $stringkey) {
            $inputid = 'prediction-' . $question->id . '-' . $value;
            $input = html_writer::empty_tag('input', [
                'type' => 'radio', 'name' => $name, 'id' => $inputid, 'value' => $value,
                'required' => 'required',
                'checked' => $reflection && $reflection->predictedcorrect !== null &&
                    (int) $reflection->predictedcorrect === $value ? 'checked' : null,
            ]);
            echo html_writer::tag('label',
                $input . html_writer::span(get_string($stringkey, 'kopfuebung')),
                ['for' => $inputid, 'class' => 'kopfuebung-reflection-option']
            );
        }
    }
    if (!empty($kopfuebung->difficultyassessment)) {
        $selectid = 'difficulty-' . $question->id;
        echo html_writer::label(get_string('difficultyquestion', 'kopfuebung'), $selectid, false,
            ['class' => 'font-weight-bold d-block mt-2']);
        $optionshtml = html_writer::tag('option', get_string('choosedots', 'moodle'), ['value' => '']);
        for ($rating = 1; $rating <= 5; $rating++) {
            $optionshtml .= html_writer::tag('option', get_string('difficultyvalue' . $rating, 'kopfuebung'), [
                'value' => $rating,
                'selected' => $reflection && (int) $reflection->difficulty === $rating ? 'selected' : null,
            ]);
        }
        echo html_writer::tag('select', $optionshtml, [
            'name' => 'difficulty[' . $question->id . ']', 'id' => $selectid,
            'class' => 'custom-select', 'required' => 'required',
        ]);
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::tag('button', get_string('submitreflection', 'kopfuebung'), [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
