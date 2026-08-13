<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', 'ready', PARAM_ALPHA);
$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:attempt', $context);
require_sesskey();

if (kopfuebung_get_latest_finished_attempt($kopfuebung->id, $USER->id)) {
    redirect(
        new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]),
        get_string('attemptalreadysubmitted', 'kopfuebung')
    );
}

if ($kopfuebung->activitystate) {
    redirect(new moodle_url('/mod/kopfuebung/attempt.php', ['id' => $cm->id]));
}

if ($action === 'withdraw') {
    if (empty($kopfuebung->allowreadywithdraw)) {
        redirect(
            new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]),
            get_string('readinesswithdrawaldisabled', 'kopfuebung'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $DB->delete_records('kopfuebung_ready', [
        'kopfuebungid' => $kopfuebung->id,
        'userid' => $USER->id,
    ]);
    redirect(
        new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]),
        get_string('readinesswithdrawn', 'kopfuebung'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
if ($action !== 'ready') {
    throw new moodle_exception('invalidrequest');
}

if (!$DB->record_exists('kopfuebung_ready', [
    'kopfuebungid' => $kopfuebung->id,
    'userid' => $USER->id,
])) {
    $DB->insert_record('kopfuebung_ready', (object) [
        'kopfuebungid' => $kopfuebung->id,
        'userid' => $USER->id,
        'timecreated' => time(),
    ]);
}

redirect(
    new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]),
    get_string('readinessreported', 'kopfuebung'),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
