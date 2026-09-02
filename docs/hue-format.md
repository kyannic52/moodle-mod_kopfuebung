# HUE Package Format 1.0

## Status

This document defines version 1.0 of HUE, the **Hausuebung Exchange Format**
(German display name: **Hausübung Exchangeformat**).
HUE is an exchange format for complete Kopfuebung activities used by the Moodle
activity module `mod_kopfuebung`.

The key words **MUST**, **MUST NOT**, **REQUIRED**, **SHOULD**, **SHOULD NOT**,
and **MAY** in this document indicate normative requirements.

## Design goals

A HUE package is intended to:

- appear to users as one file with the `.hue` extension;
- carry the editable settings and ordered questions of one Kopfuebung;
- preserve Moodle question data through Moodle XML;
- support reliable detection of questions previously imported from HUE;
- be straightforward for software and AI-assisted authoring tools to create;
- provide the source data for later worksheet, solution sheet, answer sheet, and
  presentation conversion.

HUE 1.0 is Moodle-oriented. It does not define interchange behavior for other
learning management systems.

## Package container

A HUE file MUST be a ZIP archive with the `.hue` filename extension. ZIP entry
names MUST use forward slashes and MUST be relative paths. A package MUST NOT
contain absolute paths, `..` path segments, symbolic links, encrypted entries,
or executable code.

The archive MUST contain these files at its root:

```text
mimetype
manifest.json
questions.xml
```

The `mimetype` file MUST contain this ASCII string with no byte-order mark. It
MAY be followed by exactly one line-feed byte (`LF`), but no other whitespace:

```text
application/vnd.kopfuebung.hue+zip
```

The `mimetype` entry SHOULD be the first ZIP entry and SHOULD be stored without
compression. All other entries MAY use standard ZIP deflate compression.

Importers MUST apply configurable limits to archive size, extracted size, entry
count, and XML size. Importers MUST prevent ZIP path traversal and MUST parse XML
with external entities and external network access disabled.

## Character encoding

`manifest.json` and `questions.xml` MUST use UTF-8 without a byte-order mark.
Question text is retained exactly as represented by Moodle XML. HUE does not
define translations or alternate language variants of an activity.

## Manifest

`manifest.json` MUST conform to
[`schema/hue-manifest.schema.json`](../schema/hue-manifest.schema.json).

The manifest identifies the package and records the editable activity settings:

- activity name and description;
- time limit;
- number of question positions;
- self-assessment setting;
- difficulty-assessment setting;
- whether learners may withdraw readiness;
- stable question identities and ordering.

The format deliberately does not carry a Moodle question-bank category. The
importing teacher MUST choose or confirm the destination category in the target
course.

The format also excludes course-specific or runtime data, including:

- course and course-module identifiers;
- activity state and start time;
- users and readiness declarations;
- attempts, responses, grades, and reflections;
- diagnostic grids and labels;
- feedback conversations;
- assigned additional learning offers.

These values describe a particular course run rather than a reusable
Kopfuebung.

### Package identity

`package_id` MUST be a UUID. It identifies the logical exported activity across
repeated exports. `format_version` MUST be `1.0` for packages governed by this
document.

No Moodle or plugin minimum version is encoded in HUE 1.0. An importer determines
compatibility from the activity fields and question types it understands.

### Activity description

The description consists of its original text and Moodle text format. HUE 1.0
uses the format names `html`, `moodle`, `plain`, and `markdown` rather than
Moodle's internal numeric constants.

`question_count` MUST equal the number of entries in `questions` and MUST be a
question count accepted by the activity module.

### Question references

Every non-category `<question>` element in `questions.xml` MUST have exactly one
corresponding entry in the manifest `questions` array. Category pseudo-questions
MUST NOT occur in `questions.xml` because category selection belongs to the
importing course.

Each reference contains:

- `id`: a stable HUE UUID for the logical question;
- `position`: its one-based position in the Kopfuebung;
- `xml_index`: the one-based index of its `<question>` element in `questions.xml`;
- `fingerprint`: a SHA-256 digest used to detect changed content.

Positions and XML indexes MUST each form the contiguous sequence from `1` to
`question_count`, with no duplicates. Positions define activity order. XML order
SHOULD match position order, but importers MUST use the explicit mapping.

For HUE 1.0, the fingerprint is the lowercase hexadecimal SHA-256 digest of the
UTF-8 bytes produced by Canonical XML 1.0, without comments, for the referenced
`<question>` element. Producers MUST preserve an existing question UUID when
re-exporting the same logical question and MUST recalculate its fingerprint.

## Moodle XML questions

`questions.xml` MUST be a well-formed Moodle XML question file with a `<quiz>`
document element. Questions and their files, answers, feedback, grading data,
and question-type-specific configuration MUST be represented using the Moodle
XML import/export representation.

HUE supports:

- all question types supplied by Moodle core that can be represented in Moodle
  XML;
- STACK question types represented by an installed STACK question plugin;
- GeoGebra question types represented by an installed GeoGebra question plugin.

Support in the package format does not install question plugins. Before changing
course data, an importer MUST inspect all question types and report any type that
the target Moodle site cannot import. It MUST NOT silently replace or degrade an
unsupported question type.

HUE does not promise that every interactive question has a useful paper form.
Future HUE-to-LaTeX converters MUST report questions they cannot render and MAY
provide question-type-specific print mappings.

## Import identity and conflicts

An importer MUST compare the HUE question UUID and fingerprint with questions
previously imported into the selected question-bank context.

- An unknown UUID represents a new question.
- A known UUID with the same fingerprint represents unchanged content and MUST
  NOT create an unnecessary duplicate.
- A known UUID with a different fingerprint is a conflict.

For every conflict, the teacher MUST be offered these choices before the import
is committed:

1. overwrite or update the existing logical question;
2. import the incoming content as a distinct version;
3. skip the incoming question completely.

An implementation MAY offer one selection for all conflicts, but MUST allow the
teacher to review the affected questions. Import decisions must not change the
UUID or ordering of unrelated questions.

The storage mechanism used to associate HUE UUIDs and fingerprints with Moodle
question records is an importer implementation detail and is not prescribed by
the package format.

## Validation

A conforming importer MUST validate, in this order:

1. ZIP safety and required entries;
2. exact `mimetype` value;
3. JSON syntax and manifest schema;
4. supported `format_version`;
5. question counts, positions, and XML indexes;
6. XML well-formedness and Moodle XML structure;
7. question fingerprints;
8. availability of all referenced question types.

Validation MUST finish before an activity or question is created. Errors SHOULD
identify the affected file, field, or question position in language suitable for
a teacher.

## Extensions and forward compatibility

Optional implementation-specific data MAY be placed below a top-level
`extensions` object in the manifest. Extension names SHOULD use a reverse-domain
identifier. Importers MUST ignore extensions they do not understand unless an
extension explicitly declares itself required in a future format version.

Files not defined by this specification MAY be stored below `extensions/`.
Importers MUST ignore unknown files there after applying the same archive safety
checks. Unknown files at the package root SHOULD produce a warning.

Changes that make an existing valid package invalid, reinterpret existing
fields, or add required behavior require a new major format version.

## Reference package

An unpacked reference package is available in
[`examples/hue/minimal/`](../examples/hue/minimal/). It remains unpacked in Git
so its contents can be reviewed and changed without committing a generated
binary archive.
