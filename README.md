# Kopfuebung Moodle Plugin

`mod_kopfuebung` is a Moodle activity module for timed, teacher-started question practice with diagnostic course overviews, self-reflection, feedback, and targeted learning offers.

## Install

Place this folder in a Moodle installation at:

```text
mod/kopfuebung
```

Then visit **Site administration > Notifications** or run Moodle's CLI upgrade command.

## User Guide

For a visual introduction to using the plugin and its typical workflow, see the
[Kopfuebung plugin presentation](Kopfuebung_Plugin_Praesentation.pdf).

## HUE Exchange Format

HUE (Hausuebung Exchange Format) is the package format planned for exchanging
complete Kopfuebung activities. The version 1.0 specification and authoring
guidance are available in:

- [HUE format specification](docs/hue-format.md)
- [HUE authoring guide](docs/hue-authoring.md)

An unpacked reference package is provided in `examples/hue/minimal/`, and the
manifest can be validated against `schema/hue-manifest.schema.json`.

Teachers can import and export HUE packages from an exercise activity. Import
provides question previews, duplicate handling, destination-category selection,
and drag-and-drop ordering before applying the questions and activity settings.
Activities that already contain attempts remain exportable but cannot be
replaced by a HUE import, which protects existing attempt and reflection data.

## Typical Teacher Workflow

### 1. Prepare questions

Create the required questions in the Moodle question bank first. The plugin uses Moodle's question engine, so question rendering, response handling, and grading follow the behaviour of the selected question types.

### 2. Create a Kopfuebung

Add a **Kopfuebung** activity to the course and configure:

- the activity name and description;
- a time limit;
- 8, 9, or 10 question positions;
- optional post-submission self-assessment;
- optional difficulty ratings from 1 (much too easy) to 5 (much too difficult).

Save the activity and open **Manage questions**. Assign one question-bank question to every configured position. Assignments can be made from the quick-selection lists or through the detailed question picker. The page offers separate actions for saving in place or saving and returning to the activity.

If a teacher tries to start an activity with unassigned positions, the activity remains stopped and displays options to adjust the question count, assign the missing questions, or cancel.

### 3. Prepare the diagnostic overview

The course navigation contains a **Kopfuebung overview** as soon as the course has a visible Kopfuebung activity. Teachers can:

- label recurring rows by topic or competency;
- create a new label grid when later activities use a different set of topics;
- add a dedicated overview activity to the course page;
- configure additional learning offers for individual grid rows.

An additional offer can contain a hint and links to explanatory material or further practice. It may be shown automatically after a configured number of incorrect results or assigned directly to selected students.

### 4. Run the activity

Before the activity starts, students can report that they are ready. The activity page shows the teacher how many students are ready.

Select **Start activity** when the class should begin. Ready students are directed to the timed attempt. Students may save intermediate answers and submit manually. At the end of the time limit, the visible response state is submitted, open attempts are finalised, and the activity is closed automatically. A teacher may also stop the activity manually.

Teachers can reset an individual attempt when a student needs a completely new attempt.

### 5. Student self-reflection

When enabled, submission is followed by a read-only copy of the attempt. For each question, students indicate whether they believe their answer is correct. If difficulty ratings are enabled, they also evaluate how appropriate the question difficulty was.

The reflection step never allows submitted answers to be changed. Once the activity is closed, students can open the full review with grades, feedback, correct answers where supported, and an indicator showing whether each self-assessment matched the graded result.

## Analysing Results

### Individual student overview

Select a participant in the Kopfuebung overview to inspect results across all activities. Each question position shows the student's result and a class comparison. Completed cells link to the submitted attempt.

Below each activity, the overview shows:

- the total score;
- the percentage and number of answers the student expected to be correct;
- the percentage and number of self-assessments that matched the graded results.

Values that were not enabled or are unavailable are displayed as `n/a`.

### Whole-class overview

The whole-class view shows the percentage of participants who answered each position correctly. Where reflection is enabled, each cell also contains the percentage of accurate self-assessments and the average difficulty rating. Grid boundaries and additional-offer columns visually separate different diagnostic sections.

Open the information link in a result cell to see the participant-level detail for one question. This table compares the graded result, the student's own assessment, and the reported difficulty. From there, teachers can open the complete reviewed attempt.

### Feedback and follow-up

Below the overview, teachers can publish feedback for the whole course or start a private feedback conversation with one student. Students can reply to personal feedback. Moodle notifications inform the relevant participants about new messages.

Use additional learning offers to connect diagnostic results with appropriate explanations and practice activities.

## Main Capabilities

- Timed attempts with teacher-controlled and automatic completion.
- Moodle question-bank and question-engine integration.
- Configurable sets of 8, 9, or 10 questions.
- Optional answer self-assessment and difficulty ratings.
- Individual and whole-class diagnostic matrices with reusable topic grids.
- Detailed question-level and attempt-level review pages.
- Course-wide and private feedback conversations.
- Rule-based and individually assigned additional learning offers.
- Readiness reporting and individual attempt resets.
