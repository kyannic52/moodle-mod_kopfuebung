<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Return the Kopfuebung activities that are visible to the current user.
 *
 * @param stdClass $course
 * @return array
 */
function kopfuebung_get_course_activities(stdClass $course): array {
    global $DB;

    $modinfo = get_fast_modinfo($course);
    $cms = $modinfo->get_instances_of('kopfuebung');
    $activities = [];

    foreach ($cms as $cm) {
        if (!$cm->uservisible) {
            continue;
        }
        $activity = $DB->get_record('kopfuebung', ['id' => $cm->instance], 'id, name', IGNORE_MISSING);
        if (!$activity) {
            continue;
        }
        $activity->cmid = $cm->id;
        $activities[$cm->sectionnum . ':' . $cm->id] = $activity;
    }

    ksort($activities, SORT_NATURAL);
    return array_values($activities);
}

/**
 * Load the ten course-wide diagnostic row labels.
 *
 * @param int $courseid
 * @return string[]
 */
function kopfuebung_get_course_labels(int $courseid): array {
    global $DB;

    $labels = array_fill(1, 10, '');
    $records = $DB->get_records('kopfuebung_labels', ['courseid' => $courseid], 'position ASC');
    foreach ($records as $record) {
        if ($record->position >= 1 && $record->position <= 10) {
            $labels[$record->position] = $record->label;
        }
    }
    return $labels;
}

/**
 * Save the ten course-wide diagnostic row labels.
 *
 * @param int $courseid
 * @param array $labels
 */
function kopfuebung_save_course_labels(int $courseid, array $labels): void {
    global $DB;

    $transaction = $DB->start_delegated_transaction();
    for ($position = 1; $position <= 10; $position++) {
        $label = trim(clean_param($labels[$position] ?? '', PARAM_TEXT));
        $record = $DB->get_record('kopfuebung_labels', [
            'courseid' => $courseid,
            'position' => $position,
        ]);

        if ($label === '') {
            if ($record) {
                $DB->delete_records('kopfuebung_labels', ['id' => $record->id]);
            }
            continue;
        }

        if ($record) {
            $record->label = $label;
            $record->timemodified = time();
            $DB->update_record('kopfuebung_labels', $record);
        } else {
            $DB->insert_record('kopfuebung_labels', (object) [
                'courseid' => $courseid,
                'position' => $position,
                'label' => $label,
                'timemodified' => time(),
            ]);
        }
    }
    $transaction->allow_commit();
}

/**
 * Read a user's most recent attempt for each activity from the question engine.
 *
 * @param array $activities
 * @param int $userid
 * @return array keyed by activity id
 */
function kopfuebung_get_user_matrix(array $activities, int $userid): array {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/question/engine/lib.php');

    $matrix = [];
    foreach ($activities as $activity) {
        $result = [
            'cells' => array_fill(1, 10, 'notattempted'),
            'correct' => 0,
            'graded' => 0,
            'attemptid' => 0,
        ];

        $attempts = $DB->get_records(
            'kopfuebung_attempts',
            ['kopfuebungid' => $activity->id, 'userid' => $userid],
            'id DESC',
            '*',
            0,
            1
        );
        $attempt = reset($attempts);
        if (!$attempt || empty($attempt->questionusageid)) {
            $matrix[$activity->id] = $result;
            continue;
        }

        try {
            $quba = question_engine::load_questions_usage_by_activity($attempt->questionusageid);
            $result['attemptid'] = $attempt->id;
            foreach (array_values($quba->get_slots()) as $index => $slot) {
                $position = $index + 1;
                if ($position > 10) {
                    break;
                }

                $qa = $quba->get_question_attempt($slot);
                $mark = $qa->get_mark();
                $maxmark = $quba->get_question_max_mark($slot);
                if ($mark === null || !$qa->get_state()->is_finished()) {
                    $result['cells'][$position] = 'unanswered';
                    continue;
                }

                $result['graded']++;
                if ($maxmark > 0 && $mark >= $maxmark - 0.000005) {
                    $result['cells'][$position] = 'correct';
                    $result['correct']++;
                } else if ($mark > 0) {
                    $result['cells'][$position] = 'partiallycorrect';
                } else {
                    $result['cells'][$position] = 'incorrect';
                }
            }
        } catch (Exception $exception) {
            // A deleted/corrupt question usage must not make the whole course page unusable.
            debugging($exception->getMessage(), DEBUG_DEVELOPER);
        }

        $matrix[$activity->id] = $result;
    }

    return $matrix;
}
