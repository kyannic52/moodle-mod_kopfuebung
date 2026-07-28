# Kopfübung Moodle Plugin

`mod_kopfuebung` is a Moodle activity module scaffold for timed, teacher-started question practice.

## Install

Place this folder in a Moodle installation at:

```text
mod/kopfuebung
```

Then visit **Site administration > Notifications** or run Moodle's CLI upgrade command.

## Current Capabilities

- Teachers create a Kopfübung activity and set a time limit.
- Teachers add question-bank question IDs and assign each selected question a tracking tag.
- Teachers start and stop the activity manually.
- Students can open the activity while it is active and answer the selected questions within the configured time.
- Reports aggregate submitted answers by tracking tag.
- Attempts use Moodle's question engine for rendering, response processing, and grading.
- Courses containing a Kopfübung receive a course-navigation link to a ten-row diagnostic overview.
- Students see their own correct, partially correct, incorrect, and unanswered results across all visible Kopfübungen.
- Teachers can label the ten recurring question positions by topic and inspect an enrolled participant's matrix.
