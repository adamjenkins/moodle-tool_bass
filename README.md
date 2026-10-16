# Bulk Activity Settings Selector (`tool_bass`)

A Moodle admin tool that changes the settings of some or all activities of one
type in a course in one go: pick an activity type, tick the activities, tick a
**Change** box next to each setting to change, preview, apply. Every standard
setting of the type's own settings form is offered, in the same order and under
the same sections as on the activity's Edit settings page, including what other
plugins add to that form (for quizzes: Safe Exam Browser, set to a site template
in one go).

`tool_bass` replaces `tool_quizbulkedit` (Bulk edit quizzes), which did the same
for quizzes only. It is a new plugin with its own component: it does not take
over the old plugin's saved configurations.

## Requirements

- Moodle 5.2 or later

## Installation

1. Copy the plugin directory into `<moodleroot>/public/admin/tool/bass/`
2. Visit Site administration → Notifications to run the upgrade

## How it works

From a course's administration menu (More → **Bulk Activity Settings Selector**),
choose an **Activity type**. The menu lists the types the course has activities
of that you may edit, with their number.

The page then lists the course's activities of that type in course-page order
(a subsection's activities inline, where the subsection sits), with their
section, maximum grade and grade to pass (for graded types) and completion
tracking. A **Filter by name** box shows only the activities whose name contains
the typed text (ignoring case); the select-all box then ticks or unticks the shown
activities only, and hidden activities keep their ticks.

Below the table is the type's own settings form, built from the first activity
of the type and starting from that activity's current settings. Every setting
has its own **Change** box and stays disabled until it is ticked. Only ticked
settings are changed; every other setting keeps each activity's own value.

**Preview** shows, for each selected activity, the settings that would change
with the current and the new value, and any reason an activity cannot be changed.
Nothing is saved until **Apply changes** is pressed. Changing a setting or the
selection after the preview disables Apply changes until the next preview, and
the server refuses an apply when the settings, the selection or the activities
changed since the preview.

### How an activity is changed

Each activity is changed through its own Edit settings form, exactly as if a
teacher had opened that activity's settings, changed the ticked settings and
pressed Save:

1. The activity's edit form is built with its current settings and read back
   the way a browser posts it.
2. The ticked settings are replaced by the requested values.
3. The result is posted to the activity's edit form, so the form's own rules and
   validation run (the preview reports exactly what Save would refuse), and is
   saved through Moodle's own module update (`update_moduleinfo()`): the
   module's own save code, grade items, completion, visibility, events and caches.

Each activity is saved in its own database transaction: an activity that fails
is rolled back and reported, and the others are still changed.

### What is offered

Every setting of the type's settings form, except per-activity content: the
name, the description, every text editor, file and date field, the ID number,
tags, access restrictions, competencies, outcomes and repeated fields (such as a
quiz's overall feedback). A setting is offered even when some activities lock
it (for example a quiz's Safe Exam Browser settings once it has attempts, or
settings your permissions do not allow): the preview lists each activity that
cannot take the change, says why, and skips it.

Extras:

- **Grade to pass as a percentage** of each activity's own maximum grade, for
  every graded type ("Grade to pass entered as").
- **Quiz maximum grade** (the quiz settings form has none): existing grades and
  overall feedback boundaries are rescaled, as on the quiz's Questions page.

What happens when a setting changes is what happens on the edit page, for
example:

- **Completion**: changing the completion settings of an activity that students
  have completion data for deletes and recalculates that data; Apply changes
  then needs a confirmation.
- **Quiz**: saving a quiz deletes the teachers' preview attempts, as the edit
  page does.
- An activity whose grade item is **locked** in the gradebook is skipped (unlock
  it first).

## Saved configurations and admin presets

- **Save configuration** (below the settings) stores the ticked settings, their
  values and the ticked activities under a name, for the course and the activity
  type. **Load** puts them back into the form; **Delete** removes one after a
  confirmation. Saving and loading change no activity: a loaded configuration
  still goes through Preview and Apply changes.
- **Admin presets**: a site administrator can tick *Save as an admin preset for
  the whole site* when saving; every course's page then lists the presets of
  that type under *Admin presets* with a **Load** button (presets hold settings
  only). *Site administration > Plugins > Admin tools > Bulk activity settings
  presets* lists and deletes them.
- A course's configurations are deleted with the course.

## Permissions

The page needs `tool/bass:manage` in the course (editing teachers and managers
by default; `RISK_DATALOSS | RISK_CONFIG`, because it changes settings in bulk,
rescales grades and resets completion). Only activities on which the user also
has `moodle/course:manageactivities` (what the edit page requires) are listed and
changed.

## Privacy

The plugin stores no personal data (saved configurations hold settings and
activity ids, with no user reference) and implements Moodle's privacy
`null_provider`.

## License

GNU GPL v3 or later — https://www.gnu.org/licenses/gpl-3.0.html
