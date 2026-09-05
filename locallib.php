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
    $modulecontextids = array_map('intval', $modulecontextids);

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
        'id, name, contextid'
    );
}

/**
 * Persist the complete question assignment for an activity.
 *
 * Existing records are retained when possible so references remain stable.
 *
 * @param int $kopfuebungid
 * @param array $assignments position => question id
 * @param stdClass[] $availablequestions
 * @param int $questioncount
 */
function kopfuebung_save_question_assignments(
    int $kopfuebungid,
    array $assignments,
    array $availablequestions,
    int $questioncount = 10
): void {
    global $DB;

    $normalised = [];
    foreach ($assignments as $position => $questionid) {
        $position = (int) $position;
        $questionid = (int) $questionid;
        if ($position < 1 || $position > $questioncount || !$questionid) {
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
            'id, name, activitytype, activitystate, timestarted, timelimit, questioncount, selfassessment, difficultyassessment',
            IGNORE_MISSING
        );
        if (!$activity || $activity->activitytype === 'overview') {
            continue;
        }
        $activity->cmid = $cm->id;
        $activities[$cm->sectionnum . ':' . $cm->id] = $activity;
    }

    ksort($activities, SORT_NATURAL);
    return array_values($activities);
}

/**
 * Return active enrolled users who may attempt at least one visible Kopfuebung.
 *
 * @param stdClass $course
 * @param array $activities
 * @return stdClass[] keyed by user id
 */
function kopfuebung_get_course_participants(stdClass $course, array $activities): array {
    $context = context_course::instance($course->id);
    $enrolledusers = get_enrolled_users(
        $context,
        '',
        0,
        'u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
        'u.lastname ASC, u.firstname ASC',
        0,
        0,
        true
    );

    $participants = [];
    foreach ($enrolledusers as $participant) {
        foreach ($activities as $activity) {
            $activitycontext = context_module::instance($activity->cmid);
            if (has_capability('mod/kopfuebung:attempt', $activitycontext, $participant->id)) {
                $participants[$participant->id] = $participant;
                break;
            }
        }
    }
    return $participants;
}

/**
 * Return a user's latest finished attempt for an activity, regardless of later restarts.
 *
 * @param int $kopfuebungid
 * @param int $userid
 * @return stdClass|null
 */
function kopfuebung_get_latest_finished_attempt(int $kopfuebungid, int $userid): ?stdClass {
    global $DB;

    $attempts = $DB->get_records(
        'kopfuebung_attempts',
        ['kopfuebungid' => $kopfuebungid, 'userid' => $userid, 'status' => 'finished'],
        'id DESC',
        '*',
        0,
        1
    );
    $attempt = reset($attempts);
    return $attempt ?: null;
}

/**
 * Finish all still-open attempts and close an activity whose shared time limit has expired.
 *
 * A short grace period lets the browser submit the answers visible at 00:00 before another
 * request closes the activity from the server side.
 *
 * @param stdClass $kopfuebung
 * @param int $graceseconds
 * @return bool Whether the activity was closed by this call.
 */
function kopfuebung_close_expired_activity(stdClass $kopfuebung, int $graceseconds = 2): bool {
    global $CFG, $DB;

    if (empty($kopfuebung->activitystate) || empty($kopfuebung->timestarted) ||
            time() < (int) $kopfuebung->timestarted + (int) $kopfuebung->timelimit + $graceseconds) {
        return false;
    }

    require_once($CFG->dirroot . '/question/engine/lib.php');
    $now = time();
    $transaction = $DB->start_delegated_transaction();
    $attempts = $DB->get_records('kopfuebung_attempts', [
        'kopfuebungid' => $kopfuebung->id,
        'status' => 'inprogress',
    ]);
    foreach ($attempts as $attempt) {
        if (!empty($attempt->questionusageid)) {
            try {
                $quba = question_engine::load_questions_usage_by_activity($attempt->questionusageid);
                $quba->finish_all_questions($now);
                question_engine::save_questions_usage_by_activity($quba);
            } catch (Exception $exception) {
                debugging($exception->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $attempt->status = 'finished';
        $attempt->timefinished = $now;
        $DB->update_record('kopfuebung_attempts', $attempt);
    }
    $DB->delete_records('kopfuebung_ready', ['kopfuebungid' => $kopfuebung->id]);
    $DB->set_field('kopfuebung', 'activitystate', 0, ['id' => $kopfuebung->id]);
    $DB->set_field('kopfuebung', 'timemodified', $now, ['id' => $kopfuebung->id]);
    $transaction->allow_commit();
    $kopfuebung->activitystate = 0;
    $kopfuebung->timemodified = $now;
    return true;
}

/** Finish every currently open attempt, for example when a teacher stops an activity. */
function kopfuebung_finish_open_attempts(stdClass $kopfuebung): void {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/question/engine/lib.php');
    $now = time();
    $attempts = $DB->get_records('kopfuebung_attempts', [
        'kopfuebungid' => $kopfuebung->id,
        'status' => 'inprogress',
    ]);
    foreach ($attempts as $attempt) {
        if (!empty($attempt->questionusageid)) {
            $quba = question_engine::load_questions_usage_by_activity($attempt->questionusageid);
            $quba->finish_all_questions($now);
            question_engine::save_questions_usage_by_activity($quba);
        }
        $attempt->status = 'finished';
        $attempt->timefinished = $now;
        $DB->update_record('kopfuebung_attempts', $attempt);
    }
}

/** Return whether an attempt still needs its configured reflection responses. */
function kopfuebung_reflection_pending(stdClass $kopfuebung, stdClass $attempt): bool {
    global $DB;

    if (empty($kopfuebung->selfassessment) && empty($kopfuebung->difficultyassessment)) {
        return false;
    }
    $questionids = $DB->get_fieldset_select(
        'kopfuebung_questions', 'id',
        'kopfuebungid = :id AND sortorder <= :count',
        ['id' => $kopfuebung->id, 'count' => $kopfuebung->questioncount]
    );
    if (!$questionids) {
        return false;
    }
    list($insql, $params) = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'question');
    $params['attemptid'] = $attempt->id;
    $conditions = [];
    if (!empty($kopfuebung->selfassessment)) {
        $conditions[] = 'predictedcorrect IS NOT NULL';
    }
    if (!empty($kopfuebung->difficultyassessment)) {
        $conditions[] = 'difficulty IS NOT NULL';
    }
    $completed = $DB->count_records_select(
        'kopfuebung_reflections',
        "attemptid = :attemptid AND kopfquestionid $insql AND " . implode(' AND ', $conditions),
        $params
    );
    return $completed < count($questionids);
}

/**
 * Delete every attempt of one user for an activity so a clean retry is possible.
 *
 * @param int $kopfuebungid
 * @param int $userid
 */
function kopfuebung_reset_user_attempts(int $kopfuebungid, int $userid): void {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/question/engine/lib.php');

    $attempts = $DB->get_records('kopfuebung_attempts', [
        'kopfuebungid' => $kopfuebungid,
        'userid' => $userid,
    ]);
    $transaction = $DB->start_delegated_transaction();
    foreach ($attempts as $attempt) {
        $DB->delete_records('kopfuebung_reflections', ['attemptid' => $attempt->id]);
        $DB->delete_records('kopfuebung_answers', ['attemptid' => $attempt->id]);
        if (!empty($attempt->questionusageid)) {
            question_engine::delete_questions_usage_by_activity($attempt->questionusageid);
        }
    }
    $DB->delete_records('kopfuebung_attempts', [
        'kopfuebungid' => $kopfuebungid,
        'userid' => $userid,
    ]);
    $DB->delete_records('kopfuebung_ready', [
        'kopfuebungid' => $kopfuebungid,
        'userid' => $userid,
    ]);
    $transaction->allow_commit();
}

/**
 * Send a Moodle notification about feedback in the course overview.
 *
 * @param stdClass $course
 * @param stdClass $from
 * @param stdClass $to
 * @param int $conversationuserid User id of the personal conversation, or 0 for course feedback.
 * @param bool $isreply Whether the notification concerns a student's reply.
 */
function kopfuebung_send_feedback_notification(
    stdClass $course,
    stdClass $from,
    stdClass $to,
    int $conversationuserid,
    bool $isreply = false
): void {
    $stringdata = (object) [
        'author' => fullname($from),
        'course' => format_string($course->fullname),
    ];
    $subjectkey = $isreply ? 'feedbackreplynotificationsubject' : 'feedbacknotificationsubject';
    $bodykey = $isreply ? 'feedbackreplynotificationbody' : 'feedbacknotificationbody';
    $urlparams = ['id' => $course->id];
    if ($conversationuserid) {
        $urlparams['userid'] = $conversationuserid;
    }

    $notification = new \core\message\message();
    $notification->component = 'mod_kopfuebung';
    $notification->name = 'feedback';
    $notification->userfrom = $from->id;
    $notification->userto = $to->id;
    $notification->subject = get_string($subjectkey, 'kopfuebung', $stringdata);
    $notification->fullmessage = get_string($bodykey, 'kopfuebung', $stringdata);
    $notification->fullmessageformat = FORMAT_PLAIN;
    $notification->fullmessagehtml = format_text($notification->fullmessage, FORMAT_PLAIN);
    $notification->smallmessage = $notification->subject;
    $notification->notification = 1;
    $notification->contexturl = (new moodle_url('/mod/kopfuebung/overview.php', $urlparams))->out(false);
    $notification->contexturlname = get_string('viewfeedbackconversation', 'kopfuebung');

    message_send($notification);
}

/**
 * Load the ten course-wide diagnostic row labels.
 *
 * @param int $courseid
 * @return string[]
 */
function kopfuebung_get_course_labels(int $courseid, int $gridid = 0): array {
    global $DB;

    $labels = array_fill(1, 10, '');
    $records = $DB->get_records(
        'kopfuebung_labels',
        ['courseid' => $courseid, 'gridid' => $gridid],
        'position ASC'
    );
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
function kopfuebung_save_course_labels(int $courseid, array $labels, int $gridid = 0): void {
    global $DB;

    $transaction = $DB->start_delegated_transaction();
    for ($position = 1; $position <= 10; $position++) {
        $label = trim(clean_param($labels[$position] ?? '', PARAM_TEXT));
        $record = $DB->get_record('kopfuebung_labels', [
            'courseid' => $courseid,
            'gridid' => $gridid,
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
                'gridid' => $gridid,
                'position' => $position,
                'label' => $label,
                'timemodified' => time(),
            ]);
        }
    }
    $transaction->allow_commit();
}

/**
 * Return the additional label grids in a course, keyed by their first activity CM id.
 *
 * @param int $courseid
 * @return stdClass[]
 */
function kopfuebung_get_course_label_grids(int $courseid): array {
    global $DB;

    $records = $DB->get_records('kopfuebung_labelgrids', ['courseid' => $courseid], 'id ASC');
    $grids = [];
    foreach ($records as $record) {
        $record->labels = kopfuebung_get_course_labels($courseid, $record->id);
        $grids[(int) $record->startcmid] = $record;
    }
    return $grids;
}

/**
 * Split the ordered Kopfuebung activities into their diagnostic label grids.
 *
 * @param stdClass $course
 * @param array|null $activities
 * @param array|null $labelgrids
 * @return array
 */
function kopfuebung_get_course_grid_sections(
    stdClass $course,
    ?array $activities = null,
    ?array $labelgrids = null
): array {
    $activities = $activities ?? kopfuebung_get_course_activities($course);
    $labelgrids = $labelgrids ?? kopfuebung_get_course_label_grids($course->id);
    $sections = [];
    $current = (object) [
        'gridid' => 0,
        'number' => 1,
        'labels' => kopfuebung_get_course_labels($course->id),
        'activities' => [],
    ];

    foreach ($activities as $activity) {
        if (isset($labelgrids[$activity->cmid]) && $current->activities) {
            $sections[] = $current;
            $grid = $labelgrids[$activity->cmid];
            $current = (object) [
                'gridid' => (int) $grid->id,
                'number' => count($sections) + 1,
                'labels' => $grid->labels,
                'activities' => [],
            ];
        }
        $current->activities[] = $activity;
    }
    if ($current->activities) {
        $sections[] = $current;
    }

    return $sections;
}

/**
 * Return the additional offers in a course keyed by grid id and row position.
 *
 * @param int $courseid
 * @return array
 */
function kopfuebung_get_course_additional_offers(int $courseid): array {
    global $DB;

    $offers = [];
    $records = $DB->get_records('kopfuebung_offers', ['courseid' => $courseid]);
    if (!$records) {
        return $offers;
    }
    foreach ($records as $offer) {
        $offer->links = ['explanation' => [], 'practice' => []];
        $offer->userids = [];
        $offers[(int) $offer->gridid][(int) $offer->position] = $offer;
    }
    foreach ($DB->get_records_list(
        'kopfuebung_offer_links',
        'offerid',
        array_keys($records),
        'linkcategory ASC, sortorder ASC, id ASC'
    ) as $link) {
        if (isset($records[$link->offerid]->links[$link->linkcategory])) {
            $records[$link->offerid]->links[$link->linkcategory][] = $link;
        }
    }
    foreach ($DB->get_records_list('kopfuebung_offer_users', 'offerid', array_keys($records)) as $assignment) {
        $records[$assignment->offerid]->userids[] = (int) $assignment->userid;
    }
    return $offers;
}

/**
 * Count non-correct answers for one user, row and completed grid section.
 *
 * @param stdClass $section
 * @param array $usermatrix
 * @param int $position
 * @return int
 */
function kopfuebung_count_grid_incorrect(stdClass $section, array $usermatrix, int $position): int {
    $count = 0;
    foreach ($section->activities as $activity) {
        // Do not reveal outcomes from a currently running activity.
        if ($activity->activitystate) {
            continue;
        }
        $state = $usermatrix[$activity->id]['cells'][$position] ?? 'notattempted';
        if (in_array($state, ['partiallycorrect', 'incorrect'], true)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Resolve an additional-offer target to a URL and display name.
 *
 * @param stdClass $course
 * @param string $type
 * @param string|null $target
 * @return array|null
 */
function kopfuebung_resolve_offer_target(stdClass $course, string $type, ?string $target): ?array {
    if ($type === 'url' && $target && preg_match('#^https?://#i', $target)) {
        return ['url' => new moodle_url($target), 'name' => $target];
    }
    if ($type !== 'activity' || !$target) {
        return null;
    }

    try {
        $cm = get_fast_modinfo($course)->get_cm((int) $target);
    } catch (moodle_exception $exception) {
        return null;
    }
    if ((int) $cm->course !== (int) $course->id || $cm->deletioninprogress) {
        return null;
    }

    return [
        'url' => new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]),
        'name' => $cm->get_formatted_name(),
    ];
}

/**
 * Resolve every target in one category of an additional offer.
 *
 * @param stdClass $course
 * @param stdClass $offer
 * @param string $category
 * @return array
 */
function kopfuebung_resolve_offer_links(stdClass $course, stdClass $offer, string $category): array {
    $targets = [];
    foreach ($offer->links[$category] ?? [] as $link) {
        $target = kopfuebung_resolve_offer_target($course, $link->linktype, $link->target);
        if ($target) {
            if ($link->linktype === 'url' && trim((string) $link->linklabel) !== '') {
                $target['name'] = format_string($link->linklabel);
            }
            $targets[] = $target;
        }
    }
    return $targets;
}

/**
 * Create a label grid starting at a specific Kopfuebung course module.
 *
 * @param int $courseid
 * @param int $startcmid
 * @return int
 */
function kopfuebung_create_course_label_grid(int $courseid, int $startcmid): int {
    global $DB;

    $cm = get_coursemodule_from_id('kopfuebung', $startcmid, $courseid, false, MUST_EXIST);
    $existing = $DB->get_record('kopfuebung_labelgrids', [
        'courseid' => $courseid,
        'startcmid' => $cm->id,
    ]);
    if ($existing) {
        return (int) $existing->id;
    }

    $now = time();
    return $DB->insert_record('kopfuebung_labelgrids', (object) [
        'courseid' => $courseid,
        'startcmid' => $cm->id,
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
}

/**
 * Return the label grid that applies to a specific Kopfuebung activity.
 *
 * @param stdClass $course
 * @param int $cmid
 * @return string[]
 */
function kopfuebung_get_effective_course_labels(stdClass $course, int $cmid): array {
    $labels = kopfuebung_get_course_labels($course->id);
    $grids = kopfuebung_get_course_label_grids($course->id);

    foreach (kopfuebung_get_course_activities($course) as $activity) {
        if (isset($grids[$activity->cmid])) {
            $labels = $grids[$activity->cmid]->labels;
        }
        if ((int) $activity->cmid === $cmid) {
            break;
        }
    }
    return $labels;
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
            'finished' => false,
            'reflections' => [],
            'selfassessedcorrect' => 0,
            'selfassessedcount' => 0,
            'assessmentmatches' => 0,
            'difficultytotal' => 0,
            'difficultycount' => 0,
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
            $result['finished'] = $attempt->status === 'finished';
            $selectedquestions = $DB->get_records(
                'kopfuebung_questions',
                ['kopfuebungid' => $activity->id],
                'sortorder ASC, id ASC',
                'id, sortorder'
            );
            $selectedquestions = array_values($selectedquestions);
            $positions = array_map(static function($selectedquestion) {
                return (int) $selectedquestion->sortorder;
            }, $selectedquestions);
            $reflectionrecords = $DB->get_records('kopfuebung_reflections', ['attemptid' => $attempt->id]);
            $reflectionsbyquestion = [];
            foreach ($reflectionrecords as $reflection) {
                $reflectionsbyquestion[(int) $reflection->kopfquestionid] = $reflection;
            }
            foreach (array_values($quba->get_slots()) as $index => $slot) {
                $position = $positions[$index] ?? ($index + 1);
                if ($position < 1 || $position > 10) {
                    continue;
                }

                $selectedquestion = $selectedquestions[$index] ?? null;
                $reflection = $selectedquestion ? ($reflectionsbyquestion[(int) $selectedquestion->id] ?? null) : null;
                if ($reflection) {
                    $result['reflections'][$position] = $reflection;
                    if ($reflection->predictedcorrect !== null) {
                        $result['selfassessedcount']++;
                        $result['selfassessedcorrect'] += (int) $reflection->predictedcorrect;
                    }
                    if ($reflection->difficulty !== null) {
                        $result['difficultycount']++;
                        $result['difficultytotal'] += (int) $reflection->difficulty;
                    }
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
                if ($reflection && $reflection->predictedcorrect !== null) {
                    $iscorrect = $result['cells'][$position] === 'correct';
                    if ((bool) $reflection->predictedcorrect === $iscorrect) {
                        $result['assessmentmatches']++;
                    }
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
            'cells' => array_fill(1, 10, [
                'correct' => 0,
                'answered' => 0,
                'incorrectuserids' => [],
                'assessmentmatches' => 0,
                'assessmentcount' => 0,
                'difficultytotal' => 0,
                'difficultycount' => 0,
            ]),
            'correct' => 0,
            'answered' => 0,
            'participantcount' => $participantcount,
            'assessmentmatches' => 0,
            'assessmentcount' => 0,
            'difficultytotal' => 0,
            'difficultycount' => 0,
        ];
    }

    foreach ($userids as $userid) {
        $usermatrix = kopfuebung_get_user_matrix($activities, $userid);
        foreach ($activities as $activity) {
            foreach ($usermatrix[$activity->id]['cells'] as $position => $state) {
                $reflection = $usermatrix[$activity->id]['reflections'][$position] ?? null;
                if ($reflection && $reflection->predictedcorrect !== null && in_array($state, ['correct', 'partiallycorrect', 'incorrect'], true)) {
                    $matrix[$activity->id]['cells'][$position]['assessmentcount']++;
                    $matrix[$activity->id]['assessmentcount']++;
                    $matches = ((bool) $reflection->predictedcorrect) === ($state === 'correct');
                    if ($matches) {
                        $matrix[$activity->id]['cells'][$position]['assessmentmatches']++;
                        $matrix[$activity->id]['assessmentmatches']++;
                    }
                }
                if ($reflection && $reflection->difficulty !== null) {
                    $matrix[$activity->id]['cells'][$position]['difficultytotal'] += (int) $reflection->difficulty;
                    $matrix[$activity->id]['cells'][$position]['difficultycount']++;
                    $matrix[$activity->id]['difficultytotal'] += (int) $reflection->difficulty;
                    $matrix[$activity->id]['difficultycount']++;
                }
                if ($state === 'correct') {
                    $matrix[$activity->id]['cells'][$position]['correct']++;
                    $matrix[$activity->id]['correct']++;
                }
                if (in_array($state, ['correct', 'partiallycorrect', 'incorrect'], true)) {
                    $matrix[$activity->id]['cells'][$position]['answered']++;
                    $matrix[$activity->id]['answered']++;
                }
                if (in_array($state, ['partiallycorrect', 'incorrect'], true)) {
                    $matrix[$activity->id]['cells'][$position]['incorrectuserids'][] = $userid;
                }
            }
        }
    }

    return $matrix;
}
