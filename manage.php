<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$addquestionids = optional_param_array('questionids', [], PARAM_INT);
$questionpositions = optional_param_array('questionpositions', [], PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$search = optional_param('qsearch', '', PARAM_TEXT);
$questiontag = optional_param('tag', '', PARAM_TAG);

/**
 * Return latest ready question-bank questions available in this activity/course.
 *
 * @param stdClass $course
 * @param int[] $excludedids
 * @param int $categoryid
 * @param string $search
 * @return stdClass[]
 */
function kopfuebung_get_available_questions(
    stdClass $course,
    array $excludedids,
    int $categoryid = 0,
    string $search = ''
): array {
    global $DB;

    $contextids = kopfuebung_get_course_question_context_ids($course);

    list($contextsql, $params) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
    $excludesql = '';
    if ($excludedids) {
        list($notinsql, $excludeparams) = $DB->get_in_or_equal($excludedids, SQL_PARAMS_NAMED, 'exclude', false);
        $excludesql = " AND q.id $notinsql";
        $params += $excludeparams;
    }
    $categorysql = '';
    if ($categoryid) {
        $categorysql = " AND qc.id = :categoryid";
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

    $sql = "SELECT q.id,
                   q.name,
                   q.questiontext,
                   q.questiontextformat,
                   q.qtype,
                   qc.name AS categoryname,
                   qc.contextid
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
                   $excludesql
          ORDER BY qc.name ASC, q.name ASC";

    $params['readystatus'] = 'ready';
    $params['readystatussub'] = 'ready';
    $params['randomqtype'] = 'random';

    return $DB->get_records_sql($sql, $params);
}

/**
 * Return question categories available in this activity/course.
 *
 * @param stdClass $course
 * @return stdClass[]
 */
function kopfuebung_get_question_categories(stdClass $course): array {
    global $DB;

    $contextids = kopfuebung_get_course_question_context_ids($course);
    list($contextsql, $params) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');

    return $DB->get_records_select('question_categories', "contextid $contextsql", $params, 'name ASC', 'id, name, contextid');
}

/**
 * Return every context that can hold questions belonging to this course.
 *
 * This deliberately includes sibling activity contexts. Moodle stores question
 * banks created in activities in those module contexts, so limiting the query to
 * the current Kopfuebung context hides valid questions from the same course.
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
        [
            'modulelevel' => CONTEXT_MODULE,
            'courseid' => $course->id,
        ]
    );

    return array_values(array_unique(array_merge($contextids, $modulecontextids)));
}

$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:managequestions', $context);

$PAGE->set_url('/mod/kopfuebung/manage.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('managequestions', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if ($delete) {
    require_sesskey();
    $DB->delete_records('kopfuebung_questions', ['id' => $delete, 'kopfuebungid' => $kopfuebung->id]);
    redirect($PAGE->url);
}

if ($addquestionids) {
    require_sesskey();

    $availablequestions = kopfuebung_get_available_questions($course, [], $categoryid, $search);
    $usedpositions = array_map('intval', $DB->get_fieldset_select(
        'kopfuebung_questions',
        'sortorder',
        'kopfuebungid = ?',
        [$kopfuebung->id]
    ));
    $requestedpositions = [];
    $questionstoadd = [];

    foreach (array_unique($addquestionids) as $addquestionid) {
        if (!isset($availablequestions[$addquestionid])) {
            throw new moodle_exception('invalidrecord', 'error', $PAGE->url, 'question');
        }
        if ($DB->record_exists('kopfuebung_questions', ['kopfuebungid' => $kopfuebung->id, 'questionid' => $addquestionid])) {
            continue;
        }

        $position = $questionpositions[$addquestionid] ?? 0;
        if ($position < 1 || $position > 10) {
            throw new moodle_exception('selectquestionposition', 'kopfuebung', $PAGE->url);
        }
        if (in_array($position, $usedpositions, true) || isset($requestedpositions[$position])) {
            throw new moodle_exception('questionpositioninuse', 'kopfuebung', $PAGE->url, $position);
        }
        $requestedpositions[$position] = true;
        $questionstoadd[$addquestionid] = $position;
    }

    foreach ($questionstoadd as $addquestionid => $position) {
        $tag = $questiontag;
        if ($tag === '') {
            $tag = $availablequestions[$addquestionid]->categoryname ?: $availablequestions[$addquestionid]->qtype;
        }
        $record = (object) [
            'kopfuebungid' => $kopfuebung->id,
            'questionid' => $addquestionid,
            'tag' => $tag,
            'sortorder' => $position,
            'timecreated' => time(),
        ];
        $DB->insert_record('kopfuebung_questions', $record);
    }

    redirect($PAGE->url);
}

$questions = $DB->get_records('kopfuebung_questions', ['kopfuebungid' => $kopfuebung->id], 'sortorder ASC, id ASC');
$labels = kopfuebung_get_course_labels($course->id);
$usedpositions = array_map(static function($record) {
    return (int) $record->sortorder;
}, $questions);
$positionoptions = [0 => get_string('selectposition', 'kopfuebung')];
for ($position = 1; $position <= 10; $position++) {
    if (in_array($position, $usedpositions, true)) {
        continue;
    }
    $positionoptions[$position] = get_string('positionwithlabel', 'kopfuebung', [
        'position' => $position,
        'label' => $labels[$position] !== '' ? $labels[$position] : get_string('unlabelled', 'kopfuebung'),
    ]);
}
$questionids = array_map(static function($record) {
    return $record->questionid;
}, $questions);
$questionnames = [];
if ($questionids) {
    list($insql, $params) = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
    $questionnames = $DB->get_records_select_menu('question', "id $insql", $params, '', 'id, name');
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managequestions', 'kopfuebung'));

echo $OUTPUT->heading(get_string('availablequestions', 'kopfuebung'), 3);
$categories = kopfuebung_get_question_categories($course);
$categoryoptions = [0 => get_string('allcategories', 'kopfuebung')];
foreach ($categories as $category) {
    $categoryoptions[$category->id] = format_string($category->name);
}

$filterurl = new moodle_url('/mod/kopfuebung/manage.php');
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $filterurl->out(false),
    'class' => 'kopfuebung-questionbank-filters mb-3',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
echo html_writer::label(get_string('category'), 'kopfuebung-categoryid', false, ['class' => 'mr-2']);
echo html_writer::select($categoryoptions, 'categoryid', $categoryid, false, ['id' => 'kopfuebung-categoryid', 'class' => 'custom-select mr-3']);
echo html_writer::label(get_string('search'), 'kopfuebung-qsearch', false, ['class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'qsearch',
    'id' => 'kopfuebung-qsearch',
    'value' => $search,
    'class' => 'form-control mr-3',
]);
echo html_writer::tag('button', get_string('filter'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

$availablequestions = kopfuebung_get_available_questions($course, $questionids, $categoryid, $search);
if (!$availablequestions) {
    echo $OUTPUT->notification(get_string('noavailablequestions', 'kopfuebung'), 'notifymessage');
} else {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => $PAGE->url->out(false),
        'class' => 'kopfuebung-questionbank-select',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'categoryid', 'value' => $categoryid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qsearch', 'value' => $search]);

    $table = new html_table();
    $table->head = [
        '',
        get_string('name'),
        get_string('category'),
        get_string('questiontype', 'question'),
        get_string('preview', 'kopfuebung'),
        get_string('testposition', 'kopfuebung'),
    ];

    foreach ($availablequestions as $availablequestion) {
        $preview = shorten_text(trim(html_to_text($availablequestion->questiontext, 0, false)), 180);
        $checkboxid = 'question-' . $availablequestion->id;
        $checkbox = html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'questionids[]',
            'id' => $checkboxid,
            'value' => $availablequestion->id,
        ]);

        $table->data[] = [
            $checkbox,
            format_string($availablequestion->name),
            format_string($availablequestion->categoryname),
            s($availablequestion->qtype),
            s($preview),
            html_writer::select(
                $positionoptions,
                'questionpositions[' . $availablequestion->id . ']',
                0,
                false,
                ['class' => 'custom-select']
            ),
        ];
    }

    echo html_writer::table($table);
    echo html_writer::start_div('kopfuebung-questionbank-actions');
    echo html_writer::label(get_string('questiontag', 'kopfuebung'), 'kopfuebung-tag', false, ['class' => 'mr-2']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'tag',
        'id' => 'kopfuebung-tag',
        'class' => 'form-control mr-3',
        'maxlength' => 100,
        'placeholder' => get_string('questiontagoptional', 'kopfuebung'),
    ]);
    echo html_writer::tag('button', get_string('addselectedquestions', 'kopfuebung'), [
        'type' => 'submit',
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
}

echo $OUTPUT->heading(get_string('selectedquestions', 'kopfuebung'), 3);
if (!$questions) {
    echo $OUTPUT->notification(get_string('missingquestions', 'kopfuebung'), 'notifymessage');
} else {
    $table = new html_table();
    $table->head = [
        get_string('testposition', 'kopfuebung'),
        get_string('questionid', 'kopfuebung'),
        get_string('name'),
        get_string('questiontag', 'kopfuebung'),
        '',
    ];
    foreach ($questions as $question) {
        $name = $questionnames[$question->questionid] ?? get_string('unknownquestion', 'kopfuebung', $question->questionid);
        $deleteurl = new moodle_url('/mod/kopfuebung/manage.php', [
            'id' => $cm->id,
            'delete' => $question->id,
            'sesskey' => sesskey(),
        ]);
        $table->data[] = [
            get_string('positionwithlabel', 'kopfuebung', [
                'position' => $question->sortorder,
                'label' => $labels[$question->sortorder] !== ''
                    ? $labels[$question->sortorder]
                    : get_string('unlabelled', 'kopfuebung'),
            ]),
            $question->questionid,
            format_string($name),
            s($question->tag),
            html_writer::link($deleteurl, get_string('delete')),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
