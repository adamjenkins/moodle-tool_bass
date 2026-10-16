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

namespace tool_bass;

use tool_bass\local\bulk_form;

/**
 * Shared helpers for the tool_bass PHPUnit tests.
 *
 * @package    tool_bass
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait test_helper_trait {
    /**
     * A course with completion enabled and an empty description.
     *
     * @param array $record extra course fields
     * @return \stdClass
     */
    protected function create_bass_course(array $record = []): \stdClass {
        return $this->getDataGenerator()->create_course($record + ['enablecompletion' => 1, 'summary' => '']);
    }

    /**
     * Set up $PAGE (and $COURSE) as the bulk page does after require_login().
     *
     * @param \stdClass $course
     * @param string $modname
     */
    protected function set_bass_page(\stdClass $course, string $modname = ''): void {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_course($course);
        $PAGE->set_url($this->bass_url($course, $modname));
        $PAGE->set_pagelayout('admin');
    }

    /**
     * The bulk page URL.
     *
     * @param \stdClass $course
     * @param string $modname
     * @return \moodle_url
     */
    protected function bass_url(\stdClass $course, string $modname = ''): \moodle_url {
        $params = ['courseid' => $course->id];
        if ($modname !== '') {
            $params['modname'] = $modname;
        }
        return new \moodle_url('/admin/tool/bass/index.php', $params);
    }

    /**
     * The activity's cm_info, freshly loaded.
     *
     * @param int $cmid
     * @return \cm_info
     */
    protected function bass_cm(int $cmid): \cm_info {
        [, $cm] = get_course_and_cm_from_cmid($cmid);
        return $cm;
    }

    /**
     * A bulk form with some settings ticked and given values, as if posted.
     *
     * @param \cm_info $representative
     * @param array $values POST values of the ticked settings' roots
     * @param string[] $ticked the ticked setting names
     * @param array $extra more POST values (e.g. the grade to pass type)
     * @return bulk_form
     */
    protected function bass_form(\cm_info $representative, array $values, array $ticked, array $extra = []): bulk_form {
        $post = $values + $extra + [
            bulk_form::CHANGE => array_fill_keys($ticked, 1),
            bulk_form::MARKER => 1,
        ];
        return new bulk_form($representative, $this->bass_url($representative->get_course(), $representative->modname), $post);
    }

    /**
     * The main grade item of an activity.
     *
     * @param \cm_info $cm
     * @return \grade_item
     */
    protected function bass_grade_item(\cm_info $cm): \grade_item {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        return \grade_item::fetch([
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'itemnumber' => 0,
        ]);
    }
}
