<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_kopfuebung\local\hue;

defined('MOODLE_INTERNAL') || die();

/** Validated HUE package reader. */
class package {
    public const MIMETYPE = 'application/vnd.kopfuebung.hue+zip';

    /** @var array */
    private $manifest;
    /** @var \DOMDocument */
    private $xml;
    /** @var \DOMElement[] */
    private $questions = [];

    /** @param string $pathname @return self */
    public static function from_pathname(string $pathname): self {
        if (!class_exists('ZipArchive')) {
            throw new \moodle_exception('huezipunavailable', 'kopfuebung');
        }
        $zip = new \ZipArchive();
        if ($zip->open($pathname) !== true) {
            throw new \moodle_exception('hueinvalidarchive', 'kopfuebung');
        }
        try {
            if ($zip->numFiles > 50) {
                throw new \moodle_exception('huetoomanyfiles', 'kopfuebung');
            }
            $totalsize = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = str_replace('\\', '/', (string) $stat['name']);
                $totalsize += (int) $stat['size'];
                if ($name === '' || $name[0] === '/' || preg_match('~(^|/)\.\.(/|$)~', $name)) {
                    throw new \moodle_exception('hueunsafearchive', 'kopfuebung');
                }
            }
            if ($totalsize > 50 * 1024 * 1024) {
                throw new \moodle_exception('huearchiveoversized', 'kopfuebung');
            }
            $mimetype = $zip->getFromName('mimetype');
            $manifestjson = $zip->getFromName('manifest.json');
            $questionsxml = $zip->getFromName('questions.xml');
            if ($mimetype === false || $manifestjson === false || $questionsxml === false) {
                throw new \moodle_exception('huemissingfiles', 'kopfuebung');
            }
            if (rtrim($mimetype, "\n") !== self::MIMETYPE || substr_count($mimetype, "\n") > 1) {
                throw new \moodle_exception('hueinvalidmimetype', 'kopfuebung');
            }
            if (strlen($manifestjson) > 1024 * 1024 || strlen($questionsxml) > 40 * 1024 * 1024) {
                throw new \moodle_exception('huearchiveoversized', 'kopfuebung');
            }
        } finally {
            $zip->close();
        }
        return self::from_contents($manifestjson, $questionsxml);
    }

    /** @param string $manifestjson @param string $questionsxml @return self */
    public static function from_contents(string $manifestjson, string $questionsxml): self {
        try {
            $manifest = json_decode($manifestjson, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new \moodle_exception('hueinvalidmanifest', 'kopfuebung');
        }
        self::validate_manifest($manifest);

        $previous = libxml_use_internal_errors(true);
        $xml = new \DOMDocument();
        $xml->preserveWhiteSpace = true;
        $loaded = $xml->loadXML($questionsxml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded || !$xml->documentElement || $xml->documentElement->tagName !== 'quiz') {
            throw new \moodle_exception('hueinvalidquestionsxml', 'kopfuebung');
        }

        $instance = new self();
        $instance->manifest = $manifest;
        $instance->xml = $xml;
        foreach ($xml->documentElement->childNodes as $node) {
            if (!$node instanceof \DOMElement || $node->tagName !== 'question') {
                continue;
            }
            if ($node->getAttribute('type') === 'category') {
                throw new \moodle_exception('huecategorynotallowed', 'kopfuebung');
            }
            $instance->questions[] = $node;
        }
        if (count($instance->questions) !== count($manifest['questions'])) {
            throw new \moodle_exception('huequestioncountmismatch', 'kopfuebung');
        }
        foreach ($manifest['questions'] as $reference) {
            $node = $instance->questions[$reference['xml_index'] - 1] ?? null;
            if (!$node || hash('sha256', $node->C14N(false, false)) !== $reference['fingerprint']) {
                throw new \moodle_exception('huefingerprintmismatch', 'kopfuebung', '', $reference['position']);
            }
        }
        return $instance;
    }

    /** @param mixed $manifest */
    private static function validate_manifest($manifest): void {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'HUE' ||
                ($manifest['format_version'] ?? '') !== '1.0' ||
                !preg_match($uuid, (string) ($manifest['package_id'] ?? ''))) {
            throw new \moodle_exception('hueinvalidmanifest', 'kopfuebung');
        }
        $activity = $manifest['activity'] ?? null;
        $questions = $manifest['questions'] ?? null;
        if (!is_array($activity) || !is_array($questions) ||
                !in_array((int) ($activity['question_count'] ?? 0), [8, 9, 10], true) ||
                count($questions) !== (int) $activity['question_count'] ||
                trim((string) ($activity['name'] ?? '')) === '' ||
                (int) ($activity['time_limit_seconds'] ?? 0) < 1) {
            throw new \moodle_exception('hueinvalidmanifest', 'kopfuebung');
        }
        foreach (['self_assessment', 'difficulty_assessment', 'allow_ready_withdrawal'] as $field) {
            if (!array_key_exists($field, $activity) || !is_bool($activity[$field])) {
                throw new \moodle_exception('hueinvalidmanifest', 'kopfuebung');
            }
        }
        $description = $activity['description'] ?? null;
        if (!is_array($description) || !isset($description['format'], $description['text']) ||
                !in_array($description['format'], ['html', 'moodle', 'plain', 'markdown'], true)) {
            throw new \moodle_exception('hueinvalidmanifest', 'kopfuebung');
        }
        $positions = [];
        $indexes = [];
        $ids = [];
        foreach ($questions as $reference) {
            $position = (int) ($reference['position'] ?? 0);
            $xmlindex = (int) ($reference['xml_index'] ?? 0);
            $id = (string) ($reference['id'] ?? '');
            if (!preg_match($uuid, $id) || !preg_match('/^[0-9a-f]{64}$/', (string) ($reference['fingerprint'] ?? '')) ||
                    $position < 1 || $position > count($questions) ||
                    $xmlindex < 1 || $xmlindex > count($questions)) {
                throw new \moodle_exception('hueinvalidmanifest', 'kopfuebung');
            }
            $positions[] = $position;
            $indexes[] = $xmlindex;
            $ids[] = $id;
        }
        sort($positions);
        sort($indexes);
        if ($positions !== range(1, count($questions)) || $indexes !== range(1, count($questions)) ||
                count(array_unique($ids)) !== count($ids)) {
            throw new \moodle_exception('hueinvalidmanifest', 'kopfuebung');
        }
    }

    /** @return array */
    public function get_manifest(): array {
        return $this->manifest;
    }

    /** @return array */
    public function get_question_summaries(): array {
        $summaries = [];
        $xpath = new \DOMXPath($this->xml);
        foreach ($this->manifest['questions'] as $reference) {
            $node = $this->questions[$reference['xml_index'] - 1];
            $name = $xpath->evaluate('string(name/text)', $node);
            $summaries[] = [
                'id' => $reference['id'],
                'position' => $reference['position'],
                'xml_index' => $reference['xml_index'],
                'fingerprint' => $reference['fingerprint'],
                'qtype' => $node->getAttribute('type'),
                'name' => $name !== '' ? $name : get_string('unknownquestion', 'kopfuebung', $reference['position']),
                'preview' => $xpath->evaluate('string(questiontext/text)', $node),
            ];
        }
        usort($summaries, static function(array $left, array $right): int {
            return $left['position'] <=> $right['position'];
        });
        return $summaries;
    }

    /** @param int[] $xmlindexes @return string */
    public function get_questions_xml(array $xmlindexes): string {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $quiz = $document->appendChild($document->createElement('quiz'));
        foreach ($xmlindexes as $xmlindex) {
            $node = $this->questions[(int) $xmlindex - 1] ?? null;
            if (!$node) {
                throw new \moodle_exception('hueinvalidmanifest', 'kopfuebung');
            }
            $quiz->appendChild($document->importNode($node, true));
        }
        return $document->saveXML();
    }

    /** @return string */
    public static function generate_uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
