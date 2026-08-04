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
if ($canstart) {
    $response['readycount'] = $DB->count_records('kopfuebung_ready', ['kopfuebungid' => $kopfuebung->id]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response);
