<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:viewreports', $context);

$PAGE->set_url('/mod/kopfuebung/report.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('report', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$sql = "SELECT q.tag,
               COUNT(DISTINCT a.userid) AS studentsattempted,
               COUNT(ans.id) AS answerssubmitted
          FROM {kopfuebung_questions} q
     LEFT JOIN {kopfuebung_attempts} a ON a.kopfuebungid = q.kopfuebungid
     LEFT JOIN {kopfuebung_answers} ans ON ans.attemptid = a.id AND ans.kopfquestionid = q.id
         WHERE q.kopfuebungid = :kopfuebungid
      GROUP BY q.tag
      ORDER BY q.tag";
$rows = $DB->get_records_sql($sql, ['kopfuebungid' => $kopfuebung->id]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('studentprogressbytag', 'kopfuebung'));

$table = new html_table();
$table->head = [
    get_string('questiontag', 'kopfuebung'),
    get_string('studentsattempted', 'kopfuebung'),
    get_string('answerssubmitted', 'kopfuebung'),
];
foreach ($rows as $row) {
    $table->data[] = [s($row->tag), $row->studentsattempted, $row->answerssubmitted];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
