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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../test_helper_trait.php');

/**
 * Saved configurations and admin presets.
 *
 * @package    tool_bass
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(saved_configs::class)]
#[CoversClass(\tool_bass\observer::class)]
final class saved_configs_test extends \advanced_testcase {
    use \tool_bass\test_helper_trait;

    /**
     * Save, list per type, load back into the form (dropping unknown settings), replace and delete.
     */
    public function test_save_and_load(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_bass_course();
        $a1 = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = $this->bass_cm((int) $a1->cmid);
        $this->set_bass_page($course, 'assign');
        $form = $this->bass_form(
            $cm,
            ['maxattempts' => '4', 'gradepass' => '60'],
            ['maxattempts', 'gradepass'],
            [bulk_form::GRADEPASSTYPE => bulk_form::GRADEPASS_PERCENT]
        );
        $request = change_request::from_form($form);
        $this->assertEqualsCanonicalizing(['gradepass', 'maxattempts'], $request->ticked);

        $id = saved_configs::save((int) $course->id, 'assign', 'Exams', saved_configs::snapshot($request, [(int) $cm->id]));
        $this->assertTrue(saved_configs::exists((int) $course->id, 'assign', 'Exams'));
        $this->assertCount(1, saved_configs::list((int) $course->id, 'assign'));
        $this->assertCount(0, saved_configs::list((int) $course->id, 'quiz'));
        $this->assertSame($id, saved_configs::save((int) $course->id, 'assign', 'Exams', saved_configs::snapshot($request)));

        // A stored setting the form does not offer (any more) is dropped on loading.
        $record = saved_configs::get((int) $course->id, $id);
        $data = json_decode($record->data, true);
        $data['ticked'][] = 'nosuchsetting';
        $data['values']['nosuchsetting'] = 'x';
        $data['cmids'] = [(int) $cm->id, 'junk'];
        $DB->set_field(saved_configs::TABLE, 'data', json_encode($data), ['id' => $id]);
        [$post, $cmids] = saved_configs::to_post(saved_configs::get((int) $course->id, $id), $form);
        $this->assertSame([(int) $cm->id], $cmids);
        $this->assertArrayNotHasKey('nosuchsetting', $post);
        $this->assertEquals(['maxattempts' => 1, 'gradepass' => 1], $post[bulk_form::CHANGE]);
        $this->assertSame('4', $post['maxattempts']);
        $this->assertSame(bulk_form::GRADEPASS_PERCENT, $post[bulk_form::GRADEPASSTYPE]);

        // Loading it into the bulk form gives the same request.
        $loaded = new bulk_form($cm, $this->bass_url($course, 'assign'), $post);
        $this->assertSame($request->describe(), change_request::from_form($loaded)->describe());

        $this->expectException(\dml_missing_record_exception::class);
        saved_configs::get(saved_configs::PRESETS, $id);
    }

    /**
     * A course's configurations are deleted with the course; presets stay.
     */
    public function test_course_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->create_bass_course();
        $snapshot = ['version' => saved_configs::VERSION, 'ticked' => [], 'values' => [], 'cmids' => []];
        saved_configs::save((int) $course->id, 'quiz', 'Mine', $snapshot);
        saved_configs::save(saved_configs::PRESETS, 'quiz', 'Site', $snapshot);
        // Synchronously: Moodle 5.3 deletes courses asynchronously by default.
        delete_course($course, false, false);
        $this->assertFalse($DB->record_exists(saved_configs::TABLE, ['courseid' => $course->id]));
        $this->assertTrue($DB->record_exists(saved_configs::TABLE, ['courseid' => saved_configs::PRESETS]));
    }
}
