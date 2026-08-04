<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');
require_once(__DIR__ . '/classes/form/additional_offer_form.php');

$id = required_param('id', PARAM_INT);
$gridid = required_param('gridid', PARAM_INT);
$position = required_param('position', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
$context = context_course::instance($course->id);
require_login($course);
require_capability('mod/kopfuebung:viewoverview', $context);

if ($position < 1 || $position > 10) {
    throw new moodle_exception('invalidposition', 'kopfuebung');
}

$activities = kopfuebung_get_course_activities($course);
$participants = kopfuebung_get_course_participants($course, $activities);
$sections = kopfuebung_get_course_grid_sections($course, $activities);
$section = null;
foreach ($sections as $candidate) {
    if ((int) $candidate->gridid === $gridid) {
        $section = $candidate;
        break;
    }
}
if (!$section) {
    throw new moodle_exception('invalidrecord', 'error', '', 'grid');
}

$label = trim($section->labels[$position] ?? '');
if ($label === '') {
    $label = get_string('unlabelled', 'kopfuebung');
}
$canmanage = has_capability('mod/kopfuebung:manageadditionaloffers', $context);
$PAGE->set_url('/mod/kopfuebung/additionaloffer.php', [
    'id' => $course->id,
    'gridid' => $gridid,
    'position' => $position,
]);
$PAGE->set_title(get_string($canmanage ? 'configureadditionaloffer' : 'additionalofferstudentheading',
    'kopfuebung', $label));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$courseoffers = kopfuebung_get_course_additional_offers($course->id);
$offer = $courseoffers[$gridid][$position] ?? null;

if ($canmanage) {
    $activityoptions = [];
    foreach (get_fast_modinfo($course)->get_cms() as $cm) {
        if ($cm->deletioninprogress || !$cm->has_view()) {
            continue;
        }
        $name = $cm->get_formatted_name() . ' [' . $cm->modname . ']';
        if (!$cm->visible || !$cm->visibleoncoursepage) {
            $name .= ' — ' . get_string('activityhidden', 'kopfuebung');
        }
        $activityoptions[(int) $cm->id] = $name;
    }

    $participantoptions = [];
    foreach ($participants as $participant) {
        $participantoptions[(int) $participant->id] = fullname($participant);
    }

    $form = new \mod_kopfuebung\form\additional_offer_form($PAGE->url, [
        'context' => $context,
        'activityoptions' => $activityoptions,
        'participantoptions' => $participantoptions,
        'thresholdmax' => count($section->activities),
        'hasoffer' => (bool) $offer,
    ]);

    $formdata = (object) [
        'id' => $course->id,
        'gridid' => $gridid,
        'position' => $position,
        'threshold' => $offer ? min((int) $offer->threshold, count($section->activities)) : 1,
        'hint_editor' => [
            'text' => $offer->hint ?? '',
            'format' => $offer->hintformat ?? FORMAT_HTML,
        ],
        'explanationcmids' => $offer ? array_values(array_map(static function($link): int {
            return (int) $link->target;
        }, array_filter($offer->links['explanation'], static function($link): bool {
            return $link->linktype === 'activity';
        }))) : [],
        'explanationurls' => $offer ? implode("\n", array_map(static function($link): string {
            return $link->target;
        }, array_filter($offer->links['explanation'], static function($link): bool {
            return $link->linktype === 'url';
        }))) : '',
        'practicecmids' => $offer ? array_values(array_map(static function($link): int {
            return (int) $link->target;
        }, array_filter($offer->links['practice'], static function($link): bool {
            return $link->linktype === 'activity';
        }))) : [],
        'practiceurls' => $offer ? implode("\n", array_map(static function($link): string {
            return $link->target;
        }, array_filter($offer->links['practice'], static function($link): bool {
            return $link->linktype === 'url';
        }))) : '',
        'userids' => $offer ? $offer->userids : [],
    ];
    $form->set_data($formdata);

    if ($form->is_cancelled()) {
        redirect(new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id, 'userid' => -1]));
    }
    if ($data = $form->get_data()) {
        $transaction = $DB->start_delegated_transaction();
        if (!empty($data->deleteoffer) && $offer) {
            $DB->delete_records('kopfuebung_offer_users', ['offerid' => $offer->id]);
            $DB->delete_records('kopfuebung_offer_links', ['offerid' => $offer->id]);
            $DB->delete_records('kopfuebung_offers', ['id' => $offer->id]);
            $transaction->allow_commit();
            redirect(new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id, 'userid' => -1]),
                get_string('additionalofferdeleted', 'kopfuebung'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }

        $hint = $data->hint_editor;
        $record = (object) [
            'courseid' => $course->id,
            'gridid' => $gridid,
            'position' => $position,
            'threshold' => max(1, min((int) $data->threshold, count($section->activities))),
            'hint' => $hint['text'],
            'hintformat' => $hint['format'],
            'explanationtype' => 'none',
            'explanationtarget' => null,
            'practicetype' => 'none',
            'practicetarget' => null,
            'timemodified' => time(),
        ];
        if ($offer) {
            $record->id = $offer->id;
            $DB->update_record('kopfuebung_offers', $record);
            $offerid = (int) $offer->id;
            $DB->delete_records('kopfuebung_offer_users', ['offerid' => $offerid]);
            $DB->delete_records('kopfuebung_offer_links', ['offerid' => $offerid]);
        } else {
            $record->timecreated = $record->timemodified;
            $offerid = $DB->insert_record('kopfuebung_offers', $record);
        }

        foreach (array_unique(array_map('intval', $data->userids ?? [])) as $userid) {
            if (isset($participants[$userid])) {
                $DB->insert_record('kopfuebung_offer_users', (object) [
                    'offerid' => $offerid,
                    'userid' => $userid,
                ]);
            }
        }
        foreach (['explanation', 'practice'] as $category) {
            $sortorder = 0;
            foreach (array_unique(array_map('intval', $data->{$category . 'cmids'} ?? [])) as $cmid) {
                if (isset($activityoptions[$cmid])) {
                    $DB->insert_record('kopfuebung_offer_links', (object) [
                        'offerid' => $offerid,
                        'linkcategory' => $category,
                        'linktype' => 'activity',
                        'target' => (string) $cmid,
                        'sortorder' => ++$sortorder,
                    ]);
                }
            }
            $urls = preg_split('/\R/', trim($data->{$category . 'urls'} ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach (array_unique(array_map('trim', $urls)) as $url) {
                $DB->insert_record('kopfuebung_offer_links', (object) [
                    'offerid' => $offerid,
                    'linkcategory' => $category,
                    'linktype' => 'url',
                    'target' => $url,
                    'sortorder' => ++$sortorder,
                ]);
            }
        }
        $transaction->allow_commit();
        if (!empty($data->saveandreturn)) {
            redirect(new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id, 'userid' => -1]),
                get_string('additionaloffersaved', 'kopfuebung'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        redirect($PAGE->url, get_string('additionaloffersaved', 'kopfuebung'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('configureadditionaloffer', 'kopfuebung', $label));
    echo html_writer::tag('p', get_string('additionaloffergridcontext', 'kopfuebung', [
        'grid' => $section->number,
        'activities' => count($section->activities),
    ]));
    echo $OUTPUT->notification(get_string('hiddenactivitywarning', 'kopfuebung'), 'info');
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

if (!isset($participants[$USER->id]) || !$offer) {
    throw new moodle_exception('additionaloffernotavailable', 'kopfuebung');
}
$usermatrix = kopfuebung_get_user_matrix($activities, $USER->id);
$incorrectcount = kopfuebung_count_grid_incorrect($section, $usermatrix, $position);
$isindividual = in_array((int) $USER->id, $offer->userids, true);
if (!$isindividual && $incorrectcount < (int) $offer->threshold) {
    throw new moodle_exception('additionaloffernotavailable', 'kopfuebung');
}

$explanations = kopfuebung_resolve_offer_links($course, $offer, 'explanation');
$practiceitems = kopfuebung_resolve_offer_links($course, $offer, 'practice');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('additionalofferstudentheading', 'kopfuebung', $label));
echo html_writer::tag('p', get_string('incorrectcategorycount', 'kopfuebung', [
    'category' => $label,
    'count' => $incorrectcount,
]));
if (trim((string) $offer->hint) !== '') {
    echo html_writer::div(format_text($offer->hint, $offer->hintformat, ['context' => $context]),
        'kopfuebung-additional-hint');
}
if ($explanations) {
    $items = [];
    foreach ($explanations as $explanation) {
        $items[] = html_writer::tag('li', html_writer::link(
            $explanation['url'],
            $explanation['name'],
            ['target' => '_blank', 'rel' => 'noopener noreferrer']
        ));
    }
    echo html_writer::tag('p', get_string('explanationlinkintro', 'kopfuebung'));
    echo html_writer::tag('ul', implode('', $items));
}
if ($practiceitems) {
    $items = [];
    foreach ($practiceitems as $practice) {
        $items[] = html_writer::tag('li', html_writer::link(
            $practice['url'],
            $practice['name'],
            ['target' => '_blank', 'rel' => 'noopener noreferrer']
        ));
    }
    echo html_writer::tag('p', get_string('practicelinkintro', 'kopfuebung'));
    echo html_writer::tag('ul', implode('', $items));
}
echo html_writer::div(get_string(
    $isindividual ? 'additionalofferindividualreason' : 'additionalofferthresholdreason',
    'kopfuebung',
    $isindividual ? null : (object) ['threshold' => $offer->threshold, 'count' => $incorrectcount]
), 'alert alert-info mt-4');
echo $OUTPUT->single_button(
    new moodle_url('/mod/kopfuebung/overview.php', ['id' => $course->id]),
    get_string('backtooverview', 'kopfuebung'),
    'get'
);
echo $OUTPUT->footer();
