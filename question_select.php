<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$position = required_param('position', PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$search = optional_param('qsearch', '', PARAM_TEXT);

$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:managequestions', $context);

if ($position < 1 || $position > 10) {
    throw new moodle_exception('invalidposition', 'kopfuebung');
}

$PAGE->set_url('/mod/kopfuebung/question_select.php', [
    'id' => $cm->id,
    'position' => $position,
    'categoryid' => $categoryid,
    'qsearch' => $search,
]);
$PAGE->set_title(get_string('manualassignment', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$allquestions = kopfuebung_get_available_questions($course);
$selectedrecords = $DB->get_records(
    'kopfuebung_questions',
    ['kopfuebungid' => $kopfuebung->id],
    'sortorder ASC'
);
$assignments = [];
foreach ($selectedrecords as $record) {
    if ($record->sortorder >= 1 && $record->sortorder <= 10) {
        $assignments[(int) $record->sortorder] = (int) $record->questionid;
    }
}

if (data_submitted()) {
    require_sesskey();
    $buttonquestionid = optional_param('selectquestion', 0, PARAM_INT);
    $checkedquestionids = optional_param_array('questionids', [], PARAM_INT);
    $questionid = $buttonquestionid;
    if (!$questionid) {
        $checkedquestionids = array_values(array_unique(array_filter($checkedquestionids)));
        if (count($checkedquestionids) !== 1) {
            throw new moodle_exception('selectexactlyonequestion', 'kopfuebung', $PAGE->url);
        }
        $questionid = reset($checkedquestionids);
    }

    $assignments[$position] = $questionid;
    kopfuebung_save_question_assignments($kopfuebung->id, $assignments, $allquestions);
    redirect(
        new moodle_url('/mod/kopfuebung/manage.php', ['id' => $cm->id]),
        get_string('questionassigned', 'kopfuebung'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$labels = kopfuebung_get_effective_course_labels($course, $cm->id);
$positionlabel = get_string('positionwithlabel', 'kopfuebung', [
    'position' => $position,
    'label' => $labels[$position] !== '' ? $labels[$position] : get_string('unlabelled', 'kopfuebung'),
]);
$categories = kopfuebung_get_question_categories($course);
$categoryoptions = [0 => get_string('allcategories', 'kopfuebung')];
foreach ($categories as $category) {
    $categoryoptions[$category->id] = format_string($category->name);
}
$questions = kopfuebung_get_available_questions($course, $categoryid, $search);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manualassignmentfor', 'kopfuebung', $positionlabel));
echo html_writer::tag('p', get_string('manualassignmentintro', 'kopfuebung'));

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/mod/kopfuebung/question_select.php'))->out(false),
    'class' => 'd-flex flex-wrap align-items-end mb-4',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'position', 'value' => $position]);
echo html_writer::start_div('form-group mr-3 mb-2');
echo html_writer::label(get_string('category'), 'kopfuebung-categoryid');
echo html_writer::select($categoryoptions, 'categoryid', $categoryid, false, [
    'id' => 'kopfuebung-categoryid',
    'class' => 'custom-select',
]);
echo html_writer::end_div();
echo html_writer::start_div('form-group mr-3 mb-2');
echo html_writer::label(get_string('search'), 'kopfuebung-qsearch');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'qsearch',
    'id' => 'kopfuebung-qsearch',
    'value' => $search,
    'class' => 'form-control',
]);
echo html_writer::end_div();
echo html_writer::tag('button', get_string('filter'), [
    'type' => 'submit',
    'class' => 'btn btn-secondary mb-2',
]);
echo html_writer::end_tag('form');

if (!$questions) {
    echo $OUTPUT->notification(get_string('noavailablequestions', 'kopfuebung'), 'notifymessage');
} else {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    $table = new html_table();
    $table->attributes['class'] = 'generaltable kopfuebung-question-picker';
    $table->head = [
        get_string('select'),
        get_string('name'),
        get_string('questiontype', 'question'),
        get_string('category'),
        get_string('questionpreview', 'kopfuebung'),
        get_string('actions'),
    ];

    foreach ($questions as $question) {
        $checkboxid = 'kopfuebung-question-' . $question->id;
        $checkbox = html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'questionids[]',
            'id' => $checkboxid,
            'value' => $question->id,
            'class' => 'kopfuebung-question-checkbox',
        ]);
        $preview = shorten_text(trim(html_to_text($question->questiontext, 0, false)), 350);
        $selectbutton = html_writer::tag('button', get_string('selectquestion', 'kopfuebung'), [
            'type' => 'submit',
            'name' => 'selectquestion',
            'value' => $question->id,
            'class' => 'btn btn-primary',
        ]);
        $name = html_writer::label(
            format_string($question->name),
            $checkboxid,
            false,
            ['class' => 'font-weight-bold']
        );
        if (($assignments[$position] ?? 0) === (int) $question->id) {
            $name .= html_writer::div(get_string('currentlyassigned', 'kopfuebung'), 'badge badge-info mt-1');
        }

        $table->data[] = [
            $checkbox,
            $name,
            s($question->qtype),
            format_string($question->categoryname),
            s($preview),
            $selectbutton,
        ];
    }

    echo html_writer::div(html_writer::table($table), 'table-responsive');
    echo html_writer::tag('button', get_string('selectquestion', 'kopfuebung'), [
        'type' => 'submit',
        'id' => 'kopfuebung-select-checked',
        'class' => 'btn btn-secondary',
        'disabled' => 'disabled',
    ]);
    echo html_writer::link(
        new moodle_url('/mod/kopfuebung/manage.php', ['id' => $cm->id]),
        get_string('cancel'),
        ['class' => 'btn btn-secondary ml-2']
    );
    echo html_writer::end_tag('form');

    $PAGE->requires->js_init_code("
var checkboxes = document.querySelectorAll('.kopfuebung-question-checkbox');
var selectButton = document.getElementById('kopfuebung-select-checked');
if (selectButton) {
    var updateSelectionButton = function() {
        var checked = document.querySelectorAll('.kopfuebung-question-checkbox:checked').length;
        selectButton.disabled = checked !== 1;
        selectButton.classList.toggle('btn-primary', checked === 1);
        selectButton.classList.toggle('btn-secondary', checked !== 1);
    };
    Array.prototype.forEach.call(checkboxes, function(checkbox) {
        checkbox.addEventListener('change', updateSelectionButton);
    });
}
");
}

echo $OUTPUT->footer();
