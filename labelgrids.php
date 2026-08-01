<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$placement = optional_param('placement', 'before', PARAM_ALPHA);
$activitycmid = optional_param('activitycmid', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_login($course);
require_capability('mod/kopfuebung:manageoverview', $context);

$activities = kopfuebung_get_course_activities($course);
$PAGE->set_url('/mod/kopfuebung/labelgrids.php', ['id' => $course->id]);
$PAGE->set_title(get_string('managelabelgrids', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if (data_submitted()) {
    require_sesskey();
    if (!in_array($placement, ['before', 'after'], true)) {
        throw new moodle_exception('invalidrequest');
    }

    $activityindex = null;
    foreach ($activities as $index => $activity) {
        if ((int) $activity->cmid === $activitycmid) {
            $activityindex = $index;
            break;
        }
    }
    if ($activityindex === null) {
        throw new moodle_exception('invalidrecord', 'error', $PAGE->url, 'kopfuebung');
    }

    if ($placement === 'before' && $activityindex === 0) {
        redirect($PAGE->url, get_string('nogridbeforefirstactivity', 'kopfuebung'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    if ($placement === 'after') {
        $activityindex++;
        if (!isset($activities[$activityindex])) {
            redirect($PAGE->url, get_string('nogridafterlastactivity', 'kopfuebung'), null,
                \core\output\notification::NOTIFY_ERROR);
        }
    }

    $targetcmid = (int) $activities[$activityindex]->cmid;
    $existinggrids = kopfuebung_get_course_label_grids($course->id);
    if (isset($existinggrids[$targetcmid])) {
        redirect($PAGE->url, get_string('labelgridalreadyexists', 'kopfuebung'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    kopfuebung_create_course_label_grid($course->id, $targetcmid);
    redirect(
        new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id, 'userid' => -1]),
        get_string('labelgridcreated', 'kopfuebung'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managelabelgrids', 'kopfuebung'));
echo html_writer::tag('p', get_string('labelgridmanagementintro', 'kopfuebung'));

if (!$activities) {
    echo $OUTPUT->notification(get_string('noactivities', 'kopfuebung'), 'notifymessage');
} else {
    $activityoptions = [];
    foreach ($activities as $activity) {
        $activityoptions[$activity->cmid] = format_string($activity->name);
    }

    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::start_div('form-group');
    echo html_writer::label(get_string('gridplacement', 'kopfuebung'), 'kopfuebung-grid-placement');
    echo html_writer::select([
        'before' => get_string('beforeactivity', 'kopfuebung'),
        'after' => get_string('afteractivity', 'kopfuebung'),
    ], 'placement', $placement, false, [
        'id' => 'kopfuebung-grid-placement',
        'class' => 'custom-select',
    ]);
    echo html_writer::end_div();
    echo html_writer::start_div('form-group');
    echo html_writer::label(get_string('kopfuebungactivity', 'kopfuebung'), 'kopfuebung-grid-activity');
    echo html_writer::select($activityoptions, 'activitycmid', $activitycmid, false, [
        'id' => 'kopfuebung-grid-activity',
        'class' => 'custom-select',
    ]);
    echo html_writer::end_div();
    echo html_writer::tag('button', get_string('createlabelgrid', 'kopfuebung'), [
        'type' => 'submit',
        'class' => 'btn btn-primary mr-2',
    ]);
    echo html_writer::link(
        new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id, 'userid' => -1]),
        get_string('cancel'),
        ['class' => 'btn btn-secondary']
    );
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
