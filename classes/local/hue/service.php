<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_kopfuebung\local\hue;

defined('MOODLE_INTERNAL') || die();

/** HUE import and export operations using Moodle's XML question format. */
class service {
    public static function get_draft_file(int $draftid): \stored_file {
        global $USER;
        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id, 'user', 'draft', $draftid, 'id DESC', false
        );
        $file = reset($files);
        if (!$file || strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) !== 'hue') {
            throw new \moodle_exception('huenofile', 'kopfuebung');
        }
        return $file;
    }

    public static function read_stored_file(\stored_file $file): package {
        $path = make_request_directory('kopfuebung_hue') . DIRECTORY_SEPARATOR . 'upload.hue';
        $file->copy_content_to($path);
        return package::from_pathname($path);
    }

    public static function get_existing_questions(int $courseid, array $uuids): array {
        global $DB;
        if (!$uuids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($uuids, SQL_PARAMS_NAMED, 'hueuuid');
        $params['courseid'] = $courseid;
        $records = $DB->get_records_select(
            'kopfuebung_hue_questions', "courseid = :courseid AND hueuuid $insql", $params
        );
        $result = [];
        foreach ($records as $record) {
            if ($DB->record_exists('question', ['id' => $record->questionid])) {
                $result[$record->hueuuid] = $record;
            }
        }
        return $result;
    }

    public static function import(package $package, \stdClass $course, \stdClass $activity,
            \stdClass $category, array $ordereduuids, array $actions): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        require_once($CFG->dirroot . '/question/editlib.php');

        $context = \context::instance_by_id($category->contextid);
        require_capability('moodle/question:add', $context);
        $summaries = $package->get_question_summaries();
        $byuuid = [];
        foreach ($summaries as $summary) {
            $byuuid[$summary['id']] = $summary;
            if (!\question_bank::get_qtype($summary['qtype'], false)) {
                throw new \moodle_exception('hueunsupportedqtype', 'kopfuebung', '', $summary['qtype']);
            }
        }
        if (count($ordereduuids) !== count($summaries) ||
                count(array_unique($ordereduuids)) !== count($summaries)) {
            throw new \moodle_exception('hueinvalidorder', 'kopfuebung');
        }
        foreach ($ordereduuids as $uuid) {
            if (!isset($byuuid[$uuid])) {
                throw new \moodle_exception('hueinvalidorder', 'kopfuebung');
            }
        }

        $existing = self::get_existing_questions($course->id, array_keys($byuuid));
        $toimport = [];
        $resolved = [];
        foreach ($ordereduuids as $uuid) {
            $known = $existing[$uuid] ?? null;
            if ($known && ($known->fingerprint === $byuuid[$uuid]['fingerprint'] ||
                    ($actions[$uuid] ?? 'incoming') === 'keep')) {
                $resolved[$uuid] = (int) $known->questionid;
                continue;
            }
            if ($known && ($actions[$uuid] ?? 'incoming') === 'skip') {
                continue;
            }
            $toimport[] = $byuuid[$uuid]['xml_index'];
        }

        if ($toimport) {
            $xmlpath = make_request_directory('kopfuebung_hue_import') . DIRECTORY_SEPARATOR . 'questions.xml';
            file_put_contents($xmlpath, $package->get_questions_xml($toimport));
            $format = new \qformat_xml();
            $format->setCategory($category);
            $format->setCourse($course);
            $format->setContexts([$context]);
            $format->setFilename($xmlpath);
            $format->setRealfilename('questions.xml');
            $format->setMatchgrades('error');
            $format->setCatfromfile(false);
            $format->setContextfromfile(false);
            $format->setStoponerror(true);
            $format->set_display_progress(false);
            ob_start();
            try {
                $success = $format->importprocess();
                $messages = trim((string) ob_get_contents());
            } finally {
                ob_end_clean();
            }
            if (!$success || count($format->questionids) !== count($toimport)) {
                throw new \moodle_exception('huequestionimportfailed', 'kopfuebung', '', $messages);
            }
            foreach (array_values($toimport) as $index => $xmlindex) {
                foreach ($byuuid as $uuid => $summary) {
                    if ((int) $summary['xml_index'] === (int) $xmlindex) {
                        $questionid = (int) $format->questionids[$index];
                        $resolved[$uuid] = $questionid;
                        self::save_identity($course->id, $uuid, $questionid, $summary['fingerprint']);
                        break;
                    }
                }
            }
        }

        $manifest = $package->get_manifest();
        $settings = $manifest['activity'];
        $formatmap = ['html' => FORMAT_HTML, 'moodle' => FORMAT_MOODLE,
            'plain' => FORMAT_PLAIN, 'markdown' => FORMAT_MARKDOWN];
        $activity->name = clean_param($settings['name'], PARAM_TEXT);
        $activity->introformat = $formatmap[$settings['description']['format']];
        $activity->intro = clean_text($settings['description']['text'], $activity->introformat);
        $activity->timelimit = (int) $settings['time_limit_seconds'];
        $activity->questioncount = (int) $settings['question_count'];
        $activity->selfassessment = $settings['self_assessment'] ? 1 : 0;
        $activity->difficultyassessment = $settings['difficulty_assessment'] ? 1 : 0;
        $activity->allowreadywithdraw = $settings['allow_ready_withdrawal'] ? 1 : 0;
        $activity->huepackageid = $manifest['package_id'];
        $activity->timemodified = time();
        $assignments = [];
        foreach ($ordereduuids as $offset => $uuid) {
            if (isset($resolved[$uuid])) {
                $assignments[$offset + 1] = $resolved[$uuid];
            }
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->update_record('kopfuebung', $activity);
        \kopfuebung_save_question_assignments(
            $activity->id, $assignments, \kopfuebung_get_available_questions($course), $activity->questioncount
        );
        $transaction->allow_commit();
    }

    public static function save_identity(int $courseid, string $uuid, int $questionid, string $fingerprint): void {
        global $DB;
        $record = $DB->get_record('kopfuebung_hue_questions', ['courseid' => $courseid, 'hueuuid' => $uuid]);
        if ($record) {
            $record->questionid = $questionid;
            $record->fingerprint = $fingerprint;
            $record->timemodified = time();
            $DB->update_record('kopfuebung_hue_questions', $record);
        } else {
            $DB->insert_record('kopfuebung_hue_questions', (object) [
                'courseid' => $courseid, 'hueuuid' => $uuid, 'questionid' => $questionid,
                'fingerprint' => $fingerprint, 'timecreated' => time(), 'timemodified' => time(),
            ]);
        }
    }

    public static function export(\stdClass $course, \stdClass $activity): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        require_once($CFG->dirroot . '/question/editlib.php');

        $selected = array_values($DB->get_records(
            'kopfuebung_questions', ['kopfuebungid' => $activity->id], 'sortorder ASC'
        ));
        if (count($selected) !== (int) $activity->questioncount) {
            throw new \moodle_exception('hueexportincomplete', 'kopfuebung');
        }
        $questionids = array_map(static function($record): int { return (int) $record->questionid; }, $selected);
        $questions = [];
        foreach ($questionids as $questionid) {
            try {
                $question = \question_bank::load_question_data($questionid);
            } catch (\dml_missing_record_exception $exception) {
                throw new \moodle_exception('hueexportmissingquestion', 'kopfuebung');
            }
            $question->export_process = true;
            $questions[] = $question;
        }
        $contexts = array_map(static function(int $contextid) {
            return \context::instance_by_id($contextid);
        }, \kopfuebung_get_course_question_context_ids($course));
        $format = new \qformat_xml();
        $format->setQuestions($questions);
        $format->setCourse($course);
        $format->setContexts($contexts);
        $format->setCattofile(false);
        $format->setContexttofile(false);
        $questionsxml = $format->exportprocess();
        if ($questionsxml === '') {
            throw new \moodle_exception('huequestionexportfailed', 'kopfuebung');
        }

        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;
        if (!$document->loadXML($questionsxml, LIBXML_NONET)) {
            throw new \moodle_exception('huequestionexportfailed', 'kopfuebung');
        }
        $nodes = [];
        foreach ($document->getElementsByTagName('question') as $node) {
            if ($node->getAttribute('type') !== 'category') {
                $nodes[] = $node;
            }
        }
        if (count($nodes) !== count($questionids)) {
            throw new \moodle_exception('huequestionexportfailed', 'kopfuebung');
        }
        if (empty($activity->huepackageid)) {
            $activity->huepackageid = package::generate_uuid();
            $DB->set_field('kopfuebung', 'huepackageid', $activity->huepackageid, ['id' => $activity->id]);
        }
        $references = [];
        foreach ($nodes as $index => $node) {
            $fingerprint = hash('sha256', $node->C14N(false, false));
            $identities = $DB->get_records('kopfuebung_hue_questions', [
                'courseid' => $course->id, 'questionid' => $questionids[$index],
            ], 'id ASC', '*', 0, 1);
            $identity = reset($identities);
            $uuid = $identity ? $identity->hueuuid : package::generate_uuid();
            self::save_identity($course->id, $uuid, $questionids[$index], $fingerprint);
            $references[] = ['id' => $uuid, 'position' => $index + 1, 'xml_index' => $index + 1,
                'fingerprint' => $fingerprint];
        }
        $formatmap = [FORMAT_HTML => 'html', FORMAT_MOODLE => 'moodle', FORMAT_PLAIN => 'plain',
            FORMAT_MARKDOWN => 'markdown'];
        $manifest = [
            'format' => 'HUE', 'format_version' => '1.0', 'package_id' => $activity->huepackageid,
            'language' => str_replace('_', '-', current_language()), 'created_at' => gmdate('c'),
            'generator' => ['name' => 'mod_kopfuebung', 'version' => '0.18.1'],
            'activity' => [
                'name' => $activity->name,
                'description' => ['format' => $formatmap[(int) $activity->introformat] ?? 'html',
                    'text' => (string) $activity->intro],
                'time_limit_seconds' => (int) $activity->timelimit,
                'question_count' => (int) $activity->questioncount,
                'self_assessment' => (bool) $activity->selfassessment,
                'difficulty_assessment' => (bool) $activity->difficultyassessment,
                'allow_ready_withdrawal' => (bool) $activity->allowreadywithdraw,
            ],
            'questions' => $references,
        ];
        $path = make_request_directory('kopfuebung_hue_export') . DIRECTORY_SEPARATOR . 'export.hue';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \moodle_exception('hueexportarchivefailed', 'kopfuebung');
        }
        $zip->addFromString('mimetype', package::MIMETYPE);
        if (method_exists($zip, 'setCompressionName')) {
            $zip->setCompressionName('mimetype', \ZipArchive::CM_STORE);
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $zip->addFromString('questions.xml', $questionsxml);
        $zip->close();
        return $path;
    }
}
