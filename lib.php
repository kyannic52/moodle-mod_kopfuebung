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

    return $DB->insert_record('kopfuebung', $data);
}

function kopfuebung_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();

    return $DB->update_record('kopfuebung', $data);
}

function kopfuebung_delete_instance($id) {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/question/engine/lib.php');

    if (!$DB->record_exists('kopfuebung', ['id' => $id])) {
        return false;
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
