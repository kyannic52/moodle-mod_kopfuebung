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

## Next Implementation Layer

This scaffold stores selected Moodle question IDs and displays the question text, but it does not yet run Moodle's full question engine for automatic grading, question behaviours, variants, or detailed response analysis. The next step is to replace the free-text answer capture in `attempt.php` with Moodle question engine usage.
