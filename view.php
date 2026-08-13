<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
$coursecontext = context_course::instance($course->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:view', $context);
kopfuebung_close_expired_activity($kopfuebung);

if (($kopfuebung->activitytype ?? 'exercise') === 'overview') {
    require_capability('mod/kopfuebung:viewoverview', $coursecontext);
    redirect(new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id]));
}

$canattempt = has_capability('mod/kopfuebung:attempt', $context);
$canstart = has_capability('mod/kopfuebung:startactivity', $context);
$canmanagequestions = has_capability('mod/kopfuebung:managequestions', $context);
$canviewreports = has_capability('mod/kopfuebung:viewreports', $context);
$canviewoverview = has_capability('mod/kopfuebung:viewoverview', $coursecontext);
$canresetattempts = has_capability('mod/kopfuebung:resetattempts', $context);
$isteacher = $canstart || $canmanagequestions || $canviewreports || $canresetattempts;
$isstudent = $canattempt && !$canstart;
$questioncount = (int) ($kopfuebung->questioncount ?: 10);
$assignedquestioncount = $DB->count_records_select(
    'kopfuebung_questions',
    'kopfuebungid = :assignedactivity AND sortorder >= 1 AND sortorder <= :assignedcount',
    ['assignedactivity' => $kopfuebung->id, 'assignedcount' => $questioncount]
);
$questionscomplete = $assignedquestioncount >= $questioncount;
$participantids = array_keys(get_enrolled_users($context, 'mod/kopfuebung:attempt', 0, 'u.id', null, 0, 0, true));
// A teacher may inherit the attempt capability, but must not count as a participating student.
$participantids = array_values(array_filter($participantids, static function($userid) use ($context) {
    return !has_capability('mod/kopfuebung:startactivity', $context, $userid);
}));
$participantcount = count($participantids);
$readycount = 0;
if ($participantids && ($isstudent || $isteacher)) {
    [$readyinsql, $readyparams] = $DB->get_in_or_equal($participantids, SQL_PARAMS_NAMED, 'readyuser');
    $readycount = $DB->count_records_select(
        'kopfuebung_ready',
        'kopfuebungid = :readyactivity AND userid ' . $readyinsql,
        ['readyactivity' => $kopfuebung->id] + $readyparams
    );
}
$readypercentage = $participantcount ? min(100, round(100 * $readycount / $participantcount)) : 0;
$allready = $participantcount > 0 && $readycount >= $participantcount;
$missingquestioncount = $canmanagequestions ? optional_param('missingquestions', 0, PARAM_INT) : 0;

$userready = $isstudent && $DB->record_exists('kopfuebung_ready', [
    'kopfuebungid' => $kopfuebung->id, 'userid' => $USER->id,
]);
$attempt = $isstudent ? kopfuebung_get_current_attempt($kopfuebung, $USER->id) : null;
$finishedattempt = $isstudent ? kopfuebung_get_latest_finished_attempt($kopfuebung->id, $USER->id) : null;
$attemptfinished = (bool) $finishedattempt;
if ($attemptfinished && kopfuebung_reflection_pending($kopfuebung, $finishedattempt)) {
    redirect(new moodle_url('/mod/kopfuebung/reflection.php', ['id' => $cm->id]));
}
if ($attemptfinished) {
    $userready = false;
}

$remainingseconds = (int) $kopfuebung->timelimit;
if (!empty($kopfuebung->activitystate)) {
    $remainingseconds = max(0, (int) $kopfuebung->timestarted + (int) $kopfuebung->timelimit - time());
    if ($isstudent && $attempt && $attempt->status === 'inprogress') {
        $remainingseconds = max(0, (int) $attempt->timestarted + (int) $kopfuebung->timelimit - time());
    }
}

$hasfeedback = !empty($kopfuebung->selfassessment) || !empty($kopfuebung->difficultyassessment);

$PAGE->set_url('/mod/kopfuebung/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($kopfuebung->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
// The activity description is rendered in the custom hero below.
$PAGE->activityheader->set_description('');

$statusurl = new moodle_url('/mod/kopfuebung/status.php', ['id' => $cm->id]);
$attempturl = new moodle_url('/mod/kopfuebung/attempt.php', ['id' => $cm->id]);
$jsconfig = [
    'statusUrl' => $statusurl->out(false), 'attemptUrl' => $attempturl->out(false),
    'activityState' => (bool) $kopfuebung->activitystate, 'canAttempt' => $isstudent,
    'isTeacher' => $isteacher, 'userReady' => $userready, 'attemptFinished' => $attemptfinished,
    'remainingSeconds' => $remainingseconds, 'participantCount' => $participantcount,
    'readyTemplate' => get_string('viewreadysummary', 'kopfuebung', ['ready' => '__READY__', 'total' => '__TOTAL__']),
    'allReadyLabel' => get_string('viewstatusallready', 'kopfuebung'),
    'startReadyLabel' => get_string('viewstatusstartready', 'kopfuebung'),
];
$PAGE->requires->js_init_code('(function(c) {
    var remaining = c.remainingSeconds;
    var fmt = function(s) { s = Math.max(0, parseInt(s, 10) || 0); return String(Math.floor(s / 60)).padStart(2, "0") + ":" + String(s % 60).padStart(2, "0"); };
    var setText = function(id, value) { var e = document.getElementById(id); if (e) { e.textContent = value; } };
    var setBar = function(id, count, total) { var e = document.getElementById(id); if (!e) { return; } var p = total ? Math.min(100, Math.round(100 * count / total)) : 0; e.style.width = p + "%"; e.setAttribute("aria-valuenow", p); };
    var tick = function() { setText("kopfuebung-live-time", fmt(remaining)); if (remaining > 0) { remaining--; } };
    if (c.activityState) { tick(); window.setInterval(tick, 1000); }
    var poll = function() { fetch(c.statusUrl, {credentials: "same-origin", cache: "no-store"}).then(function(r) { if (!r.ok) { throw new Error(); } return r.json(); }).then(function(d) {
        remaining = d.remainingseconds;
        if (c.canAttempt) {
            if (d.activitystate && d.userready && !d.attemptfinished) { window.location.assign(c.attemptUrl); return; }
            if (d.activitystate !== c.activityState || d.attemptfinished !== c.attemptFinished) { window.location.reload(); return; }
        }
        if (typeof d.readycount !== "undefined") {
            setText("kopfuebung-ready-count", d.readycount || 0);
            setText("kopfuebung-ready-summary", c.readyTemplate.replace("__READY__", d.readycount || 0).replace("__TOTAL__", c.participantCount));
            setBar("kopfuebung-ready-progress", d.readycount || 0, c.participantCount);
            var readinessPill = document.getElementById("kopfuebung-teacher-readiness-pill");
            if (readinessPill && !d.activitystate) {
                var everyoneReady = c.participantCount > 0 && (d.readycount || 0) >= c.participantCount;
                readinessPill.textContent = everyoneReady ? c.allReadyLabel : c.startReadyLabel;
                readinessPill.classList.toggle("is-ready", everyoneReady);
                readinessPill.classList.toggle("is-pulsing", everyoneReady);
            }
        }
        if (c.isTeacher) {
            ["started", "finished", "selfassessed", "difficultyassessed"].forEach(function(k) { if (typeof d[k] !== "undefined") { setText("kopfuebung-" + k + "-count-label", d[k] + " / " + c.participantCount); setBar("kopfuebung-" + k + "-progress", d[k], c.participantCount); } });
        }
    }).catch(function() {}); };
    poll();
    window.setInterval(poll, 2000);
})(' . json_encode($jsconfig) . ');');

echo $OUTPUT->header();
echo html_writer::start_div('kopfuebung-view');
echo html_writer::start_div('kopfuebung-view-intro');
echo html_writer::tag('h2', format_string($kopfuebung->name), ['class' => 'kopfuebung-view-title']);
$intro = format_module_intro('kopfuebung', $kopfuebung, $cm->id);
if (trim(strip_tags($intro)) !== '') {
    echo html_writer::div($intro, 'kopfuebung-view-description');
}
echo html_writer::end_div();

echo html_writer::start_div('kopfuebung-facts');
foreach ([
    [$questioncount, get_string('viewquestions', 'kopfuebung')],
    [format_time($kopfuebung->timelimit), get_string('viewworkingtime', 'kopfuebung')],
    [get_string('viewmodeexercise', 'kopfuebung'), get_string($hasfeedback ? 'viewwithfeedback' : 'viewwithoutfeedback', 'kopfuebung')],
] as [$value, $label]) {
    echo html_writer::div(html_writer::span($value, 'kopfuebung-fact-value') . html_writer::span($label, 'kopfuebung-fact-label'), 'kopfuebung-fact card');
}
echo html_writer::end_div();

if ($missingquestioncount > 0) {
    $settingsurl = new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]);
    $manageurl = new moodle_url('/mod/kopfuebung/manage.php', ['id' => $cm->id]);
    echo html_writer::start_div('kopfuebung-modal-backdrop', ['id' => 'kopfuebung-missing-questions-modal', 'role' => 'dialog', 'aria-modal' => 'true']);
    echo html_writer::start_div('kopfuebung-modal card shadow-lg'); echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string('missingquestionassignmentsheading', 'kopfuebung'));
    echo html_writer::tag('p', get_string($missingquestioncount === 1 ? 'missingquestionassignmentpromptone' : 'missingquestionassignmentsprompt', 'kopfuebung', $missingquestioncount));
    echo html_writer::div(html_writer::link($settingsurl, get_string('adjustquestioncount', 'kopfuebung'), ['class' => 'btn btn-primary']) . ' ' . html_writer::link($manageurl, get_string('assignmissingquestions', 'kopfuebung'), ['class' => 'btn btn-secondary']) . ' ' . html_writer::tag('button', get_string('cancel'), ['type' => 'button', 'class' => 'btn btn-link', 'id' => 'kopfuebung-close-missing-questions']), 'kopfuebung-modal-actions');
    echo html_writer::end_div(); echo html_writer::end_div(); echo html_writer::end_div();
    $PAGE->requires->js_init_code('document.getElementById("kopfuebung-close-missing-questions").addEventListener("click", function() { document.getElementById("kopfuebung-missing-questions-modal").remove(); });');
}

echo html_writer::start_div('kopfuebung-main-cards');
echo html_writer::start_div('kopfuebung-large-card card'); echo html_writer::start_div('card-body');
if ($isstudent) {
    if ($attemptfinished) {
        echo html_writer::div(get_string('viewstatusdone', 'kopfuebung'), 'kopfuebung-status-pill is-finished');
        echo html_writer::tag('h3', get_string('viewdoneheading', 'kopfuebung'));
        echo html_writer::tag('p', get_string('viewdonetext', 'kopfuebung'));
        if (!$kopfuebung->activitystate) { echo $OUTPUT->single_button(new moodle_url('/mod/kopfuebung/review.php', ['id' => $cm->id]), get_string('reviewattempt', 'kopfuebung'), 'get', ['primary' => true]); }
        else { echo html_writer::tag('p', get_string('resultsavailableafteractivity', 'kopfuebung'), ['class' => 'text-muted']); }
    } else if ($kopfuebung->activitystate) {
        echo html_writer::div(get_string('viewstatusrunning', 'kopfuebung'), 'kopfuebung-status-pill is-running');
        echo html_writer::tag('h3', get_string('viewrunningheading', 'kopfuebung'));
        echo html_writer::div(sprintf('%02d:%02d', floor($remainingseconds / 60), $remainingseconds % 60), 'kopfuebung-live-time', ['id' => 'kopfuebung-live-time']);
        echo html_writer::tag('p', get_string('viewrunningtext', 'kopfuebung'));
        echo $OUTPUT->single_button($attempturl, get_string($attempt ? 'continueattempt' : 'attemptactivity', 'kopfuebung'), 'get', ['primary' => true]);
    } else if ($userready) {
        echo html_writer::div(get_string('viewstatusready', 'kopfuebung'), 'kopfuebung-status-pill is-ready');
        echo html_writer::tag('h3', get_string('viewwaitingheading', 'kopfuebung'));
        echo html_writer::tag('p', get_string('viewwaitingtext', 'kopfuebung'));
        echo html_writer::tag('p', get_string('viewreadysummary', 'kopfuebung', ['ready' => $readycount, 'total' => $participantcount]), ['id' => 'kopfuebung-ready-summary']);
        echo html_writer::div(html_writer::div('', 'kopfuebung-progress-bar bg-success', ['id' => 'kopfuebung-ready-progress', 'style' => 'width:' . $readypercentage . '%']), 'kopfuebung-progress');
        echo html_writer::div(get_string('viewwaitnotice', 'kopfuebung'), 'alert alert-success mb-0');
        if (!empty($kopfuebung->allowreadywithdraw)) {
            echo html_writer::div($OUTPUT->single_button(
                new moodle_url('/mod/kopfuebung/ready.php', [
                    'id' => $cm->id,
                    'action' => 'withdraw',
                    'sesskey' => sesskey(),
                ]),
                get_string('withdrawreadiness', 'kopfuebung'),
                'post'
            ), 'kopfuebung-withdraw-ready');
        }
    } else {
        echo html_writer::div(get_string('viewstatuswaiting', 'kopfuebung'), 'kopfuebung-status-pill');
        echo html_writer::tag('h3', get_string('viewreadyheading', 'kopfuebung'));
        echo html_writer::tag('p', get_string('viewreadytext', 'kopfuebung'));
        echo $OUTPUT->single_button(new moodle_url('/mod/kopfuebung/ready.php', ['id' => $cm->id, 'sesskey' => sesskey()]), get_string('iamready', 'kopfuebung'), 'post', ['primary' => true]);
    }
} else if ($canstart) {
    $teacherpillkey = $kopfuebung->activitystate ? 'viewstatusrunning' : ($allready ? 'viewstatusallready' : 'viewstatusstartready');
    $teacherpillclass = $kopfuebung->activitystate ? ' is-running' : ($allready ? ' is-ready is-pulsing' : '');
    echo html_writer::div(get_string($teacherpillkey, 'kopfuebung'), 'kopfuebung-status-pill' . $teacherpillclass, [
        'id' => 'kopfuebung-teacher-readiness-pill',
    ]);
    echo html_writer::tag('h3', get_string($kopfuebung->activitystate ? 'viewteacherrunning' : 'viewteacherready', 'kopfuebung'));
    if ($kopfuebung->activitystate) {
        echo html_writer::div(sprintf('%02d:%02d', floor($remainingseconds / 60), $remainingseconds % 60), 'kopfuebung-live-time', ['id' => 'kopfuebung-live-time']);
    } else {
        echo html_writer::tag('p', get_string('viewteacherreadytext', 'kopfuebung'), ['class' => 'text-muted']);
        echo html_writer::tag('p', get_string('viewreadysummary', 'kopfuebung', ['ready' => $readycount, 'total' => $participantcount]), ['id' => 'kopfuebung-ready-summary']);
        echo html_writer::div(html_writer::div('', 'kopfuebung-progress-bar bg-success', ['id' => 'kopfuebung-ready-progress', 'style' => 'width:' . $readypercentage . '%']), 'kopfuebung-progress');
    }
    if ($kopfuebung->activitystate) {
        $metrics = [['started', 'info'], ['finished', 'success']];
        if ($kopfuebung->selfassessment) { $metrics[] = ['selfassessed', 'warning']; }
        if ($kopfuebung->difficultyassessment) { $metrics[] = ['difficultyassessed', 'purple']; }
        foreach ($metrics as [$key, $colour]) {
            echo html_writer::div(html_writer::span(get_string('viewprogress' . $key, 'kopfuebung')) . html_writer::span('0 / ' . $participantcount, 'float-right', ['id' => 'kopfuebung-' . $key . '-count-label']), 'kopfuebung-progress-label');
            echo html_writer::div(html_writer::div('', 'kopfuebung-progress-bar bg-' . $colour, ['id' => 'kopfuebung-' . $key . '-progress', 'style' => 'width:0']), 'kopfuebung-progress');
        }
    }
    $action = $kopfuebung->activitystate ? 'stop' : 'start';
    echo $OUTPUT->single_button(new moodle_url('/mod/kopfuebung/start.php', ['id' => $cm->id, 'action' => $action, 'sesskey' => sesskey()]), get_string($kopfuebung->activitystate ? 'stopactivity' : 'startactivity', 'kopfuebung'), 'post', ['primary' => !$kopfuebung->activitystate]);
}
echo html_writer::end_div(); echo html_writer::end_div();

echo html_writer::start_div('kopfuebung-large-card card'); echo html_writer::start_div('card-body');
if ($canstart) {
    echo html_writer::div(get_string('viewteacherrole', 'kopfuebung'), 'kopfuebung-flow-role');
    echo html_writer::tag('h3', get_string('viewteacherflowheading', 'kopfuebung'));
} else {
    echo html_writer::tag('h3', get_string('viewflowheading', 'kopfuebung'));
}
echo html_writer::start_div('kopfuebung-flow');
if ($canstart) {
    $activityhasrun = !empty($kopfuebung->timestarted);
    $teachersteps = [
        1 => [
            'class' => $questionscomplete ? ' is-complete' : ' is-warning',
            'title' => get_string('viewteacherstep1title', 'kopfuebung'),
            'text' => get_string('viewteacherstep1text', 'kopfuebung'),
            'hint' => $questionscomplete ? '' : get_string('viewteacherquestionsmissing', 'kopfuebung', [
                'assigned' => $assignedquestioncount,
                'total' => $questioncount,
            ]),
        ],
        2 => [
            'class' => ($allready || $activityhasrun) ? ' is-complete' : ($questionscomplete ? ' is-active' : ''),
            'title' => get_string('viewteacherstep2title', 'kopfuebung'),
            'text' => get_string('viewteacherstep2text', 'kopfuebung'),
            'hint' => '',
        ],
        3 => [
            'class' => $kopfuebung->activitystate ? ' is-active' : ($activityhasrun ? ' is-complete' : ($allready ? ' is-active' : '')),
            'title' => get_string('viewteacherstep3title', 'kopfuebung'),
            'text' => get_string('viewteacherstep3text', 'kopfuebung'),
            'hint' => '',
        ],
    ];
    foreach ($teachersteps as $number => $teacherstep) {
        echo html_writer::start_div('kopfuebung-flow-step' . $teacherstep['class']);
        echo html_writer::span($number, 'kopfuebung-step-number');
        $stepcontent = html_writer::tag('h4', $teacherstep['title']) . html_writer::tag('p', $teacherstep['text']);
        if ($teacherstep['hint'] !== '') {
            $stepcontent .= html_writer::div($teacherstep['hint'], 'kopfuebung-step-warning');
        }
        echo html_writer::div($stepcontent, 'kopfuebung-step-copy');
        echo html_writer::end_div();
    }
} else {
    $step = $attemptfinished ? 3 : ((!empty($kopfuebung->activitystate) || $attempt) ? 2 : 1);
    for ($i = 1; $i <= 3; $i++) {
        echo html_writer::start_div('kopfuebung-flow-step' . ($step === $i ? ' is-active' : '') . ($step > $i ? ' is-complete' : ''));
        echo html_writer::span($i, 'kopfuebung-step-number');
        echo html_writer::div(html_writer::tag('h4', get_string('viewstep' . $i . 'title', 'kopfuebung')) . html_writer::tag('p', get_string('viewstep' . $i . 'text', 'kopfuebung')), 'kopfuebung-step-copy');
        echo html_writer::end_div();
    }
}
echo html_writer::end_div(); echo html_writer::end_div(); echo html_writer::end_div();
echo html_writer::end_div();

if ($isteacher || ($isstudent && $canviewoverview)) {
    echo html_writer::start_div('kopfuebung-teacher-card card'); echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string($isteacher ? 'viewadvancedheading' : 'viewstudentoverviewheading', 'kopfuebung'));
    echo html_writer::tag('p', get_string($isteacher ? 'viewadvancedtext' : 'viewstudentoverviewtext', 'kopfuebung'), ['class' => 'text-muted']);
    echo html_writer::start_div('kopfuebung-teacher-actions');
    if ($isteacher && $canmanagequestions) { echo html_writer::link(new moodle_url('/mod/kopfuebung/manage.php', ['id' => $cm->id]), get_string('managequestions', 'kopfuebung'), ['class' => 'btn btn-outline-primary']); }
    if ($isteacher && $canviewreports) { echo html_writer::link(new moodle_url('/mod/kopfuebung/report.php', ['id' => $cm->id]), get_string('report', 'kopfuebung'), ['class' => 'btn btn-outline-primary']); }
    if ($canviewoverview) { echo html_writer::link(new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id]), get_string('courseoverview', 'kopfuebung'), ['class' => 'btn btn-outline-primary']); }
    echo html_writer::end_div(); echo html_writer::end_div(); echo html_writer::end_div();
}

if ($canresetattempts) {
    $attempts = $DB->get_records('kopfuebung_attempts', ['kopfuebungid' => $kopfuebung->id], 'userid ASC, id DESC');
    $latest = []; foreach ($attempts as $a) { if (!isset($latest[$a->userid])) { $latest[$a->userid] = $a; } }
    if ($latest) {
        $table = new html_table(); $table->attributes['class'] = 'generaltable';
        $table->head = [get_string('participant', 'kopfuebung'), get_string('attemptstatus', 'kopfuebung'), get_string('attempttime', 'kopfuebung'), get_string('actions')];
        foreach ($latest as $a) {
            $student = $DB->get_record('user', ['id' => $a->userid, 'deleted' => 0], '*', IGNORE_MISSING); if (!$student || !is_enrolled($context, $student, '', true)) { continue; }
            $actions = $a->status === 'finished' ? html_writer::link(new moodle_url('/mod/kopfuebung/review.php', ['id' => $cm->id, 'userid' => $student->id]), get_string('reviewattempt', 'kopfuebung'), ['class' => 'btn btn-secondary btn-sm mr-2']) : '';
            $actions .= html_writer::link(new moodle_url('/mod/kopfuebung/resetattempt.php', ['id' => $cm->id, 'userid' => $student->id]), get_string('resetattempt', 'kopfuebung'), ['class' => 'btn btn-danger btn-sm']);
            $table->data[] = [fullname($student), get_string('attemptstatus' . $a->status, 'kopfuebung'), userdate($a->timefinished ?: $a->timestarted), $actions];
        }
        echo html_writer::start_div('kopfuebung-attempt-management'); echo $OUTPUT->heading(get_string('attemptmanagement', 'kopfuebung'), 3); echo html_writer::div(html_writer::table($table), 'table-responsive'); echo html_writer::end_div();
    }
}
echo html_writer::end_div();
echo $OUTPUT->footer();
