<?php
// This file is part of Moodle - http://moodle.org/

define('AJAX_SCRIPT', true);

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

$canattempt = has_capability('mod/kopfuebung:attempt', $context);
$canstart = has_capability('mod/kopfuebung:startactivity', $context);
$userready = $canattempt && $DB->record_exists('kopfuebung_ready', [
    'kopfuebungid' => $kopfuebung->id,
    'userid' => $USER->id,
]);
$attempt = $canattempt ? kopfuebung_get_current_attempt($kopfuebung, $USER->id) : null;
$finishedattempt = $canattempt
    ? kopfuebung_get_latest_finished_attempt($kopfuebung->id, $USER->id)
    : null;
if ($finishedattempt) {
    $userready = false;
}
$remainingseconds = (int) $kopfuebung->timelimit;

if ($canattempt && $kopfuebung->activitystate) {
    $remainingseconds = max(0, $kopfuebung->timestarted + $kopfuebung->timelimit - time());
    if ($attempt && $attempt->status === 'inprogress') {
        $remainingseconds = max(0, $attempt->timestarted + $kopfuebung->timelimit - time());
    }
}

$response = [
    'activitystate' => (bool) $kopfuebung->activitystate,
    'userready' => (bool) $userready,
    'attemptfinished' => (bool) $finishedattempt,
    'remainingseconds' => $remainingseconds,
];
if ($canattempt || $canstart) {
    $response['readycount'] = $DB->count_records('kopfuebung_ready', ['kopfuebungid' => $kopfuebung->id]);
}
if ($canstart) {

    $participantids = array_keys(get_enrolled_users($context, 'mod/kopfuebung:attempt', 0, 'u.id'));
    $latestattempts = [];
    if ($participantids) {
        [$insql, $inparams] = $DB->get_in_or_equal($participantids, SQL_PARAMS_NAMED, 'participant');
        $attemptwhere = 'kopfuebungid = :activityid AND userid ' . $insql;
        $attemptparams = ['activityid' => $kopfuebung->id] + $inparams;
        if (!empty($kopfuebung->activitystate) && !empty($kopfuebung->timestarted)) {
            $attemptwhere .= ' AND timestarted >= :activitystart';
            $attemptparams['activitystart'] = $kopfuebung->timestarted;
        }
        $attempts = $DB->get_records_select(
            'kopfuebung_attempts',
            $attemptwhere,
            $attemptparams,
            'userid ASC, id DESC'
        );
        foreach ($attempts as $participantattempt) {
            if (!isset($latestattempts[$participantattempt->userid])) {
                $latestattempts[$participantattempt->userid] = $participantattempt;
            }
        }
    }
    $response['started'] = count($latestattempts);
    $response['finished'] = 0;
    $response['selfassessed'] = 0;
    $response['difficultyassessed'] = 0;
    foreach ($latestattempts as $participantattempt) {
        if ($participantattempt->status !== 'finished') {
            continue;
        }
        $response['finished']++;
        $reflections = $DB->get_records('kopfuebung_reflections', ['attemptid' => $participantattempt->id]);
        if (!empty($kopfuebung->selfassessment) && count(array_filter($reflections,
                static function($reflection) { return $reflection->predictedcorrect !== null; })) >= $kopfuebung->questioncount) {
            $response['selfassessed']++;
        }
        if (!empty($kopfuebung->difficultyassessment) && count(array_filter($reflections,
                static function($reflection) { return $reflection->difficulty !== null; })) >= $kopfuebung->questioncount) {
            $response['difficultyassessed']++;
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response);
