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

$PAGE->set_url('/mod/kopfuebung/manage.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('managequestions', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$availablequestions = kopfuebung_get_available_questions($course);
if (data_submitted()) {
    require_sesskey();
    $assignments = optional_param_array('assignments', [], PARAM_INT);
    kopfuebung_save_question_assignments(
        $kopfuebung->id,
        $assignments,
        $availablequestions,
        (int) $kopfuebung->questioncount
    );
    redirect(
        $PAGE->url,
        get_string('questionassignmentssaved', 'kopfuebung'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$selectedquestions = $DB->get_records(
    'kopfuebung_questions',
    ['kopfuebungid' => $kopfuebung->id],
    'sortorder ASC, id ASC'
);
$selectedbyposition = [];
foreach ($selectedquestions as $selectedquestion) {
    if ($selectedquestion->sortorder >= 1 && $selectedquestion->sortorder <= $kopfuebung->questioncount) {
        $selectedbyposition[(int) $selectedquestion->sortorder] = (int) $selectedquestion->questionid;
    }
}
$labels = kopfuebung_get_effective_course_labels($course, $cm->id);

$questionoptions = [0 => get_string('noquestionselected', 'kopfuebung')];
foreach ($availablequestions as $question) {
    $questionoptions[$question->id] = format_string($question->name);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managequestions', 'kopfuebung'));
echo html_writer::tag('p', get_string('managequestionsintro', 'kopfuebung'), ['class' => 'mb-4']);

if (!$availablequestions) {
    echo $OUTPUT->notification(get_string('noavailablequestions', 'kopfuebung'), 'notifymessage');
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out(false),
    'id' => 'kopfuebung-assignment-form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$table = new html_table();
$table->attributes['class'] = 'generaltable kopfuebung-assignment-table';
$table->head = [
    get_string('testposition', 'kopfuebung'),
    get_string('quickquestionselection', 'kopfuebung'),
    get_string('actions'),
];

for ($position = 1; $position <= $kopfuebung->questioncount; $position++) {
    $positionlabel = get_string('positionwithlabel', 'kopfuebung', [
        'position' => $position,
        'label' => $labels[$position] !== '' ? $labels[$position] : get_string('unlabelled', 'kopfuebung'),
    ]);
    $selectid = 'kopfuebung-assignment-' . $position;
    $select = html_writer::label(
        get_string('quickquestionselectionfor', 'kopfuebung', $positionlabel),
        $selectid,
        false,
        ['class' => 'sr-only']
    );
    $select .= html_writer::select(
        $questionoptions,
        'assignments[' . $position . ']',
        $selectedbyposition[$position] ?? 0,
        false,
        [
            'id' => $selectid,
            'class' => 'custom-select kopfuebung-question-select',
            'data-initial-value' => $selectedbyposition[$position] ?? 0,
        ]
    );

    $manualurl = new moodle_url('/mod/kopfuebung/question_select.php', [
        'id' => $cm->id,
        'position' => $position,
    ]);
    $manualbutton = html_writer::link(
        $manualurl,
        get_string('manualassignment', 'kopfuebung'),
        ['class' => 'btn btn-primary']
    );

    $table->data[] = [
        html_writer::tag('strong', $positionlabel),
        $select,
        $manualbutton,
    ];
}

echo html_writer::div(html_writer::table($table), 'table-responsive');
echo html_writer::tag('button', get_string('assignquestionselection', 'kopfuebung'), [
    'type' => 'submit',
    'id' => 'kopfuebung-save-assignments',
    'class' => 'btn btn-secondary',
    'disabled' => 'disabled',
]);
echo html_writer::end_tag('form');

$PAGE->requires->js_init_code("
var form = document.getElementById('kopfuebung-assignment-form');
var saveButton = document.getElementById('kopfuebung-save-assignments');
if (form && saveButton) {
    var selects = form.querySelectorAll('.kopfuebung-question-select');
    var updateButton = function() {
        var changed = Array.prototype.some.call(selects, function(select) {
            return select.value !== select.getAttribute('data-initial-value');
        });
        saveButton.disabled = !changed;
        saveButton.classList.toggle('btn-primary', changed);
        saveButton.classList.toggle('btn-secondary', !changed);
    };
    Array.prototype.forEach.call(selects, function(select) {
        select.addEventListener('change', updateButton);
    });
    updateButton();
}
");

echo $OUTPUT->footer();
