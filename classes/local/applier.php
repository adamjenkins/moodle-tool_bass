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

use mod_quiz\quiz_settings;

/**
 * Applies activity plans: each activity is saved through its own edit form, as on the edit page.
 *
 * Per activity, in its own database transaction (a failing activity is rolled back and
 * reported, the others carry on):
 * 1. quiz maximum grade changed: grade_calculator::update_quiz_maximum_grade(), which rescales
 *    the quiz's grades and feedback boundaries as the quiz's Questions page does;
 * 2. the plan's POST data (the activity's current settings with the ticked ones replaced) is
 *    posted to the activity's edit form; get_data() runs the form's validation, and
 *    update_moduleinfo() saves it exactly as course/modedit.php does — the module's
 *    update_instance(), grade items, completion (recalculated when the completion settings
 *    are unlocked), visibility, events and caches.
 * After all activities: rebuild_course_cache().
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class applier {
    /**
     * Apply plans for activities of one course.
     *
     * Plans with errors are skipped and plans without changes are left alone.
     *
     * @param int $courseid
     * @param activity_plan[] $plans from {@see planner::plan()} for the same course
     * @return apply_result[] cmid => result, in plan order
     */
    public function apply(int $courseid, array $plans): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');

        if ($DB->is_transaction_started()) {
            // A failing activity must not doom the others' writes through a shared outer transaction.
            throw new \coding_exception('The bulk activity settings applier must not run inside a database transaction.');
        }

        $results = [];
        $applied = false;
        foreach ($plans as $plan) {
            $cm = $plan->get_cm();
            $cmid = (int) $cm->id;
            $name = (string) $cm->name;
            if ((int) $cm->course !== $courseid) {
                throw new \coding_exception('Activity plan for course module ' . $cmid . ' is not in course ' . $courseid);
            }
            if ($plan->get_errors()) {
                $results[$cmid] = new apply_result($cmid, $name, apply_result::SKIPPED, $plan->get_errors());
                continue;
            }
            if (!$plan->has_changes()) {
                $results[$cmid] = new apply_result($cmid, $name, apply_result::NOCHANGE);
                continue;
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $this->apply_plan($plan);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                try {
                    $transaction->rollback($e);
                } catch (\Throwable $rethrown) {
                    // The rollback rethrows the original exception, which is reported below.
                    unset($rethrown);
                }
                $results[$cmid] = new apply_result($cmid, $name, apply_result::FAILED, [$e->getMessage()]);
                continue;
            }
            $applied = true;
            $results[$cmid] = new apply_result($cmid, $name, apply_result::APPLIED);
        }

        if ($applied) {
            rebuild_course_cache($courseid, true);
        }
        return $results;
    }

    /**
     * Save one activity (see the class docblock). Runs inside a transaction.
     *
     * @param activity_plan $plan
     */
    protected function apply_plan(activity_plan $plan): void {
        $cm = $plan->get_cm();
        $course = $cm->get_course();

        if ($plan->get_maxgrade() !== null) {
            quiz_settings::create((int) $cm->instance)->get_grade_calculator()
                ->update_quiz_maximum_grade($plan->get_maxgrade());
        }

        $save = function (\moodleform_mod $form, $data, \stdClass $cmrecord) use ($course): void {
            if (!$data) {
                $messages = [];
                foreach (module_form::errors($form) as $name => $message) {
                    $messages[] = $name . ': ' . $message;
                }
                throw new \moodle_exception('error_notsaved', 'tool_bass', '', implode(' ', $messages));
            }
            $data->frontend = true;
            update_moduleinfo($cmrecord, $data, $course, $form);
        };
        module_form::with_submission($cm, $plan->get_post(), $save);
    }
}
