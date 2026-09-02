<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$id = required_param('id', PARAM_INT);
$draftid = optional_param('draftid', 0, PARAM_INT);
$cm = get_coursemodule_from_id('kopfuebung', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$kopfuebung = $DB->get_record('kopfuebung', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/kopfuebung:managequestions', $context);
if (($kopfuebung->activitytype ?? 'exercise') !== 'exercise') {
    throw new moodle_exception('hueexerciseonly', 'kopfuebung');
}
if (!empty($kopfuebung->activitystate) || $DB->record_exists('kopfuebung_attempts', ['kopfuebungid' => $kopfuebung->id])) {
    throw new moodle_exception('hueimportattemptsexist', 'kopfuebung',
        new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]));
}

$PAGE->set_url('/mod/kopfuebung/hue_import.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('hueimport', 'kopfuebung'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$uploadform = new \mod_kopfuebung\form\hue_upload_form();
$uploadform->set_data(['id' => $cm->id]);
if ($uploadform->is_cancelled()) {
    redirect(new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]));
}
if ($uploaddata = $uploadform->get_data()) {
    $draftid = (int) $uploaddata->huefile;
}

$package = null;
$summaries = [];
$existing = [];
if ($draftid) {
    $file = \mod_kopfuebung\local\hue\service::get_draft_file($draftid);
    $package = \mod_kopfuebung\local\hue\service::read_stored_file($file);
    $summaries = $package->get_question_summaries();
    $existing = \mod_kopfuebung\local\hue\service::get_existing_questions(
        $course->id,
        array_column($summaries, 'id')
    );
}

if ($package && optional_param('confirmimport', 0, PARAM_BOOL)) {
    require_sesskey();
    $categoryid = required_param('categoryid', PARAM_INT);
    $category = $DB->get_record('question_categories', ['id' => $categoryid], '*', MUST_EXIST);
    if (!in_array((int) $category->contextid, kopfuebung_get_course_question_context_ids($course), true)) {
        throw new moodle_exception('invalidcategoryid', 'error');
    }
    $order = optional_param_array('order', [], PARAM_RAW_TRIMMED);
    $actions = optional_param_array('conflict', [], PARAM_ALPHA);
    \mod_kopfuebung\local\hue\service::import(
        $package,
        $course,
        $kopfuebung,
        $category,
        array_values($order),
        $actions
    );
    redirect(
        new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]),
        get_string('hueimportsuccess', 'kopfuebung'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($package ? 'huereviewheading' : 'hueimportheading', 'kopfuebung'));

if (!$package) {
    echo html_writer::tag('p', get_string('hueuploadintro', 'kopfuebung'), ['class' => 'mb-4']);
    $uploadform->display();
    echo $OUTPUT->footer();
    exit;
}

$manifest = $package->get_manifest();
echo html_writer::tag('p', get_string('huereviewintro', 'kopfuebung', $manifest['activity']['name']), ['class' => 'mb-4']);

$categories = kopfuebung_get_question_categories($course);
$categoryoptions = [];
foreach ($categories as $category) {
    $categorycontext = context::instance_by_id($category->contextid);
    if (has_capability('moodle/question:add', $categorycontext)) {
        $categoryoptions[$category->id] = format_string($category->name, true, ['context' => $categorycontext]);
    }
}
if (!$categoryoptions) {
    echo $OUTPUT->notification(get_string('huenowritablecategory', 'kopfuebung'), 'notifyproblem');
    echo $OUTPUT->continue_button(new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]));
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false),
    'id' => 'kopfuebung-hue-review-form']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'draftid', 'value' => $draftid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirmimport', 'value' => 1]);
echo html_writer::start_div('form-group row');
echo html_writer::tag('label', get_string('huedestinationcategory', 'kopfuebung'),
    ['for' => 'hue-category', 'class' => 'col-md-3 col-form-label d-flex pb-0 pr-md-5']);
echo html_writer::div(html_writer::select($categoryoptions, 'categoryid', key($categoryoptions), false,
    ['id' => 'hue-category', 'class' => 'custom-select']), 'col-md-9 form-inline align-items-start felement');
echo html_writer::end_div();

$table = new html_table();
$table->attributes['class'] = 'generaltable kopfuebung-hue-table';
$table->head = [get_string('hueposition', 'kopfuebung'), get_string('huequestiontitle', 'kopfuebung'),
    get_string('huepreview', 'kopfuebung'), get_string('hueexisting', 'kopfuebung')];
foreach ($summaries as $summary) {
    $known = $existing[$summary['id']] ?? null;
    $status = html_writer::span(get_string('huenewquestion', 'kopfuebung'), 'text-success');
    if ($known && hash_equals($known->fingerprint, $summary['fingerprint'])) {
        $status = html_writer::span(get_string('hueunchangedquestion', 'kopfuebung'), 'text-muted');
    } else if ($known) {
        $status = html_writer::div(get_string('huequestionconflict', 'kopfuebung'), 'alert alert-warning py-2 mb-2');
        $status .= html_writer::select([
            'keep' => get_string('huekeeporiginal', 'kopfuebung'),
            'incoming' => get_string('hueusenewversion', 'kopfuebung'),
            'skip' => get_string('hueskipquestion', 'kopfuebung'),
        ], 'conflict[' . $summary['id'] . ']', 'keep', false, ['class' => 'custom-select custom-select-sm']);
    }
    $previewid = 'hue-preview-' . clean_param($summary['id'], PARAM_ALPHANUMEXT);
    $preview = html_writer::tag('button', $OUTPUT->pix_icon('i/search', get_string('huepreview', 'kopfuebung')),
        ['type' => 'button', 'class' => 'btn btn-link p-1 hue-preview-button', 'data-preview' => $previewid,
            'title' => get_string('huepreviewquestion', 'kopfuebung')]);
    $preview .= html_writer::div(format_text($summary['preview'], FORMAT_HTML, ['context' => $context]),
        'd-none hue-preview-content', ['id' => $previewid]);
    $position = $OUTPUT->pix_icon('i/dragdrop', get_string('huereorder', 'kopfuebung'),
        'moodle', ['class' => 'mr-2 hue-drag-handle']) . html_writer::span($summary['position'], 'hue-position');
    $position .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'order[]',
        'value' => $summary['id']]);
    $table->data[] = new html_table_row([$position,
        format_string($summary['name']) . html_writer::div(s($summary['qtype']), 'small text-muted'), $preview, $status]);
    $table->data[count($table->data) - 1]->attributes = ['draggable' => 'true', 'class' => 'hue-question-row'];
}
echo html_writer::div(html_writer::table($table), 'table-responsive');
echo html_writer::div(
    html_writer::tag('button', get_string('hueimportquestions', 'kopfuebung'),
        ['type' => 'submit', 'class' => 'btn btn-primary']) . ' ' .
    html_writer::link(new moodle_url('/mod/kopfuebung/view.php', ['id' => $cm->id]),
        get_string('huecancelimport', 'kopfuebung'), ['class' => 'btn btn-secondary']),
    'mt-3'
);
echo html_writer::end_tag('form');

echo html_writer::start_div('modal fade', ['id' => 'hue-preview-modal', 'tabindex' => '-1', 'role' => 'dialog']);
echo html_writer::start_div('modal-dialog modal-lg', ['role' => 'document']);
echo html_writer::start_div('modal-content');
echo html_writer::div(html_writer::tag('h5', get_string('huepreview', 'kopfuebung'), ['class' => 'modal-title']) .
    html_writer::tag('button', '&times;', ['type' => 'button', 'class' => 'close hue-preview-close',
        'aria-label' => get_string('closebuttontitle')]), 'modal-header');
echo html_writer::div('', 'modal-body', ['id' => 'hue-preview-modal-body']);
echo html_writer::end_div(); echo html_writer::end_div(); echo html_writer::end_div();

$PAGE->requires->js_init_code(<<<'JS'
(function() {
    var table = document.querySelector('.kopfuebung-hue-table tbody');
    var dragged = null;
    if (table) {
        table.addEventListener('dragstart', function(event) {
            dragged = event.target.closest('.hue-question-row');
            if (dragged) { dragged.classList.add('is-dragging'); }
        });
        table.addEventListener('dragover', function(event) {
            event.preventDefault();
            var target = event.target.closest('.hue-question-row');
            if (dragged && target && target !== dragged) {
                var box = target.getBoundingClientRect();
                table.insertBefore(dragged, event.clientY < box.top + box.height / 2 ? target : target.nextSibling);
            }
        });
        table.addEventListener('dragend', function() {
            if (dragged) { dragged.classList.remove('is-dragging'); }
            dragged = null;
            Array.prototype.forEach.call(table.querySelectorAll('.hue-position'), function(element, index) {
                element.textContent = index + 1;
            });
        });
    }
    var modal = document.getElementById('hue-preview-modal');
    var body = document.getElementById('hue-preview-modal-body');
    document.addEventListener('click', function(event) {
        var button = event.target.closest('.hue-preview-button');
        if (button) {
            body.innerHTML = document.getElementById(button.dataset.preview).innerHTML;
            modal.classList.add('show'); modal.style.display = 'block'; modal.setAttribute('aria-modal', 'true');
        }
        if (event.target.closest('.hue-preview-close') || event.target === modal) {
            modal.classList.remove('show'); modal.style.display = 'none'; modal.removeAttribute('aria-modal');
        }
    });
}());
JS
);

echo $OUTPUT->footer();
