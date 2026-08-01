<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Return a user's attempt from the activity's current run, if one exists.
 *
 * @param stdClass $kopfuebung
 * @param int $userid
 * @return stdClass|null
 */
function kopfuebung_get_current_attempt(stdClass $kopfuebung, int $userid): ?stdClass {
    global $DB;

    if (empty($kopfuebung->timestarted)) {
        return null;
    }

    $params = [
        'kopfuebungid' => $kopfuebung->id,
        'userid' => $userid,
        'activitystarted' => $kopfuebung->timestarted,
    ];
    $attempts = $DB->get_records_select(
        'kopfuebung_attempts',
        'kopfuebungid = :kopfuebungid AND userid = :userid
            AND timestarted >= :activitystarted AND status = :finishedstatus',
        $params + ['finishedstatus' => 'finished'],
        'id DESC',
        '*',
        0,
        1
    );

    $attempt = reset($attempts);
    if (!$attempt) {
        $attempts = $DB->get_records_select(
            'kopfuebung_attempts',
            'kopfuebungid = :kopfuebungid AND userid = :userid AND timestarted >= :activitystarted',
            $params,
            'id DESC',
            '*',
            0,
            1
        );
        $attempt = reset($attempts);
    }
    return $attempt ?: null;
}

/**
 * Return every context that can hold questions belonging to this course.
 *
 * @param stdClass $course
 * @return int[]
 */
function kopfuebung_get_course_question_context_ids(stdClass $course): array {
    global $DB;

    $coursecontext = context_course::instance($course->id);
    $contextids = array_map('intval', array_filter(explode('/', trim($coursecontext->path, '/'))));
    $modulecontextids = $DB->get_fieldset_sql(
        "SELECT ctx.id
           FROM {context} ctx
           JOIN {course_modules} cm ON cm.id = ctx.instanceid
          WHERE ctx.contextlevel = :modulelevel
            AND cm.course = :courseid",
        ['modulelevel' => CONTEXT_MODULE, 'courseid' => $course->id]
    );

    return array_values(array_unique(array_merge($contextids, $modulecontextids)));
}

/**
 * Return the latest ready question-bank questions available in a course.
 *
 * @param stdClass $course
 * @param int $categoryid
 * @param string $search
 * @return stdClass[]
 */
function kopfuebung_get_available_questions(
    stdClass $course,
    int $categoryid = 0,
    string $search = ''
): array {
    global $DB;

    $contextids = kopfuebung_get_course_question_context_ids($course);
    list($contextsql, $params) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');

    $categorysql = '';
    if ($categoryid) {
        $categorysql = ' AND qc.id = :categoryid';
        $params['categoryid'] = $categoryid;
    }

    $searchsql = '';
    if ($search !== '') {
        $searchsql = $DB->sql_like('q.name', ':searchname', false) .
            ' OR ' . $DB->sql_like('q.questiontext', ':searchtext', false);
        $searchsql = " AND ($searchsql)";
        $params['searchname'] = '%' . $DB->sql_like_escape($search) . '%';
        $params['searchtext'] = '%' . $DB->sql_like_escape($search) . '%';
    }

    $params['readystatus'] = 'ready';
    $params['readystatussub'] = 'ready';
    $params['randomqtype'] = 'random';

    $sql = "SELECT q.id,
                   q.name,
                   q.questiontext,
                   q.questiontextformat,
                   q.qtype,
                   qc.name AS categoryname
              FROM {question_versions} qv
              JOIN {question} q ON q.id = qv.questionid
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE qc.contextid $contextsql
               AND qv.status = :readystatus
               AND qv.version = (
                   SELECT MAX(qv2.version)
                     FROM {question_versions} qv2
                    WHERE qv2.questionbankentryid = qv.questionbankentryid
                      AND qv2.status = :readystatussub
               )
               AND q.qtype <> :randomqtype
                   $categorysql
                   $searchsql
          ORDER BY q.name ASC, qc.name ASC";

    return $DB->get_records_sql($sql, $params);
}

/**
 * Return question categories available in a course.
 *
 * @param stdClass $course
 * @return stdClass[]
 */
function kopfuebung_get_question_categories(stdClass $course): array {
    global $DB;

    $contextids = kopfuebung_get_course_question_context_ids($course);
    list($contextsql, $params) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');

    return $DB->get_records_select(
        'question_categories',
        "contextid $contextsql",
        $params,
        'name ASC',
        'id, name'
    );
}

/**
 * Persist the complete ten-position question assignment.
 *
 * Existing records are retained when possible so references remain stable.
 *
 * @param int $kopfuebungid
 * @param array $assignments position => question id
 * @param stdClass[] $availablequestions
 */
function kopfuebung_save_question_assignments(
    int $kopfuebungid,
    array $assignments,
    array $availablequestions
): void {
    global $DB;

    $normalised = [];
    foreach ($assignments as $position => $questionid) {
        $position = (int) $position;
        $questionid = (int) $questionid;
        if ($position < 1 || $position > 10 || !$questionid) {
            continue;
        }
        if (!isset($availablequestions[$questionid])) {
            throw new moodle_exception('invalidrecord', 'error', '', 'question');
        }
        if (in_array($questionid, $normalised, true)) {
            throw new moodle_exception('duplicatequestionassignment', 'kopfuebung');
        }
        $normalised[$position] = $questionid;
    }

    $transaction = $DB->start_delegated_transaction();
    $existing = $DB->get_records('kopfuebung_questions', ['kopfuebungid' => $kopfuebungid]);
    $existingbyquestion = [];
    foreach ($existing as $record) {
        $existingbyquestion[(int) $record->questionid] = $record;
    }

    foreach ($normalised as $position => $questionid) {
        if (isset($existingbyquestion[$questionid])) {
            $record = $existingbyquestion[$questionid];
            $record->sortorder = $position;
            $DB->update_record('kopfuebung_questions', $record);
            unset($existing[$record->id]);
            continue;
        }

        $question = $availablequestions[$questionid];
        $DB->insert_record('kopfuebung_questions', (object) [
            'kopfuebungid' => $kopfuebungid,
            'questionid' => $questionid,
            'tag' => $question->categoryname ?: $question->qtype,
            'sortorder' => $position,
            'timecreated' => time(),
        ]);
    }

    foreach ($existing as $record) {
        $DB->delete_records('kopfuebung_questions', ['id' => $record->id]);
    }
    $transaction->allow_commit();
}

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
        $activity = $DB->get_record(
            'kopfuebung',
            ['id' => $cm->instance],
            'id, name, activitystate, timestarted, timelimit',
            IGNORE_MISSING
        );
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
            ['kopfuebungid' => $activity->id, 'userid' => $userid, 'status' => 'finished'],
            'id DESC',
            '*',
            0,
            1
        );
        $attempt = reset($attempts);
        if (!$attempt) {
            $attempts = $DB->get_records(
                'kopfuebung_attempts',
                ['kopfuebungid' => $activity->id, 'userid' => $userid],
                'id DESC',
                '*',
                0,
                1
            );
            $attempt = reset($attempts);
        }
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

/**
 * Aggregate the most recent attempts of a set of course participants.
 *
 * @param array $activities
 * @param int[] $userids
 * @return array keyed by activity id
 */
function kopfuebung_get_group_matrix(array $activities, array $userids): array {
    $userids = array_values(array_unique(array_map('intval', $userids)));
    $participantcount = count($userids);
    $matrix = [];

    foreach ($activities as $activity) {
        $matrix[$activity->id] = [
            'cells' => array_fill(1, 10, ['correct' => 0, 'answered' => 0]),
            'correct' => 0,
            'answered' => 0,
            'participantcount' => $participantcount,
        ];
    }

    foreach ($userids as $userid) {
        $usermatrix = kopfuebung_get_user_matrix($activities, $userid);
        foreach ($activities as $activity) {
            foreach ($usermatrix[$activity->id]['cells'] as $position => $state) {
                if ($state === 'correct') {
                    $matrix[$activity->id]['cells'][$position]['correct']++;
                    $matrix[$activity->id]['correct']++;
                }
                if (in_array($state, ['correct', 'partiallycorrect', 'incorrect'], true)) {
                    $matrix[$activity->id]['cells'][$position]['answered']++;
                    $matrix[$activity->id]['answered']++;
                }
            }
        }
    }

    return $matrix;
}
