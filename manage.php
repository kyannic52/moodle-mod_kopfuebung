<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$addquestionids = optional_param_array('questionids', [], PARAM_INT);
$categoryid = optional_param('categoryid', 0, PARAM_INT);
$search = optional_param('qsearch', '', PARAM_TEXT);
$questiontag = optional_param('tag', '', PARAM_TAG);

/**
 * Return latest ready question-bank questions available in this activity/course.
 *
 * @param stdClass $course
 * @param context_module $modulecontext
 * @param int[] $excludedids
 * @param int $categoryid
 * @param string $search
 * @return stdClass[]
 */
function kopfuebung_get_available_questions(
    stdClass $course,
    context_module $modulecontext,
    array $excludedids,
    int $categoryid = 0,
    string $search = ''
): array {
    global $DB;

    $contextids = [
        context_system::instance()->id,
        context_course::instance($course->id)->id,
        $modulecontext->id,
    ];

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
 * @param context_module $modulecontext
 * @return stdClass[]
 */
function kopfuebung_get_question_categories(stdClass $course, context_module $modulecontext): array {
    global $DB;

    $contextids = [
        context_system::instance()->id,
        context_course::instance($course->id)->id,
        $modulecontext->id,
    ];
    list($contextsql, $params) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');

    return $DB->get_records_select('question_categories', "contextid $contextsql", $params, 'name ASC', 'id, name, contextid');
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

    $availablequestions = kopfuebung_get_available_questions($course, $context, [], $categoryid, $search);
    $currentcount = $DB->count_records('kopfuebung_questions', ['kopfuebungid' => $kopfuebung->id]);
    $newquestionids = [];
    foreach (array_unique($addquestionids) as $addquestionid) {
        if (isset($availablequestions[$addquestionid]) && !$DB->record_exists('kopfuebung_questions', [
            'kopfuebungid' => $kopfuebung->id,
            'questionid' => $addquestionid,
        ])) {
            $newquestionids[] = $addquestionid;
        }
    }
    if ($currentcount + count($newquestionids) > 10) {
        throw new moodle_exception('maximumquestions', 'kopfuebung', $PAGE->url);
    }

    foreach ($addquestionids as $addquestionid) {
        if (!isset($availablequestions[$addquestionid])) {
            throw new moodle_exception('invalidrecord', 'error', $PAGE->url, 'question');
        }

        if ($DB->record_exists('kopfuebung_questions', ['kopfuebungid' => $kopfuebung->id, 'questionid' => $addquestionid])) {
            continue;
        }

        $tag = $questiontag;
        if ($tag === '') {
            $tag = $availablequestions[$addquestionid]->categoryname ?: $availablequestions[$addquestionid]->qtype;
        }
        $record = (object) [
            'kopfuebungid' => $kopfuebung->id,
            'questionid' => $addquestionid,
            'tag' => $tag,
            'sortorder' => $DB->count_records('kopfuebung_questions', ['kopfuebungid' => $kopfuebung->id]) + 1,
            'timecreated' => time(),
        ];
        $DB->insert_record('kopfuebung_questions', $record);
    }

    redirect($PAGE->url);
}

$questions = $DB->get_records('kopfuebung_questions', ['kopfuebungid' => $kopfuebung->id], 'sortorder ASC, id ASC');
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
$categories = kopfuebung_get_question_categories($course, $context);
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

$availablequestions = kopfuebung_get_available_questions($course, $context, $questionids, $categoryid, $search);
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
    $table->head = [get_string('questionid', 'kopfuebung'), get_string('name'), get_string('questiontag', 'kopfuebung'), ''];
    foreach ($questions as $question) {
        $name = $questionnames[$question->questionid] ?? get_string('unknownquestion', 'kopfuebung', $question->questionid);
        $deleteurl = new moodle_url('/mod/kopfuebung/manage.php', [
            'id' => $cm->id,
            'delete' => $question->id,
            'sesskey' => sesskey(),
        ]);
        $table->data[] = [
            $question->questionid,
            format_string($name),
            s($question->tag),
            html_writer::link($deleteurl, get_string('delete')),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
