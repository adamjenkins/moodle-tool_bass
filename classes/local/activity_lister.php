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
 * The activities of a course a user may bulk edit, by type, in course-page order.
 *
 * An activity is eligible when it is not being deleted, its type has a settings form and is
 * shown on the course page, and the user may edit its settings
 * (moodle/course:manageactivities in its context, as course/modedit.php requires).
 * Client-supplied course module ids must always be filtered through this list.
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_lister {
    /**
     * Whether an activity can be bulk edited by a user.
     *
     * @param \cm_info $cm
     * @param int $userid
     * @return bool
     */
    protected static function is_eligible(\cm_info $cm, int $userid): bool {
        if (!empty($cm->deletioninprogress)) {
            return false;
        }
        if (!plugin_supports('mod', $cm->modname, FEATURE_CAN_DISPLAY, true)) {
            return false;
        }
        if (module_form::form_class($cm->modname) === null) {
            return false;
        }
        return has_capability('moodle/course:manageactivities', $cm->context, $userid);
    }

    /**
     * The activity types of a course that have an activity the user may bulk edit.
     *
     * @param int $courseid
     * @param int|null $userid null = current user
     * @return array modname => ['name' => localised type name, 'count' => eligible activities], by name
     */
    public static function get_types(int $courseid, ?int $userid = null): array {
        global $USER;
        $userid = $userid ?? (int) $USER->id;
        $types = [];
        foreach (get_fast_modinfo($courseid, $userid)->get_cms() as $cm) {
            if (!self::is_eligible($cm, $userid)) {
                continue;
            }
            if (!isset($types[$cm->modname])) {
                $types[$cm->modname] = ['name' => get_string('modulename', $cm->modname), 'count' => 0];
            }
            $types[$cm->modname]['count']++;
        }
        \core_collator::asort_array_of_arrays_by_key($types, 'name');
        return $types;
    }

    /**
     * The activities of one type the user may bulk edit, in course-page order (subsection content inline).
     *
     * Each entry is an object with:
     * - cm: \cm_info
     * - section: string the section's name
     * - grademax: float|null the main grade item's maximum grade (null without a grade item or for a scale)
     * - gradepass: float|null the main grade item's grade to pass
     * - decimals: int the main grade item's decimals
     * - gradelocked: bool whether any of the activity's grade items is locked in the gradebook
     *
     * @param int $courseid
     * @param string $modname
     * @param int|null $userid null = current user
     * @return \stdClass[] cmid => entry
     */
    public static function get_eligible(int $courseid, string $modname, ?int $userid = null): array {
        global $CFG, $USER;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $userid = $userid ?? (int) $USER->id;
        $modinfo = get_fast_modinfo($courseid, $userid);
        $cms = [];
        foreach ($modinfo->get_instances_of($modname) as $cm) {
            if ((int) $cm->course === $courseid && self::is_eligible($cm, $userid)) {
                $cms[(int) $cm->id] = $cm;
            }
        }
        if (!$cms) {
            return [];
        }

        $items = [];
        foreach (\grade_item::fetch_all(['courseid' => $courseid, 'itemtype' => 'mod', 'itemmodule' => $modname]) ?: [] as $item) {
            $items[(int) $item->iteminstance][] = $item;
        }

        // Course-page order: get_cms() lists subsection content after every listed section,
        // sort_cm_array() puts it inline where the subsection sits.
        $modinfo->sort_cm_array($cms);

        $course = $modinfo->get_course();
        $result = [];
        foreach ($cms as $cmid => $cm) {
            $main = null;
            $locked = false;
            foreach ($items[(int) $cm->instance] ?? [] as $item) {
                $locked = $locked || $item->is_locked();
                if ((int) $item->itemnumber === 0 && empty($item->outcomeid)) {
                    $main = $item;
                }
            }
            $result[$cmid] = (object) [
                'cm' => $cm,
                'section' => get_section_name($course, $cm->sectionnum),
                'grademax' => $main && (int) $main->gradetype === GRADE_TYPE_VALUE ? (float) $main->grademax : null,
                'gradepass' => $main ? (float) $main->gradepass : null,
                'decimals' => $main ? (int) $main->get_decimals() : 2,
                'gradelocked' => $locked,
            ];
        }
        return $result;
    }

    /**
     * Keep only the course module ids that are eligible, dropping anything else.
     *
     * @param int[] $cmids client-supplied course module ids
     * @param array $eligible the result of {@see get_eligible()}
     * @return int[] eligible ids in course order, without duplicates
     */
    public static function filter_cmids(array $cmids, array $eligible): array {
        $wanted = array_flip(array_map('intval', $cmids));
        return array_values(array_filter(array_keys($eligible), fn($cmid) => isset($wanted[$cmid])));
    }
}
