<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_login($course);
require_capability('mod/kopfuebung:viewoverview', $context);

$canmanage = has_capability('mod/kopfuebung:manageoverview', $context);
$targetuser = $USER;

if ($canmanage && $userid && $userid != $USER->id) {
    $targetuser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
    if (!is_enrolled($context, $targetuser, '', true)) {
        throw new moodle_exception('notenrolled', 'enrol');
    }
}

$urlparams = ['id' => $course->id];
if ($targetuser->id != $USER->id) {
    $urlparams['userid'] = $targetuser->id;
}
$PAGE->set_url('/mod/kopfuebung/overview.php', $urlparams);
$PAGE->set_title(get_string('courseoverview', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if ($canmanage && data_submitted()) {
    require_sesskey();
    $labels = optional_param_array('labels', [], PARAM_TEXT);
    kopfuebung_save_course_labels($course->id, $labels);
    redirect($PAGE->url, get_string('labelssaved', 'kopfuebung'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$activities = kopfuebung_get_course_activities($course);
$labels = kopfuebung_get_course_labels($course->id);
$matrix = kopfuebung_get_user_matrix($activities, $targetuser->id);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('courseoverview', 'kopfuebung'));

if ($canmanage) {
    $participants = get_enrolled_users(
        $context,
        '',
        0,
        'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
        'u.lastname ASC, u.firstname ASC'
    );
    $participantoptions = [$USER->id => fullname($USER)];
    foreach ($participants as $participant) {
        $participantoptions[$participant->id] = fullname($participant);
    }

    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => (new moodle_url('/mod/kopfuebung/overview.php'))->out(false),
        'class' => 'd-flex align-items-end mb-4',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
    echo html_writer::start_div('form-group mb-0 mr-2');
    echo html_writer::label(get_string('selectparticipant', 'kopfuebung'), 'kopfuebung-userid');
    echo html_writer::select($participantoptions, 'userid', $targetuser->id, false, [
        'id' => 'kopfuebung-userid',
        'class' => 'custom-select',
    ]);
    echo html_writer::end_div();
    echo html_writer::tag('button', get_string('show'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->heading(get_string('resultsof', 'kopfuebung', fullname($targetuser)), 3);
echo html_writer::tag('p', get_string('overviewexplanation', 'kopfuebung'));

if (!$activities) {
    echo $OUTPUT->notification(get_string('noactivities', 'kopfuebung'), 'notifymessage');
} else {
    if ($canmanage) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    }

    $table = new html_table();
    $table->attributes['class'] = 'generaltable kopfuebung-overview';
    $table->head = [get_string('topic', 'kopfuebung')];
    foreach ($activities as $activity) {
        $table->head[] = html_writer::link(
            new moodle_url('/mod/kopfuebung/view.php', ['id' => $activity->cmid]),
            format_string($activity->name)
        );
    }

    for ($position = 1; $position <= 10; $position++) {
        if ($canmanage) {
            $input = html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => 'labels[' . $position . ']',
                'id' => 'kopfuebung-label-' . $position,
                'value' => $labels[$position],
                'maxlength' => 255,
                'class' => 'form-control',
                'placeholder' => get_string('rowlabel', 'kopfuebung', $position),
            ]);
            $rowlabel = html_writer::label(
                get_string('rowprefix', 'kopfuebung', $position),
                'kopfuebung-label-' . $position,
                false,
                ['class' => 'font-weight-bold mr-2']
            );
            $rowlabel .= $input;
        } else {
            $rowlabel = get_string('rowheading', 'kopfuebung', [
                'position' => $position,
                'label' => $labels[$position] !== '' ? s($labels[$position]) : get_string('unlabelled', 'kopfuebung'),
            ]);
        }

        $row = [$rowlabel];
        foreach ($activities as $activity) {
            $state = $matrix[$activity->id]['cells'][$position];
            $indicator = [
                'correct' => ['✓', 'success'],
                'partiallycorrect' => ['◐', 'warning'],
                'incorrect' => ['✕', 'danger'],
                'unanswered' => ['—', 'muted'],
                'notattempted' => ['—', 'muted'],
            ][$state];
            $row[] = html_writer::span(
                $indicator[0] . html_writer::span(
                    get_string($state, 'kopfuebung'),
                    'sr-only'
                ),
                'text-' . $indicator[1] . ' font-weight-bold',
                ['title' => get_string($state, 'kopfuebung')]
            );
        }
        $table->data[] = $row;
    }

    $sumrow = [html_writer::tag('strong', get_string('sum', 'kopfuebung'))];
    foreach ($activities as $activity) {
        $result = $matrix[$activity->id];
        $sumrow[] = $result['attemptid']
            ? get_string('scoreoutof', 'kopfuebung', ['score' => $result['correct'], 'total' => count($result['cells'])])
            : get_string('notattempted', 'kopfuebung');
    }
    $table->data[] = $sumrow;

    echo html_writer::div(html_writer::table($table), 'table-responsive');
    echo html_writer::div(
        get_string('legend', 'kopfuebung') . ': ' .
        '✓ ' . get_string('correct', 'kopfuebung') . ', ' .
        '◐ ' . get_string('partiallycorrect', 'kopfuebung') . ', ' .
        '✕ ' . get_string('incorrect', 'kopfuebung') . ', ' .
        '— ' . get_string('unanswered', 'kopfuebung'),
        'small text-muted mb-3'
    );

    if ($canmanage) {
        echo html_writer::tag('button', get_string('savelabels', 'kopfuebung'), [
            'type' => 'submit',
            'class' => 'btn btn-primary',
        ]);
        echo html_writer::end_tag('form');
    }
}

echo $OUTPUT->footer();
