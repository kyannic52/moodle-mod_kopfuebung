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
$showallusers = $canmanage && ($userid === 0 || $userid === -1);
$targetuser = $USER;

if ($canmanage && !$showallusers && $userid && $userid != $USER->id) {
    $targetuser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
    if (!is_enrolled($context, $targetuser, '', true)) {
        throw new moodle_exception('notenrolled', 'enrol');
    }
}

$urlparams = ['id' => $course->id];
if ($showallusers) {
    $urlparams['userid'] = -1;
} else if ($targetuser->id != $USER->id) {
    $urlparams['userid'] = $targetuser->id;
}
$PAGE->set_url('/mod/kopfuebung/overview.php', $urlparams);
$PAGE->set_title(get_string('courseoverview', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$activities = kopfuebung_get_course_activities($course);
$defaultlabels = kopfuebung_get_course_labels($course->id);
$labelgrids = kopfuebung_get_course_label_grids($course->id);

if ($canmanage && data_submitted()) {
    require_sesskey();
    $submittedlabels = optional_param_array('labels', [], PARAM_TEXT);
    $labelsbygrid = [];
    $validgridids = [0 => true];
    foreach ($labelgrids as $grid) {
        $validgridids[(int) $grid->id] = true;
    }
    foreach ($submittedlabels as $key => $label) {
        if (preg_match('/^(\d+)_(\d+)$/', (string) $key, $matches)) {
            $gridid = (int) $matches[1];
            $position = (int) $matches[2];
            if (isset($validgridids[$gridid]) && $position >= 1 && $position <= 10) {
                $labelsbygrid[$gridid][$position] = $label;
            }
        }
    }
    foreach ($labelsbygrid as $gridid => $gridlabels) {
        kopfuebung_save_course_labels($course->id, $gridlabels, $gridid);
    }

    $newgridbefore = optional_param('newgridbefore', 0, PARAM_INT);
    if ($newgridbefore) {
        kopfuebung_create_course_label_grid($course->id, $newgridbefore);
        redirect($PAGE->url, get_string('labelgridcreated', 'kopfuebung'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect($PAGE->url, get_string('labelssaved', 'kopfuebung'), null, \core\output\notification::NOTIFY_SUCCESS);
}
$participants = kopfuebung_get_course_participants($course, $activities);
$groupmatrix = kopfuebung_get_group_matrix($activities, array_keys($participants));
$matrix = $showallusers
    ? $groupmatrix
    : kopfuebung_get_user_matrix($activities, $targetuser->id);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('courseoverview', 'kopfuebung'));

if ($canmanage) {
    echo html_writer::start_tag('form', [
        'method' => 'get',
        'action' => (new moodle_url('/mod/kopfuebung/overview.php'))->out(false),
        'class' => 'd-flex align-items-end mb-4',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $course->id]);
    echo html_writer::start_div('form-group mb-0 mr-2');
    echo html_writer::label(get_string('selectparticipant', 'kopfuebung'), 'kopfuebung-userid');
    echo html_writer::start_tag('select', [
        'name' => 'userid',
        'id' => 'kopfuebung-userid',
        'class' => 'custom-select',
    ]);
    echo html_writer::tag('option', get_string('allparticipants', 'kopfuebung'), [
        'value' => -1,
        'selected' => $showallusers ? 'selected' : null,
    ]);
    echo html_writer::tag('option', get_string('participantseparator', 'kopfuebung'), [
        'value' => '',
        'disabled' => 'disabled',
    ]);
    foreach ($participants as $participant) {
        echo html_writer::tag('option', fullname($participant), [
            'value' => $participant->id,
            'selected' => !$showallusers && $targetuser->id == $participant->id ? 'selected' : null,
        ]);
    }
    echo html_writer::end_tag('select');
    echo html_writer::end_div();
    echo html_writer::tag('button', get_string('show'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');

    echo $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/labelgrids.php', ['id' => $course->id]),
        get_string('managelabelgrids', 'kopfuebung'),
        'get',
        ['class' => 'mb-4']
    );
}

echo $OUTPUT->heading(
    $showallusers
        ? get_string('resultsofallparticipants', 'kopfuebung')
        : get_string('resultsof', 'kopfuebung', fullname($targetuser)),
    3
);
echo html_writer::tag('p', get_string(
    $showallusers ? 'groupoverviewexplanation' : 'overviewexplanation',
    'kopfuebung'
));

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
    foreach ($activities as $activityindex => $activity) {
        if (isset($labelgrids[$activity->cmid])) {
            $table->head[] = get_string('topic', 'kopfuebung');
        }
        $activityheading = html_writer::link(
            new moodle_url('/mod/kopfuebung/view.php', ['id' => $activity->cmid]),
            format_string($activity->name)
        );
        if ($canmanage && $activityindex > 0 && !isset($labelgrids[$activity->cmid])) {
            $activityheading .= html_writer::div(html_writer::tag(
                'button',
                get_string('newgridbeforeactivity', 'kopfuebung'),
                [
                    'type' => 'submit',
                    'name' => 'newgridbefore',
                    'value' => $activity->cmid,
                    'class' => 'btn btn-link btn-sm p-0 mt-1',
                ]
            ));
        }
        $table->head[] = $activityheading;
    }

    $renderlabel = static function(int $gridid, array $gridlabels, int $position) use ($canmanage): string {
        if ($canmanage) {
            $inputid = 'kopfuebung-label-' . $gridid . '-' . $position;
            $input = html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => 'labels[' . $gridid . '_' . $position . ']',
                'id' => $inputid,
                'value' => $gridlabels[$position],
                'maxlength' => 255,
                'class' => 'form-control',
                'placeholder' => get_string('rowlabel', 'kopfuebung', $position),
            ]);
            return html_writer::label(
                get_string('rowprefix', 'kopfuebung', $position),
                $inputid,
                false,
                ['class' => 'font-weight-bold mr-2']
            ) . $input;
        }

        return get_string('rowheading', 'kopfuebung', [
            'position' => $position,
            'label' => $gridlabels[$position] !== ''
                ? s($gridlabels[$position])
                : get_string('unlabelled', 'kopfuebung'),
        ]);
    };

    $renderdetailslink = static function(stdClass $activity, int $position) use (
        $canmanage,
        $course,
        $groupmatrix,
        $participants
    ): string {
        if (!$canmanage) {
            return '';
        }

        $incorrectnames = [];
        foreach ($groupmatrix[$activity->id]['cells'][$position]['incorrectuserids'] as $participantid) {
            if (isset($participants[$participantid])) {
                $incorrectnames[] = fullname($participants[$participantid]);
            }
        }
        $tooltip = get_string('incorrectparticipantstooltip', 'kopfuebung') . "\n\n" .
            ($incorrectnames
                ? implode("\n", $incorrectnames)
                : get_string('noincorrectparticipants', 'kopfuebung'));

        return ' ' . html_writer::link(
            new moodle_url('/mod/kopfuebung/resultdetail.php', [
                'id' => $activity->cmid,
                'position' => $position,
            ]),
            html_writer::span('i', 'kopfuebung-info-symbol', ['aria-hidden' => 'true']),
            [
                'class' => 'kopfuebung-result-details',
                'title' => $tooltip,
                'aria-label' => get_string('showparticipantdetails', 'kopfuebung'),
            ]
        );
    };

    for ($position = 1; $position <= 10; $position++) {
        $row = [$renderlabel(0, $defaultlabels, $position)];
        foreach ($activities as $activity) {
            if (isset($labelgrids[$activity->cmid])) {
                $grid = $labelgrids[$activity->cmid];
                $row[] = $renderlabel((int) $grid->id, $grid->labels, $position);
            }
            if (!$canmanage && $activity->activitystate) {
                $row[] = html_writer::span(
                    get_string('resultswithheldshort', 'kopfuebung'),
                    'text-muted',
                    ['title' => get_string('resultsavailableafteractivity', 'kopfuebung')]
                );
                continue;
            }

            if ($showallusers) {
                $cell = $matrix[$activity->id]['cells'][$position];
                $percentage = $matrix[$activity->id]['participantcount'] > 0
                    ? (int) round(100 * $cell['correct'] / $matrix[$activity->id]['participantcount'])
                    : 0;
                $content = get_string('groupresult', 'kopfuebung', [
                    'percentage' => $percentage,
                    'answered' => $cell['answered'],
                ]);
                $row[] = $content . $renderdetailslink($activity, $position);
                continue;
            }

            $state = $matrix[$activity->id]['cells'][$position];
            $comparison = $groupmatrix[$activity->id]['cells'][$position];
            $indicator = [
                'correct' => ['✓', 'success'],
                'partiallycorrect' => ['◐', 'warning'],
                'incorrect' => ['✕', 'danger'],
                'unanswered' => ['—', 'muted'],
                'notattempted' => ['—', 'muted'],
            ][$state];
            $content = html_writer::span(
                $indicator[0] . html_writer::span(
                    get_string($state, 'kopfuebung'),
                    'sr-only'
                ),
                'text-' . $indicator[1] . ' font-weight-bold',
                ['title' => get_string($state, 'kopfuebung')]
            );
            $content .= ' ' . html_writer::span(
                get_string('classcomparison', 'kopfuebung', [
                    'correct' => $comparison['correct'],
                    'answered' => $comparison['answered'],
                ]),
                'kopfuebung-comparison'
            );
            $content .= $renderdetailslink($activity, $position);
            $cell = new html_table_cell($content);
            $cell->attributes['class'] = 'kopfuebung-result-cell kopfuebung-result-' . $state;
            $row[] = $cell;
        }
        $table->data[] = $row;
    }

    $sumrow = [html_writer::tag('strong', get_string('sum', 'kopfuebung'))];
    foreach ($activities as $activity) {
        if (isset($labelgrids[$activity->cmid])) {
            $sumrow[] = html_writer::tag('strong', get_string('sum', 'kopfuebung'));
        }
        $result = $matrix[$activity->id];
        if ($showallusers) {
            $totalpossible = $result['participantcount'] * count($result['cells']);
            $percentage = $totalpossible > 0
                ? (int) round(100 * $result['correct'] / $totalpossible)
                : 0;
            $sumrow[] = $percentage . '%';
        } else {
            if (!$canmanage && $activity->activitystate) {
                $sumrow[] = get_string('resultswithheldshort', 'kopfuebung');
            } else if ($result['attemptid']) {
                $sumrow[] = get_string('scoreoutof', 'kopfuebung', [
                    'score' => $result['correct'],
                    'total' => count($result['cells']),
                ]);
            } else {
                $sumrow[] = get_string('notattempted', 'kopfuebung');
            }
        }
    }
    $table->data[] = $sumrow;

    echo html_writer::div(html_writer::table($table), 'table-responsive');
    if (!$showallusers) {
        echo html_writer::div(
            get_string('legend', 'kopfuebung') . ': ' .
            '✓ ' . get_string('correct', 'kopfuebung') . ', ' .
            '◐ ' . get_string('partiallycorrect', 'kopfuebung') . ', ' .
            '✕ ' . get_string('incorrect', 'kopfuebung') . ', ' .
            '— ' . get_string('unanswered', 'kopfuebung'),
            'small text-muted mb-3'
        );
    }

    if ($canmanage) {
        echo html_writer::tag('button', get_string('savelabels', 'kopfuebung'), [
            'type' => 'submit',
            'class' => 'btn btn-primary',
        ]);
        echo html_writer::end_tag('form');
    }
}

echo $OUTPUT->footer();
