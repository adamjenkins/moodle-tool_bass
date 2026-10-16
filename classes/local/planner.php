<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_bass\local;

/**
 * Plans a change request for the selected activities.
 *
 * Per activity: read its current settings from its own edit form
 * ({@see module_form::current_post()}), replace the ticked settings, work out the grade to
 * pass from a percentage and the quiz maximum grade, read the form again as a browser would
 * post it with those values ({@see module_form::browser_post()}), compare old and new values,
 * and, when something changes, post the result to the activity's edit form to run the form's
 * own validation — so the preview reports exactly what Save on the edit page would refuse.
 * Nothing is written.
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class planner {
    /** @var float the largest grade the grade columns (NUMBER(10,5)) can hold. */
    public const GRADE_LIMIT = 99999.99999;

    /** @var int[] posted ids that were not eligible. */
    protected array $rejected = [];

    /**
     * Plan the request for the selected activities.
     *
     * @param int $courseid
     * @param int[] $cmids client-supplied course module ids (filtered here)
     * @param change_request $request
     * @param bulk_form $form the bulk form of the request's activity type
     * @return activity_plan[] cmid => plan, in course order
     */
    public function plan(int $courseid, array $cmids, change_request $request, bulk_form $form): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $eligible = activity_lister::get_eligible($courseid, $request->modname);
        $selected = activity_lister::filter_cmids($cmids, $eligible);
        $this->rejected = array_values(array_diff(array_unique(array_map('intval', $cmids)), $selected));

        $plans = [];
        foreach ($selected as $cmid) {
            $plans[$cmid] = $this->plan_activity($eligible[$cmid], $request, $form);
        }
        return $plans;
    }

    /**
     * The posted ids that were dropped because they are not eligible.
     *
     * @return int[]
     */
    public function get_rejected_cmids(): array {
        return $this->rejected;
    }

    /**
     * Plan one activity.
     *
     * @param \stdClass $entry an activity_lister entry
     * @param change_request $request
     * @param bulk_form $form
     * @return activity_plan
     */
    protected function plan_activity(\stdClass $entry, change_request $request, bulk_form $form): activity_plan {
        global $DB;
        $cm = $entry->cm;
        $plan = new activity_plan($cm);
        $settings = $form->get_settings();

        if ($entry->gradelocked) {
            // Saving through the module would print the gradebook's "regrade anyway" page.
            $plan->add_error(get_string('error_gradelocked', 'tool_bass'));
            return $plan;
        }

        try {
            $current = module_form::current_post($cm);
        } catch (\Throwable $e) {
            $plan->add_error(get_string('error_form', 'tool_bass', $e->getMessage()));
            return $plan;
        }

        $merged = $current;
        $currentvalues = [];
        foreach ($request->ticked as $name) {
            if ($name === bulk_form::MAXGRADE || !isset($settings[$name])) {
                continue;
            }
            $merged = post_values::overlay($merged, $settings[$name]['roots'], $request->values);
            $currentvalues[$name] = post_values::subset($current, $settings[$name]['roots']);
        }

        // Quiz maximum grade (not in the quiz form: applied with the grade calculator, see applier).
        $isquiz = $cm->modname === 'quiz';
        $oldmax = $isquiz ? (float) $DB->get_field('quiz', 'grade', ['id' => $cm->instance]) : null;
        $newmax = null;
        if ($isquiz && $request->is_ticked(bulk_form::MAXGRADE)) {
            $newmax = self::parse_grade($request->values[bulk_form::MAXGRADE] ?? '');
            if ($newmax === null || $newmax <= 0) {
                $plan->add_error(get_string('error_maxgrade', 'tool_bass'));
                return $plan;
            }
            $currentvalues[bulk_form::MAXGRADE] = $oldmax;
            if (abs($newmax - $oldmax) > 1e-7) {
                $plan->set_maxgrade($newmax);
                // The quiz form validates the grade to pass against its hidden grade field.
                $merged['grade'] = (string) $newmax;
                $plan->add_row(
                    bulk_form::MAXGRADE,
                    $settings[bulk_form::MAXGRADE]['label'],
                    format_float($oldmax, $entry->decimals, true, true),
                    format_float($newmax, $entry->decimals, true, true)
                );
                if ($DB->record_exists('quiz_attempts', ['quiz' => $cm->instance, 'preview' => 0])) {
                    $plan->add_warning(get_string('warning_rescale', 'tool_bass'));
                }
            }
        }

        // Grade to pass as a percentage of this activity's (new) maximum grade.
        if ($request->is_ticked('gradepass') && $request->gradepasstype === bulk_form::GRADEPASS_PERCENT) {
            $percent = self::parse_grade($request->values['gradepass'] ?? '');
            $max = $isquiz ? ($newmax ?? $oldmax) : $this->new_point_grade($request, $merged, $entry);
            if ($percent === null || $percent < 0 || $percent > 100) {
                $plan->add_error(get_string('error_percent', 'tool_bass'));
                return $plan;
            }
            if ($max === null || $max <= 0) {
                $plan->add_error(get_string('error_percentnograde', 'tool_bass'));
                return $plan;
            }
            $merged['gradepass'] = format_float(round($percent * $max / 100, 5), 5, true, true);
        }

        $completion = false;
        foreach ($request->ticked as $name) {
            if (!empty($settings[$name]['completion'])) {
                $completion = true;
            }
        }
        if ($completion) {
            // As the edit page's "Unlock completion settings": the module saves the completion
            // settings and recalculates everyone's completion of this activity.
            $merged['completionunlocked'] = 1;
        }

        // What the browser would post once the teacher put these values into the edit form: the
        // form's own show/hide and enable/disable rules decide which fields go with them.
        try {
            $merged = module_form::browser_post($cm, $merged);
        } catch (\Throwable $e) {
            $plan->add_error(get_string('error_form', 'tool_bass', $e->getMessage()));
            return $plan;
        }

        $completionchanged = false;
        foreach ($request->ticked as $name) {
            if ($name === bulk_form::MAXGRADE || !isset($settings[$name])) {
                continue;
            }
            $roots = $settings[$name]['roots'];
            $old = post_values::subset($current, $roots);
            $new = post_values::subset($merged, $roots);
            if (post_values::same($old, $new)) {
                continue;
            }
            $plan->add_row($name, $settings[$name]['label'], $form->display($name, $current), $form->display($name, $merged));
            $completionchanged = $completionchanged || !empty($settings[$name]['completion']);
        }
        $plan->set_current($currentvalues);
        $plan->set_post($merged);

        if (!$plan->has_changes()) {
            return $plan;
        }

        if ($completionchanged && (new \completion_info($cm->get_course()))->count_user_data($cm) > 0) {
            $plan->set_needs_confirmation(true);
            $plan->add_warning(get_string('warning_completionreset', 'tool_bass'));
        }

        // Run the edit form's own validation on exactly what would be saved.
        try {
            $validate = function (\moodleform_mod $modform, $data) use ($request, $settings): array {
                $errors = $data ? [] : module_form::errors($modform);
                $quickform = module_form::quickform($modform);
                foreach ($request->ticked as $name) {
                    if ($name === bulk_form::MAXGRADE) {
                        continue;
                    }
                    if (!$quickform->elementExists($name)) {
                        $errors[$name] = get_string('error_notinform', 'tool_bass');
                    } else if ($quickform->getElement($name)->isFrozen()) {
                        $errors[$name] = get_string('error_frozen', 'tool_bass');
                    }
                }
                $messages = [];
                foreach ($errors as $name => $message) {
                    $label = $settings[$name]['label'] ?? ($quickform->elementExists($name)
                        ? bulk_form::label_of($quickform->getElement($name)) : $name);
                    $messages[] = get_string('error_setting', 'tool_bass', (object) ['label' => $label, 'message' => $message]);
                }
                return $messages;
            };
            $errors = module_form::with_submission($cm, $merged, $validate);
        } catch (\Throwable $e) {
            $errors = [get_string('error_form', 'tool_bass', $e->getMessage())];
        }
        foreach ($errors as $message) {
            $plan->add_error($message);
        }
        return $plan;
    }

    /**
     * The new maximum grade of an activity's grade setting, when it is (or stays) a point grade.
     *
     * @param change_request $request
     * @param array $merged the POST data to save
     * @param \stdClass $entry an activity_lister entry
     * @return float|null null for a scale or no grade
     */
    protected function new_point_grade(change_request $request, array $merged, \stdClass $entry): ?float {
        if ($request->is_ticked('grade') && is_array($merged['grade'] ?? null)) {
            if (($merged['grade']['modgrade_type'] ?? '') !== 'point') {
                return null;
            }
            return self::parse_grade($merged['grade']['modgrade_point'] ?? '');
        }
        return $entry->grademax;
    }

    /**
     * Parse a typed grade or percentage (localised decimal separator allowed).
     *
     * @param mixed $value
     * @return float|null null when it is not a number within the grade columns' range
     */
    public static function parse_grade($value): ?float {
        if (!is_scalar($value)) {
            return null;
        }
        $number = unformat_float(trim((string) $value), true);
        if ($number === false || $number === null || !is_numeric($number)) {
            return null;
        }
        $number = (float) $number;
        return abs($number) <= self::GRADE_LIMIT ? $number : null;
    }

    /**
     * A fingerprint of a request and its plans: Apply changes is refused when it differs from the preview's.
     *
     * @param change_request $request
     * @param activity_plan[] $plans
     * @return string
     */
    public static function fingerprint(change_request $request, array $plans): string {
        $parts = [$request->describe()];
        foreach ($plans as $cmid => $plan) {
            $parts[] = [
                'cmid' => (int) $cmid,
                'current' => post_values::normalise($plan->get_current()),
                'rows' => $plan->get_rows(),
                'errors' => $plan->get_errors(),
                'confirm' => $plan->needs_confirmation(),
            ];
        }
        return sha1(json_encode($parts));
    }
}
