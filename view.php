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
require_capability('mod/kopfuebung:view', $context);

kopfuebung_close_expired_activity($kopfuebung);

if (($kopfuebung->activitytype ?? 'exercise') === 'overview') {
    require_capability('mod/kopfuebung:viewoverview', context_course::instance($course->id));
    redirect(new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id]));
}

$canattempt = has_capability('mod/kopfuebung:attempt', $context);
$canstart = has_capability('mod/kopfuebung:startactivity', $context);
$canmanagequestions = has_capability('mod/kopfuebung:managequestions', $context);
$missingquestioncount = $canmanagequestions
    ? optional_param('missingquestions', 0, PARAM_INT)
    : 0;
$userready = $canattempt && $DB->record_exists('kopfuebung_ready', [
    'kopfuebungid' => $kopfuebung->id,
    'userid' => $USER->id,
]);
$readycount = $canstart ? $DB->count_records('kopfuebung_ready', ['kopfuebungid' => $kopfuebung->id]) : 0;
$attempt = $canattempt ? kopfuebung_get_current_attempt($kopfuebung, $USER->id) : null;
$finishedattempt = $canattempt
    ? kopfuebung_get_latest_finished_attempt($kopfuebung->id, $USER->id)
    : null;
$attemptfinished = (bool) $finishedattempt;
if ($attemptfinished && kopfuebung_reflection_pending($kopfuebung, $finishedattempt)) {
    redirect(new moodle_url('/mod/kopfuebung/reflection.php', ['id' => $cm->id]));
}
if ($attemptfinished) {
    $userready = false;
}

$remainingseconds = (int) $kopfuebung->timelimit;
if ($canattempt && $kopfuebung->activitystate) {
    $remainingseconds = max(0, $kopfuebung->timestarted + $kopfuebung->timelimit - time());
    if ($attempt && $attempt->status === 'inprogress') {
        $remainingseconds = max(0, $attempt->timestarted + $kopfuebung->timelimit - time());
    }
}

$PAGE->set_url('/mod/kopfuebung/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($kopfuebung->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$statusurl = new moodle_url('/mod/kopfuebung/status.php', ['id' => $cm->id]);
$attempturl = new moodle_url('/mod/kopfuebung/attempt.php', ['id' => $cm->id]);
$initialstate = $kopfuebung->activitystate ? 'true' : 'false';
$canattemptjs = $canattempt ? 'true' : 'false';
$canstartjs = $canstart ? 'true' : 'false';
$userreadyjs = $userready ? 'true' : 'false';
$attemptfinishedjs = $attemptfinished ? 'true' : 'false';
$startedtemplate = json_encode(get_string('activitystartedtime', 'kopfuebung', '__TIME__'));
$PAGE->requires->js_init_code("
var statusUrl = " . json_encode($statusurl->out(false)) . ";
var attemptUrl = " . json_encode($attempturl->out(false)) . ";
var initialState = $initialstate;
var canAttempt = $canattemptjs;
var canStart = $canstartjs;
var userReady = $userreadyjs;
var attemptFinished = $attemptfinishedjs;
var remainingSeconds = " . (int) $remainingseconds . ";
var statusMessage = document.getElementById('kopfuebung-status-message');
var readyCount = document.getElementById('kopfuebung-ready-count');
var formatTime = function(seconds) {
    seconds = Math.max(0, parseInt(seconds, 10) || 0);
    var minutes = Math.floor(seconds / 60);
    var remainder = seconds % 60;
    return (minutes < 10 ? '0' : '') + minutes + ':' + (remainder < 10 ? '0' : '') + remainder;
};
var updateStartedMessage = function() {
    if (canAttempt && statusMessage && initialState) {
        statusMessage.textContent = $startedtemplate.replace('__TIME__', formatTime(remainingSeconds));
    }
};
if (initialState && canAttempt) {
    updateStartedMessage();
    window.setInterval(function() {
        if (remainingSeconds > 0) {
            remainingSeconds--;
            updateStartedMessage();
        }
    }, 1000);
}
var pollStatus = function() {
    fetch(statusUrl, {credentials: 'same-origin', cache: 'no-store'})
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Status request failed');
            }
            return response.json();
        })
        .then(function(data) {
            if (canStart && readyCount && typeof data.readycount !== 'undefined') {
                readyCount.textContent = data.readycount;
            }
            if (canAttempt) {
                userReady = data.userready;
                attemptFinished = data.attemptfinished;
                remainingSeconds = data.remainingseconds;
                if (data.activitystate && userReady && !attemptFinished) {
                    window.location.assign(attemptUrl);
                    return;
                }
                if (data.activitystate !== initialState) {
                    window.location.reload();
                    return;
                }
                if (data.activitystate) {
                    initialState = true;
                    updateStartedMessage();
                }
            }
        })
        .catch(function() {
            // A temporary polling failure should not interrupt the page.
        });
};
window.setInterval(pollStatus, 2000);
");

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($kopfuebung->name));
echo format_module_intro('kopfuebung', $kopfuebung, $cm->id);

if ($missingquestioncount > 0) {
    $settingsurl = new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]);
    $managequestionsurl = new moodle_url('/mod/kopfuebung/manage.php', ['id' => $cm->id]);
    echo html_writer::start_div('kopfuebung-modal-backdrop', [
        'id' => 'kopfuebung-missing-questions-modal',
        'role' => 'dialog',
        'aria-modal' => 'true',
        'aria-labelledby' => 'kopfuebung-missing-questions-title',
        'aria-describedby' => 'kopfuebung-missing-questions-description',
    ]);
    echo html_writer::start_div('kopfuebung-modal card shadow-lg');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string('missingquestionassignmentsheading', 'kopfuebung'), [
        'id' => 'kopfuebung-missing-questions-title',
        'class' => 'card-title',
    ]);
    $promptstring = $missingquestioncount === 1
        ? 'missingquestionassignmentpromptone'
        : 'missingquestionassignmentsprompt';
    echo html_writer::tag('p', get_string($promptstring, 'kopfuebung', $missingquestioncount), [
        'id' => 'kopfuebung-missing-questions-description',
    ]);
    echo html_writer::start_div('kopfuebung-modal-actions');
    echo html_writer::link($settingsurl, get_string('adjustquestioncount', 'kopfuebung'), [
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::link($managequestionsurl, get_string('assignmissingquestions', 'kopfuebung'), [
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::tag('button', get_string('cancel'), [
        'type' => 'button',
        'class' => 'btn btn-link',
        'id' => 'kopfuebung-close-missing-questions',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();

    $PAGE->requires->js_init_code(<<<JS
var missingQuestionsModal = document.getElementById('kopfuebung-missing-questions-modal');
var closeMissingQuestions = document.getElementById('kopfuebung-close-missing-questions');
if (missingQuestionsModal && closeMissingQuestions) {
    var closeMissingQuestionsModal = function() {
        missingQuestionsModal.remove();
    };
    closeMissingQuestions.addEventListener('click', closeMissingQuestionsModal);
    missingQuestionsModal.addEventListener('click', function(event) {
        if (event.target === missingQuestionsModal) {
            closeMissingQuestionsModal();
        }
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && document.body.contains(missingQuestionsModal)) {
            closeMissingQuestionsModal();
        }
    });
    closeMissingQuestions.focus();
}
JS
    );
}

if ($canattempt) {
    if ($attemptfinished) {
        $statusstring = get_string('attemptalreadysubmitted', 'kopfuebung');
        $statusclass = 'alert alert-info';
    } else if ($kopfuebung->activitystate) {
        $statusstring = get_string('activitystartedtime', 'kopfuebung', sprintf(
            '%02d:%02d',
            floor($remainingseconds / 60),
            $remainingseconds % 60
        ));
        $statusclass = 'alert alert-success';
    } else {
        $statusstring = get_string('activityclosedwithtime', 'kopfuebung', format_time($kopfuebung->timelimit));
        $statusclass = 'alert alert-warning';
    }
} else {
    $statusstring = $kopfuebung->activitystate
        ? get_string('activityisopen', 'kopfuebung')
        : get_string('activityisclosed', 'kopfuebung');
    $statusclass = $kopfuebung->activitystate ? 'alert alert-success' : 'alert alert-warning';
}
echo html_writer::div($statusstring, $statusclass, ['id' => 'kopfuebung-status-message']);

if ($canattempt && !$attemptfinished && !$kopfuebung->activitystate && $userready) {
    echo $OUTPUT->notification(get_string('readinessreported', 'kopfuebung'), 'notifysuccess');
}
if ($canstart) {
    echo html_writer::div(
        get_string('readycount', 'kopfuebung') . ': ' .
            html_writer::span($readycount, '', ['id' => 'kopfuebung-ready-count']),
        'alert alert-info'
    );
}

$buttons = [];
if (has_capability('mod/kopfuebung:viewoverview', context_course::instance($course->id))) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id]),
        get_string('courseoverview', 'kopfuebung'),
        'get'
    );
}
if (has_capability('mod/kopfuebung:managequestions', $context)) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/manage.php', ['id' => $cm->id]),
        get_string('managequestions', 'kopfuebung'),
        'get'
    );
}
if ($canstart) {
    $action = $kopfuebung->activitystate ? 'stop' : 'start';
    $label = $kopfuebung->activitystate ? get_string('stopactivity', 'kopfuebung') : get_string('startactivity', 'kopfuebung');
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/start.php', ['id' => $cm->id, 'action' => $action, 'sesskey' => sesskey()]),
        $label,
        'post'
    );
}
if (has_capability('mod/kopfuebung:viewreports', $context)) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/report.php', ['id' => $cm->id]),
        get_string('report', 'kopfuebung'),
        'get'
    );
}
if ($canattempt && !$attemptfinished && !$kopfuebung->activitystate && !$userready) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/ready.php', ['id' => $cm->id, 'sesskey' => sesskey()]),
        get_string('iamready', 'kopfuebung'),
        'post',
        ['primary' => true]
    );
}
if ($canattempt && !$attemptfinished && $kopfuebung->activitystate) {
    $buttons[] = $OUTPUT->single_button(
        $attempturl,
        get_string($attempt ? 'continueattempt' : 'attemptactivity', 'kopfuebung'),
        'get',
        ['primary' => true]
    );
}
if ($canattempt && $attemptfinished && !$kopfuebung->activitystate) {
    $buttons[] = $OUTPUT->single_button(
        new moodle_url('/mod/kopfuebung/review.php', ['id' => $cm->id]),
        get_string('reviewattempt', 'kopfuebung'),
        'get',
        ['primary' => true]
    );
}

echo html_writer::div(implode(' ', $buttons), 'kopfuebung-actions');

if (has_capability('mod/kopfuebung:resetattempts', $context)) {
    $activityattempts = $DB->get_records(
        'kopfuebung_attempts',
        ['kopfuebungid' => $kopfuebung->id],
        'userid ASC, id DESC'
    );
    $latestbyuser = [];
    foreach ($activityattempts as $activityattempt) {
        if (!isset($latestbyuser[$activityattempt->userid])) {
            $latestbyuser[$activityattempt->userid] = $activityattempt;
        }
    }
    if ($latestbyuser) {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable mt-4';
        $table->head = [
            get_string('participant', 'kopfuebung'),
            get_string('attemptstatus', 'kopfuebung'),
            get_string('attempttime', 'kopfuebung'),
            get_string('actions'),
        ];
        foreach ($latestbyuser as $studentattempt) {
            $student = $DB->get_record('user', ['id' => $studentattempt->userid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$student || !is_enrolled($context, $student, '', true)) {
                continue;
            }
            $actions = '';
            if ($studentattempt->status === 'finished') {
                $actions .= html_writer::link(
                    new moodle_url('/mod/kopfuebung/review.php', [
                        'id' => $cm->id,
                        'userid' => $student->id,
                    ]),
                    get_string('reviewattempt', 'kopfuebung'),
                    ['class' => 'btn btn-secondary btn-sm mr-2']
                );
            }
            $actions .= html_writer::link(
                new moodle_url('/mod/kopfuebung/resetattempt.php', [
                    'id' => $cm->id,
                    'userid' => $student->id,
                ]),
                get_string('resetattempt', 'kopfuebung'),
                ['class' => 'btn btn-danger btn-sm']
            );
            $table->data[] = [
                fullname($student),
                get_string('attemptstatus' . $studentattempt->status, 'kopfuebung'),
                userdate($studentattempt->timefinished ?: $studentattempt->timestarted),
                $actions,
            ];
        }
        echo $OUTPUT->heading(get_string('attemptmanagement', 'kopfuebung'), 3);
        echo html_writer::div(html_writer::table($table), 'table-responsive');
    }
}
echo $OUTPUT->footer();
