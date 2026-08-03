<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

function kopfuebung_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        default:
            return null;
    }
}

function kopfuebung_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->activitystate = 0;
    $data->timestarted = null;
    $data->questioncount = in_array((int) $data->questioncount, [8, 9, 10], true)
        ? (int) $data->questioncount
        : 10;

    return $DB->insert_record('kopfuebung', $data);
}

function kopfuebung_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $data->questioncount = in_array((int) $data->questioncount, [8, 9, 10], true)
        ? (int) $data->questioncount
        : 10;

    return $DB->update_record('kopfuebung', $data);
}

function kopfuebung_delete_instance($id) {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/question/engine/lib.php');

    if (!$DB->record_exists('kopfuebung', ['id' => $id])) {
        return false;
    }

    $cm = get_coursemodule_from_instance('kopfuebung', $id, 0, false, IGNORE_MISSING);
    if ($cm) {
        $labelgrid = $DB->get_record('kopfuebung_labelgrids', ['startcmid' => $cm->id]);
        if ($labelgrid) {
            $DB->delete_records('kopfuebung_labels', ['gridid' => $labelgrid->id]);
            $DB->delete_records('kopfuebung_labelgrids', ['id' => $labelgrid->id]);
        }
    }

    $attemptids = $DB->get_fieldset_select('kopfuebung_attempts', 'id', 'kopfuebungid = ?', [$id]);
    $questionusageids = $DB->get_fieldset_select(
        'kopfuebung_attempts',
        'questionusageid',
        'kopfuebungid = ? AND questionusageid > 0',
        [$id]
    );
    if ($attemptids) {
        list($insql, $params) = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('kopfuebung_answers', "attemptid $insql", $params);
    }
    foreach ($questionusageids as $questionusageid) {
        question_engine::delete_questions_usage_by_activity($questionusageid);
    }

    $DB->delete_records('kopfuebung_attempts', ['kopfuebungid' => $id]);
    $DB->delete_records('kopfuebung_ready', ['kopfuebungid' => $id]);
    $DB->delete_records('kopfuebung_questions', ['kopfuebungid' => $id]);
    $DB->delete_records('kopfuebung', ['id' => $id]);

    return true;
}

function kopfuebung_get_coursemodule_info($coursemodule) {
    global $DB;

    $kopfuebung = $DB->get_record('kopfuebung', ['id' => $coursemodule->instance], 'id, name, intro, introformat', IGNORE_MISSING);
    if (!$kopfuebung) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $kopfuebung->name;
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('kopfuebung', $kopfuebung, $coursemodule->id, false);
    }

    return $info;
}

/**
 * Add the course-wide Kopfuebung result matrix to course navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function kopfuebung_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    if (!has_capability('mod/kopfuebung:viewoverview', $context)) {
        return;
    }

    $modinfo = get_fast_modinfo($course);
    $hasvisibleactivity = false;
    foreach ($modinfo->get_instances_of('kopfuebung') as $cm) {
        if ($cm->uservisible) {
            $hasvisibleactivity = true;
            break;
        }
    }
    if (!$hasvisibleactivity) {
        return;
    }

    $navigation->add(
        get_string('courseoverview', 'kopfuebung'),
        new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id]),
        navigation_node::TYPE_CUSTOM,
        null,
        'kopfuebungoverview',
        new pix_icon('icon', '', 'mod_kopfuebung')
    );
}
