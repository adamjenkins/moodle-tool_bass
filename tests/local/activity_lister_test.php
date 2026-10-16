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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The activities a user may bulk edit: types, course order, capabilities.
 *
 * @package    tool_bass
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(activity_lister::class)]
final class activity_lister_test extends \advanced_testcase {
    /**
     * Types with counts; activities in course-page order with subsection content inline; capability filtering.
     */
    public function test_types_order_and_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 3, 'summary' => '']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        // Created out of order: section 3 first, then section 1, then a subsection in section 1.
        $late = $generator->create_module('assign', ['course' => $course->id, 'section' => 3, 'name' => 'Late']);
        $early = $generator->create_module('assign', ['course' => $course->id, 'section' => 1, 'name' => 'Early']);
        $sub = $generator->create_module('subsection', ['course' => $course->id, 'section' => 1, 'name' => 'Sub']);
        $subsection = $DB->get_record('course_sections', ['component' => 'mod_subsection', 'itemid' => $sub->id], '*', MUST_EXIST);
        $inner = $generator->create_module('assign', ['course' => $course->id, 'section' => $subsection->section,
            'name' => 'Inner']);
        $locked = $generator->create_module('assign', ['course' => $course->id, 'section' => 2, 'name' => 'Not mine']);
        $generator->create_module('page', ['course' => $course->id, 'section' => 2]);

        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability(
            'moodle/course:manageactivities',
            CAP_PROHIBIT,
            $role,
            \context_module::instance($locked->cmid),
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $types = activity_lister::get_types((int) $course->id, (int) $teacher->id);
        $this->assertSame(3, $types['assign']['count']);
        $this->assertSame(1, $types['page']['count']);
        $this->assertSame(get_string('modulename', 'assign'), $types['assign']['name']);

        $eligible = activity_lister::get_eligible((int) $course->id, 'assign', (int) $teacher->id);
        $this->assertSame([(int) $early->cmid, (int) $inner->cmid, (int) $late->cmid], array_keys($eligible));
        $this->assertSame(100.0, $eligible[$early->cmid]->grademax);
        $this->assertFalse($eligible[$early->cmid]->gradelocked);

        $this->assertSame([(int) $early->cmid, (int) $late->cmid], activity_lister::filter_cmids(
            [(int) $late->cmid, (int) $locked->cmid, (int) $early->cmid, (int) $early->cmid, 999999],
            $eligible
        ));
    }
}
