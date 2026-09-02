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
    global $DB, $SESSION, $USER;

    $huedraftid = (int) ($data->huefile ?? 0);
    $huefiles = $huedraftid ? get_file_storage()->get_area_files(
        context_user::instance($USER->id)->id, 'user', 'draft', $huedraftid, 'id', false
    ) : [];
    unset($data->huefile, $data->hueapply);

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->activitystate = 0;
    $data->timestarted = null;
    $data->activitytype = ($data->activitytype ?? '') === 'overview' ? 'overview' : 'exercise';
    if ($data->activitytype === 'overview' || empty($data->timelimit)) {
        $data->timelimit = 300;
    }
    $questioncount = (int) ($data->questioncount ?? 10);
    $data->questioncount = in_array($questioncount, [8, 9, 10], true)
        ? $questioncount
        : 10;
    $data->selfassessment = empty($data->selfassessment) ? 0 : 1;
    $data->difficultyassessment = empty($data->difficultyassessment) ? 0 : 1;
    $data->allowreadywithdraw = empty($data->allowreadywithdraw) ? 0 : 1;
    if ($huefiles && trim((string) ($data->name ?? '')) === '') {
        $data->name = get_string('huependingactivityname', 'kopfuebung');
    }

    $id = $DB->insert_record('kopfuebung', $data);
    if ($huefiles) {
        $SESSION->kopfuebung_hue_pending = [
            'activityid' => $id,
            'courseid' => (int) $data->course,
            'draftid' => $huedraftid,
        ];
    }
    return $id;
}

function kopfuebung_update_instance($data, $mform = null) {
    global $DB, $SESSION, $USER;

    $huedraftid = (int) ($data->huefile ?? 0);
    $huefiles = $huedraftid ? get_file_storage()->get_area_files(
        context_user::instance($USER->id)->id, 'user', 'draft', $huedraftid, 'id', false
    ) : [];
    unset($data->huefile, $data->hueapply);

    $data->id = $data->instance;
    $data->timemodified = time();
    $data->activitytype = ($data->activitytype ?? '') === 'overview' ? 'overview' : 'exercise';
    if ($data->activitytype === 'overview' || empty($data->timelimit)) {
        $data->timelimit = 300;
    }
    $questioncount = (int) ($data->questioncount ?? 10);
    $data->questioncount = in_array($questioncount, [8, 9, 10], true)
        ? $questioncount
        : 10;
    $data->selfassessment = empty($data->selfassessment) ? 0 : 1;
    $data->difficultyassessment = empty($data->difficultyassessment) ? 0 : 1;
    $data->allowreadywithdraw = empty($data->allowreadywithdraw) ? 0 : 1;

    $result = $DB->update_record('kopfuebung', $data);
    if ($huefiles) {
        $SESSION->kopfuebung_hue_pending = [
            'activityid' => (int) $data->id,
            'courseid' => (int) $data->course,
            'draftid' => $huedraftid,
        ];
    }
    return $result;
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
            $offerids = $DB->get_fieldset_select(
                'kopfuebung_offers',
                'id',
                'courseid = ? AND gridid = ?',
                [$cm->course, $labelgrid->id]
            );
            if ($offerids) {
                list($offersql, $offerparams) = $DB->get_in_or_equal($offerids, SQL_PARAMS_NAMED, 'offer');
                $DB->delete_records_select('kopfuebung_offer_users', "offerid $offersql", $offerparams);
                $DB->delete_records_select('kopfuebung_offer_links', "offerid $offersql", $offerparams);
            }
            $DB->delete_records('kopfuebung_offers', [
                'courseid' => $cm->course,
                'gridid' => $labelgrid->id,
            ]);
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
        $DB->delete_records_select('kopfuebung_reflections', "attemptid $insql", $params);
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
