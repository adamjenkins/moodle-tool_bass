# Changes

## v0.1.0

- New plugin: the Bulk Activity Settings Selector (`tool_bass`), which replaces
  Bulk edit quizzes (`tool_quizbulkedit`).
- Choose an activity type, and every standard setting of that type's settings
  form can be changed on some or all of the course's activities of that type,
  in the same order and sections as on the activity's Edit settings page.
- Each activity is saved through its own settings form, so its own rules,
  validation and save code apply, as on the edit page.
- Quizzes: Safe Exam Browser can be set in bulk, including "Yes – Use an
  existing template" with a site template.
- Grade to pass as a percentage of each activity's maximum grade works for every
  graded type; the quiz maximum grade can still be changed.
- Saved configurations and admin presets now belong to one activity type. Admin
  presets are saved from a course's page by a site administrator.
- The activity list is in course order and has the Filter by name box.

# Before the rename: tool_quizbulkedit

## v0.1.2

- The plugin's maturity is now Beta (was Alpha).
- composer.json now accepts any Moodle 5.x release from 5.2 on (`^5.2`), so
  later 5.x releases are no longer excluded.
- Automated testing now covers the released Moodle 5.3 (MOODLE_503_STABLE)
  instead of Moodle's development branch.
