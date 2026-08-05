<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');
require_once(__DIR__ . '/classes/form/feedback_form.php');

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$editfeedbackid = optional_param('editfeedback', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_login($course);
require_capability('mod/kopfuebung:viewoverview', $context);

$canmanage = has_capability('mod/kopfuebung:manageoverview', $context);
$canfeedback = has_capability('mod/kopfuebung:givefeedback', $context);
$canmanageoffers = has_capability('mod/kopfuebung:manageadditionaloffers', $context);
$canselectparticipant = $canmanage || $canfeedback;
$showallusers = $canselectparticipant && ($userid === 0 || $userid === -1);
$targetuser = $USER;

if ($canselectparticipant && !$showallusers && $userid && $userid != $USER->id) {
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
$gridsections = kopfuebung_get_course_grid_sections($course, $activities, $labelgrids);
$gridsectionsbyendcmid = [];
foreach ($gridsections as $gridsection) {
    $sectionactivities = $gridsection->activities;
    $lastactivity = end($sectionactivities);
    $gridsectionsbyendcmid[(int) $lastactivity->cmid] = $gridsection;
}
$additionaloffers = kopfuebung_get_course_additional_offers($course->id);
$participants = kopfuebung_get_course_participants($course, $activities);

if (!$showallusers && !isset($participants[$targetuser->id])) {
    throw new moodle_exception('invalidparticipant', 'kopfuebung');
}

$feedbacktargetid = $showallusers ? 0 : (int) $targetuser->id;
$canreply = !$showallusers && $feedbacktargetid === (int) $USER->id && $DB->record_exists(
    'kopfuebung_feedback',
    ['courseid' => $course->id, 'userid' => $feedbacktargetid]
);
$editingfeedback = null;
if ($editfeedbackid) {
    if (!$canfeedback) {
        throw new moodle_exception('nopermissions', 'error');
    }
    $editingfeedback = $DB->get_record('kopfuebung_feedback', [
        'id' => $editfeedbackid,
        'courseid' => $course->id,
        'authorid' => $USER->id,
    ], '*', MUST_EXIST);
    $editingfeedbackvisible = $showallusers
        ? empty($editingfeedback->userid)
        : (empty($editingfeedback->userid) || (int) $editingfeedback->userid === $feedbacktargetid);
    if (!$editingfeedbackvisible) {
        throw new moodle_exception('nopermissions', 'error');
    }
}
$feedbackform = new \mod_kopfuebung\form\feedback_form($PAGE->url, [
    'context' => $context,
    'editmode' => (bool) $editingfeedback,
]);
$feedbackformdata = (object) [
    'id' => $course->id,
    'userid' => $showallusers ? -1 : $feedbacktargetid,
    'editfeedback' => $editingfeedback->id ?? 0,
];
if ($editingfeedback) {
    $feedbackformdata->message_editor = [
        'text' => $editingfeedback->message,
        'format' => $editingfeedback->messageformat,
    ];
}
$feedbackform->set_data($feedbackformdata);

if ($feedbackform->is_cancelled()) {
    redirect($PAGE->url);
}

if ($feedbackdata = $feedbackform->get_data()) {
    $maypost = $canfeedback || $canreply;
    if (!$maypost) {
        throw new moodle_exception('nopermissions', 'error');
    }

    $message = $feedbackdata->message_editor;
    $now = time();
    if (!empty($feedbackdata->editfeedback)) {
        if (!$editingfeedback || (int) $feedbackdata->editfeedback !== (int) $editingfeedback->id) {
            throw new moodle_exception('nopermissions', 'error');
        }
        $editingfeedback->message = $message['text'];
        $editingfeedback->messageformat = $message['format'];
        $editingfeedback->timemodified = max($now, (int) $editingfeedback->timecreated + 1);
        $DB->update_record('kopfuebung_feedback', $editingfeedback);
        redirect($PAGE->url, get_string('feedbackupdated', 'kopfuebung'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    $isstudentreply = $feedbacktargetid && (int) $USER->id === $feedbacktargetid;
    $feedbackteacher = null;
    if ($isstudentreply) {
        $previousfeedback = $DB->get_records_select(
            'kopfuebung_feedback',
            'courseid = :courseid AND userid = :userid AND authorid <> :authorid',
            [
                'courseid' => $course->id,
                'userid' => $feedbacktargetid,
                'authorid' => $feedbacktargetid,
            ],
            'timecreated DESC, id DESC',
            'id, authorid',
            0,
            1
        );
        $previousfeedback = reset($previousfeedback);
        if ($previousfeedback) {
            $feedbackteacher = $DB->get_record(
                'user',
                ['id' => $previousfeedback->authorid, 'deleted' => 0],
                '*',
                IGNORE_MISSING
            );
        }
    }

    $DB->insert_record('kopfuebung_feedback', (object) [
        'courseid' => $course->id,
        'userid' => $feedbacktargetid,
        'authorid' => $USER->id,
        'message' => $message['text'],
        'messageformat' => $message['format'],
        'timecreated' => $now,
        'timemodified' => $now,
    ]);

    if ($isstudentreply && $feedbackteacher) {
        kopfuebung_send_feedback_notification(
            $course,
            $USER,
            $feedbackteacher,
            $feedbacktargetid,
            true
        );
    } else if (!$isstudentreply && $feedbacktargetid) {
        kopfuebung_send_feedback_notification($course, $USER, $targetuser, $feedbacktargetid);
    } else if (!$isstudentreply) {
        foreach ($participants as $participant) {
            if ((int) $participant->id !== (int) $USER->id) {
                kopfuebung_send_feedback_notification($course, $USER, $participant, 0);
            }
        }
    }
    redirect($PAGE->url, get_string('feedbacksent', 'kopfuebung'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

if ($canmanage && optional_param('action', '', PARAM_ALPHA) === 'savelabels') {
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
$groupmatrix = kopfuebung_get_group_matrix($activities, array_keys($participants));
$matrix = $showallusers
    ? $groupmatrix
    : kopfuebung_get_user_matrix($activities, $targetuser->id);

if ($canselectparticipant) {
    $PAGE->requires->js_init_code(<<<JS
var participantSelect = document.getElementById('kopfuebung-userid');
if (participantSelect) {
    participantSelect.addEventListener('change', function() {
        if (participantSelect.value !== '') {
            participantSelect.form.submit();
        }
    });
}
JS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('courseoverview', 'kopfuebung'));

if ($canselectparticipant) {
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
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'savelabels']);
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
        if (isset($gridsectionsbyendcmid[$activity->cmid])) {
            $table->head[] = get_string(
                'additionaloffercolumn',
                'kopfuebung',
                $gridsectionsbyendcmid[$activity->cmid]->number
            );
        }
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

    $renderadditionaloffer = static function(stdClass $section, int $position) use (
        $additionaloffers,
        $canmanageoffers,
        $course,
        $context,
        $groupmatrix,
        $matrix,
        $participants,
        $showallusers,
        $targetuser
    ): html_table_cell {
        $cell = new html_table_cell('');
        $cell->attributes['class'] = 'kopfuebung-additional-offer-cell';
        $url = new moodle_url('/mod/kopfuebung/additionaloffer.php', [
            'id' => $course->id,
            'gridid' => $section->gridid,
            'position' => $position,
        ]);
        $label = trim($section->labels[$position] ?? '');
        if ($label === '') {
            $label = get_string('unlabelled', 'kopfuebung');
        }

        if ($canmanageoffers) {
            $offer = $additionaloffers[$section->gridid][$position] ?? null;
            $iconclass = 'kopfuebung-additional-offer-teacher-icon';
            if ($offer) {
                $iconclass .= ' kopfuebung-additional-offer-configured';
            }
            $content = html_writer::link(
                $url,
                html_writer::span('↗', $iconclass, ['aria-hidden' => 'true']),
                [
                    'class' => 'kopfuebung-additional-offer-link',
                    'aria-label' => get_string('configureadditionaloffer', 'kopfuebung', $label),
                ]
            );
            $cell->attributes['title'] = get_string('configureadditionaloffer', 'kopfuebung', $label);
            if ($offer) {
                $incorrectbyuser = array_fill_keys(array_keys($participants), 0);
                foreach ($section->activities as $activity) {
                    if ($activity->activitystate) {
                        continue;
                    }
                    foreach ($groupmatrix[$activity->id]['cells'][$position]['incorrectuserids'] as $userid) {
                        if (isset($incorrectbyuser[$userid])) {
                            $incorrectbyuser[$userid]++;
                        }
                    }
                }
                $conditioncount = count(array_filter($incorrectbyuser, static function(int $count) use ($offer): bool {
                    return $count >= (int) $offer->threshold;
                }));
                $individualcount = count(array_intersect(array_keys($participants), $offer->userids));
                $countdata = (object) [
                    'condition' => $conditioncount,
                    'individual' => $individualcount,
                    'total' => count($participants),
                ];
                $content .= html_writer::span(
                    get_string('additionaloffercounts', 'kopfuebung', $countdata),
                    'kopfuebung-additional-offer-counts'
                );
                $cell->attributes['title'] = get_string(
                    'additionaloffercountsactual',
                    'kopfuebung',
                    $countdata
                );
            }
            $cell->text = $content;
            return $cell;
        }

        if ($showallusers || empty($additionaloffers[$section->gridid][$position])) {
            return $cell;
        }
        $offer = $additionaloffers[$section->gridid][$position];
        $incorrectcount = kopfuebung_count_grid_incorrect($section, $matrix, $position);
        $isindividual = in_array((int) $targetuser->id, $offer->userids, true);
        if (!$isindividual && $incorrectcount < (int) $offer->threshold) {
            return $cell;
        }

        $tooltipblocks = [];
        if ($isindividual) {
            $tooltipblocks[] = get_string('additionalofferindividualreason', 'kopfuebung');
        } else {
            $tooltipblocks[] = get_string('incorrectcategorycount', 'kopfuebung', [
                'category' => $label,
                'count' => $incorrectcount,
            ]);
        }
        if (trim((string) $offer->hint) !== '') {
            $tooltipblocks[] = content_to_text(
                format_text($offer->hint, $offer->hintformat, ['context' => $context]),
                FORMAT_HTML
            );
        }
        $explanations = kopfuebung_resolve_offer_links($course, $offer, 'explanation');
        if ($explanations) {
            $explanationblock = get_string('explanationlinkintro', 'kopfuebung');
            foreach ($explanations as $explanation) {
                $explanationblock .= "\n" . $explanation['name'] . ' — ' . $explanation['url']->out(false);
            }
            $tooltipblocks[] = $explanationblock;
        }
        $practiceitems = kopfuebung_resolve_offer_links($course, $offer, 'practice');
        if ($practiceitems) {
            $practiceblock = get_string('practicelinkintro', 'kopfuebung');
            foreach ($practiceitems as $practice) {
                $practiceblock .= "\n" . $practice['name'] . ' — ' . $practice['url']->out(false);
            }
            $tooltipblocks[] = $practiceblock;
        }
        $tooltip = implode("\n\n", $tooltipblocks);
        $cell->text = html_writer::link(
            $url,
            html_writer::span('!', 'kopfuebung-additional-offer-student-icon', ['aria-hidden' => 'true']),
            [
                'class' => 'kopfuebung-additional-offer-link',
                'title' => $tooltip,
                'aria-label' => get_string('viewadditionaloffer', 'kopfuebung', $label),
            ]
        );
        return $cell;
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
                if (isset($gridsectionsbyendcmid[$activity->cmid])) {
                    $row[] = $renderadditionaloffer($gridsectionsbyendcmid[$activity->cmid], $position);
                }
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
                $assessment = !empty($activity->selfassessment) && $cell['assessmentcount'] > 0
                    ? round(100 * $cell['assessmentmatches'] / $cell['assessmentcount']) . '%'
                    : get_string('notavailableabbr', 'kopfuebung');
                $difficulty = !empty($activity->difficultyassessment) && $cell['difficultycount'] > 0
                    ? format_float($cell['difficultytotal'] / $cell['difficultycount'], 1)
                    : get_string('notavailableabbr', 'kopfuebung');
                $content .= html_writer::div('(' . $assessment . ' / ' . $difficulty . ')',
                    'small text-muted kopfuebung-reflection-summary');
                $row[] = $content . $renderdetailslink($activity, $position);
                if (isset($gridsectionsbyendcmid[$activity->cmid])) {
                    $row[] = $renderadditionaloffer($gridsectionsbyendcmid[$activity->cmid], $position);
                }
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
            if (!empty($matrix[$activity->id]['finished']) &&
                    ($canselectparticipant || !$activity->activitystate)) {
                $content = html_writer::link(
                    new moodle_url('/mod/kopfuebung/review.php', [
                        'id' => $activity->cmid,
                        'userid' => $targetuser->id,
                    ], 'question-' . $position),
                    $content,
                    [
                        'class' => 'kopfuebung-review-link',
                        'title' => get_string('reviewquestion', 'kopfuebung', $position),
                    ]
                );
            }
            $content .= $renderdetailslink($activity, $position);
            $cell = new html_table_cell($content);
            $cell->attributes['class'] = 'kopfuebung-result-cell kopfuebung-result-' . $state;
            $row[] = $cell;
            if (isset($gridsectionsbyendcmid[$activity->cmid])) {
                $row[] = $renderadditionaloffer($gridsectionsbyendcmid[$activity->cmid], $position);
            }
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
            $totalpossible = $result['participantcount'] * (int) $activity->questioncount;
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
                    'total' => (int) $activity->questioncount,
                ]);
            } else {
                $sumrow[] = get_string('notattempted', 'kopfuebung');
            }
        }
        if (isset($gridsectionsbyendcmid[$activity->cmid])) {
            $sumrow[] = '';
        }
    }
    $table->data[] = $sumrow;

    if (!$showallusers) {
        $assessmentrow = [html_writer::tag('strong', get_string('selfassessmentresult', 'kopfuebung'))];
        $accuracyrow = [html_writer::tag('strong', get_string('assessmentaccuracy', 'kopfuebung'))];
        foreach ($activities as $activity) {
            if (isset($labelgrids[$activity->cmid])) {
                $assessmentrow[] = '';
                $accuracyrow[] = '';
            }
            $result = $matrix[$activity->id];
            if (!$canmanage && $activity->activitystate) {
                $assessmentrow[] = get_string('resultswithheldshort', 'kopfuebung');
                $accuracyrow[] = get_string('resultswithheldshort', 'kopfuebung');
            } else if (!empty($activity->selfassessment) &&
                    $result['selfassessedcount'] === (int) $activity->questioncount) {
                $assessmentrow[] = get_string('reflectionpercentage', 'kopfuebung', [
                    'percentage' => (int) round(100 * $result['selfassessedcorrect'] / $activity->questioncount),
                    'count' => $result['selfassessedcorrect'],
                    'total' => $activity->questioncount,
                ]);
                $accuracyrow[] = get_string('reflectionpercentage', 'kopfuebung', [
                    'percentage' => (int) round(100 * $result['assessmentmatches'] / $activity->questioncount),
                    'count' => $result['assessmentmatches'],
                    'total' => $activity->questioncount,
                ]);
            } else {
                $assessmentrow[] = get_string('notavailableabbr', 'kopfuebung');
                $accuracyrow[] = get_string('notavailableabbr', 'kopfuebung');
            }
            if (isset($gridsectionsbyendcmid[$activity->cmid])) {
                $assessmentrow[] = '';
                $accuracyrow[] = '';
            }
        }
        $table->data[] = $assessmentrow;
        $table->data[] = $accuracyrow;
    }

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

$feedbackparams = ['courseid' => $course->id];
if ($showallusers) {
    $feedbackwhere = 'courseid = :courseid AND userid = 0';
} else {
    $feedbackwhere = 'courseid = :courseid AND (userid = 0 OR userid = :userid)';
    $feedbackparams['userid'] = $feedbacktargetid;
}
$feedbackmessages = $DB->get_records_select(
    'kopfuebung_feedback',
    $feedbackwhere,
    $feedbackparams,
    'timecreated ASC, id ASC'
);

echo $OUTPUT->heading(get_string('feedback', 'kopfuebung'), 3);
if (!$feedbackmessages) {
    echo $OUTPUT->notification(get_string('nofeedbackyet', 'kopfuebung'), 'notifymessage');
}
foreach ($feedbackmessages as $feedbackmessage) {
    $author = $DB->get_record('user', ['id' => $feedbackmessage->authorid], '*', MUST_EXIST);
    $iscoursefeedback = empty($feedbackmessage->userid);
    $scope = get_string($iscoursefeedback ? 'feedbackforcourse' : 'individualfeedback', 'kopfuebung');
    $meta = html_writer::tag('strong', fullname($author)) . ' · ' .
        userdate($feedbackmessage->timecreated) . ' · ' .
        html_writer::span($scope, 'badge ' . ($iscoursefeedback ? 'badge-primary' : 'badge-warning'));
    if ((int) $feedbackmessage->timemodified > (int) $feedbackmessage->timecreated) {
        $meta .= ' · ' . html_writer::span(get_string('feedbackedited', 'kopfuebung'), 'text-muted');
    }
    $content = format_text($feedbackmessage->message, $feedbackmessage->messageformat, [
        'context' => $context,
        'para' => false,
    ]);
    $classes = 'kopfuebung-feedback-message ' .
        ($iscoursefeedback ? 'kopfuebung-feedback-course' : 'kopfuebung-feedback-individual');
    if ((int) $feedbackmessage->authorid === (int) $USER->id) {
        $classes .= ' kopfuebung-feedback-own-message';
    }
    if ($canfeedback && (int) $feedbackmessage->authorid === (int) $USER->id) {
        $editparams = $urlparams;
        $editparams['editfeedback'] = $feedbackmessage->id;
        $content .= html_writer::div(html_writer::link(
            new moodle_url('/mod/kopfuebung/overview.php', $editparams),
            get_string('editfeedback', 'kopfuebung'),
            ['class' => 'btn btn-link btn-sm p-0 mt-2']
        ));
    }
    echo html_writer::div(html_writer::div($meta, 'kopfuebung-feedback-meta') . $content, $classes);
}

if ($canfeedback || $canreply) {
    $feedbackheading = $editingfeedback
        ? get_string('editfeedbackheading', 'kopfuebung')
        : ($showallusers
            ? get_string('feedbackforallparticipants', 'kopfuebung')
            : get_string($canfeedback ? 'feedbackforuser' : 'replytofeedback', 'kopfuebung', fullname($targetuser)));
    echo $OUTPUT->heading($feedbackheading, 4);
    $feedbackform->display();
}

echo $OUTPUT->footer();
