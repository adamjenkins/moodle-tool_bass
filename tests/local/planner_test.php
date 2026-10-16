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
use quizaccess_seb\seb_quiz_settings;
use quizaccess_seb\settings_provider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../test_helper_trait.php');

/**
 * Planning and applying change requests: the bulk form, the planner and the applier together.
 *
 * @package    tool_bass
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(bulk_form::class)]
#[CoversClass(change_request::class)]
#[CoversClass(planner::class)]
#[CoversClass(applier::class)]
#[CoversClass(activity_plan::class)]
#[CoversClass(post_values::class)]
final class planner_test extends \advanced_testcase {
    use \tool_bass\test_helper_trait;

    /**
     * The applier refuses an outer transaction, which PHPUnit opens on PostgreSQL.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
    }

    /**
     * Plan a request for some activities.
     *
     * @param bulk_form $form
     * @param \cm_info[] $cms
     * @return activity_plan[]
     */
    private function plan(bulk_form $form, array $cms): array {
        $course = $form->get_representative()->get_course();
        return (new planner())->plan(
            (int) $course->id,
            array_map(fn($cm) => (int) $cm->id, $cms),
            change_request::from_form($form),
            $form
        );
    }

    /**
     * Apply plans.
     *
     * @param activity_plan[] $plans
     * @return apply_result[]
     */
    private function apply(array $plans): array {
        $course = reset($plans)->get_cm()->get_course();
        return (new applier())->apply((int) $course->id, $plans);
    }

    /**
     * The bulk form offers the type's own settings in its own order and leaves out per-activity content.
     */
    public function test_form_offers_the_settings_of_the_edit_page(): void {
        $course = $this->create_bass_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $this->set_bass_page($course, 'quiz');
        $form = new bulk_form($this->bass_cm((int) $quiz->cmid), $this->bass_url($course, 'quiz'), null);
        $names = array_keys($form->get_settings());

        foreach (
            ['timelimit', 'attempts', 'grademethod', 'shuffleanswers', 'seb_requiresafeexambrowser', 'visible', 'groupmode',
                'gradepass', bulk_form::MAXGRADE, 'completion'] as $expected
        ) {
            $this->assertContains($expected, $names);
        }
        foreach (
            ['name', 'introeditor', 'timeopen', 'timeclose', 'cmidnumber', 'tags', 'filemanager_sebconfigfile',
                'availabilityconditionsjson'] as $excluded
        ) {
            $this->assertNotContains($excluded, $names);
        }
        // The order of the quiz form: timing before grade, grade before the review options, SEB before Common module settings.
        $this->assertLessThan(array_search('gradepass', $names), array_search('timelimit', $names));
        $this->assertLessThan(array_search('gradepass', $names), array_search(bulk_form::MAXGRADE, $names));
        $this->assertLessThan(array_search('duringoptionsgrp', $names), array_search('attempts', $names));
        $this->assertLessThan(array_search('visible', $names), array_search('seb_requiresafeexambrowser', $names));
        $this->assertTrue($form->get_settings()['completion']['completion']);
        $this->assertFalse($form->get_settings()['attempts']['completion']);

        $html = $form->render();
        $this->assertStringContainsString('name="' . bulk_form::CHANGE . '[timelimit]"', $html);
        $this->assertStringNotContainsString('name="timeopen[', $html);
        $this->assertStringNotContainsString('name="name"', $html);
    }

    /**
     * Safe Exam Browser set to "use an existing template" on several quizzes.
     */
    public function test_seb_template_on_several_quizzes(): void {
        $course = $this->create_bass_course();
        $template = $this->getDataGenerator()->get_plugin_generator('quizaccess_seb')->create_template(['name' => 'Exam room']);
        $quizzes = [];
        foreach ([10, 20, 15] as $grade) {
            $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => $grade]);
            $quizzes[] = $this->bass_cm((int) $quiz->cmid);
        }
        $this->set_bass_page($course, 'quiz');
        $form = $this->bass_form($quizzes[0], [
            'seb_requiresafeexambrowser' => settings_provider::USE_SEB_TEMPLATE,
            'seb_templateid' => $template->get('id'),
        ], ['seb_requiresafeexambrowser', 'seb_templateid']);

        $plans = $this->plan($form, $quizzes);
        $label = $form->get_settings()['seb_requiresafeexambrowser']['label'];
        foreach ($plans as $plan) {
            $this->assertSame([], $plan->get_errors());
            $this->assertTrue($plan->can_apply());
            $this->assertContains($label, array_column($plan->get_rows(), 'label'));
        }
        $results = $this->apply($plans);

        foreach ($quizzes as $cm) {
            $this->assertSame(apply_result::APPLIED, $results[$cm->id]->status, implode(' ', $results[$cm->id]->messages));
            $settings = seb_quiz_settings::get_record(['quizid' => $cm->instance]);
            $this->assertNotFalse($settings);
            $this->assertEquals(settings_provider::USE_SEB_TEMPLATE, $settings->get('requiresafeexambrowser'));
            $this->assertEquals($template->get('id'), $settings->get('templateid'));
            // The quiz now demands Safe Exam Browser.
            $quizobj = \mod_quiz\quiz_settings::create((int) $cm->instance);
            $this->assertContains('quizaccess_seb', $quizobj->get_access_manager(time())->get_active_rule_names());
        }
        // The grades were left alone.
        $this->assertEquals([10, 20, 15], array_map(fn($cm) => (float) $this->bass_grade_item($cm)->grademax, $quizzes));
    }

    /**
     * Grade to pass as a percentage of each assignment's own maximum grade; unticked settings keep each activity's value.
     */
    public function test_gradepass_percent_and_unticked_settings(): void {
        global $DB;
        $course = $this->create_bass_course();
        $a1 = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100, 'maxattempts' => 2]);
        $a2 = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 20, 'maxattempts' => 5]);
        $cms = [$this->bass_cm((int) $a1->cmid), $this->bass_cm((int) $a2->cmid)];
        $this->set_bass_page($course, 'assign');
        $form = $this->bass_form(
            $cms[0],
            ['gradepass' => '60'],
            ['gradepass'],
            [bulk_form::GRADEPASSTYPE => bulk_form::GRADEPASS_PERCENT]
        );

        $results = $this->apply($this->plan($form, $cms));

        $this->assertSame(apply_result::APPLIED, $results[$cms[0]->id]->status, implode(' ', $results[$cms[0]->id]->messages));
        $this->assertSame(apply_result::APPLIED, $results[$cms[1]->id]->status, implode(' ', $results[$cms[1]->id]->messages));
        $this->assertEquals(60.0, (float) $this->bass_grade_item($cms[0])->gradepass);
        $this->assertEquals(12.0, (float) $this->bass_grade_item($cms[1])->gradepass);
        $this->assertEquals(2, $DB->get_field('assign', 'maxattempts', ['id' => $a1->id]));
        $this->assertEquals(5, $DB->get_field('assign', 'maxattempts', ['id' => $a2->id]));
    }

    /**
     * Quiz maximum grade and a percentage grade to pass of the new maximum.
     */
    public function test_quiz_maxgrade_and_gradepass_percent(): void {
        global $DB;
        $course = $this->create_bass_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 10]);
        $cm = $this->bass_cm((int) $quiz->cmid);
        $this->set_bass_page($course, 'quiz');
        $form = $this->bass_form(
            $cm,
            [bulk_form::MAXGRADE => '40', 'gradepass' => '50'],
            [bulk_form::MAXGRADE, 'gradepass'],
            [bulk_form::GRADEPASSTYPE => bulk_form::GRADEPASS_PERCENT]
        );

        $plans = $this->plan($form, [$cm]);
        $this->assertSame([], $plans[$cm->id]->get_errors());
        $results = $this->apply($plans);

        $this->assertSame(apply_result::APPLIED, $results[$cm->id]->status, implode(' ', $results[$cm->id]->messages));
        $this->assertEquals(40.0, (float) $DB->get_field('quiz', 'grade', ['id' => $quiz->id]));
        $this->assertEquals(40.0, (float) $this->bass_grade_item($cm)->grademax);
        $this->assertEquals(20.0, (float) $this->bass_grade_item($cm)->gradepass);
    }

    /**
     * The activity's own validation refuses a grade to pass above its maximum grade; nothing is written.
     */
    public function test_form_validation_refuses_per_activity(): void {
        $course = $this->create_bass_course();
        $a1 = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $a2 = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 20]);
        $cms = [$this->bass_cm((int) $a1->cmid), $this->bass_cm((int) $a2->cmid)];
        $this->set_bass_page($course, 'assign');
        $form = $this->bass_form($cms[0], ['gradepass' => '50'], ['gradepass']);

        $plans = $this->plan($form, $cms);
        $this->assertSame([], $plans[$cms[0]->id]->get_errors());
        $this->assertCount(1, $plans[$cms[1]->id]->get_errors());
        $this->assertStringContainsString(get_string('gradepass', 'grades'), $plans[$cms[1]->id]->get_errors()[0]);

        $results = $this->apply($plans);
        $this->assertSame(apply_result::APPLIED, $results[$cms[0]->id]->status);
        $this->assertSame(apply_result::SKIPPED, $results[$cms[1]->id]->status);
        $this->assertEquals(50.0, (float) $this->bass_grade_item($cms[0])->gradepass);
        $this->assertEquals(0.0, (float) $this->bass_grade_item($cms[1])->gradepass);
    }

    /**
     * An activity whose grade item is locked is skipped entirely.
     */
    public function test_locked_grade_item_is_skipped(): void {
        global $DB;
        $course = $this->create_bass_course();
        $a1 = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'maxattempts' => 2]);
        $cm = $this->bass_cm((int) $a1->cmid);
        $item = $this->bass_grade_item($cm);
        $item->set_locked(1);
        $this->set_bass_page($course, 'assign');
        $form = $this->bass_form($cm, ['maxattempts' => '4'], ['maxattempts']);

        $plans = $this->plan($form, [$cm]);
        $this->assertSame([get_string('error_gradelocked', 'tool_bass')], $plans[$cm->id]->get_errors());
        $results = $this->apply($plans);
        $this->assertSame(apply_result::SKIPPED, $results[$cm->id]->status);
        $this->assertEquals(2, $DB->get_field('assign', 'maxattempts', ['id' => $a1->id]));
    }

    /**
     * Ticked settings that already have the requested value: no change, nothing saved.
     */
    public function test_no_change(): void {
        global $DB;
        $course = $this->create_bass_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'printintro' => 1]);
        $cm = $this->bass_cm((int) $page->cmid);
        $revision = $DB->get_field('page', 'revision', ['id' => $page->id]);
        $this->set_bass_page($course, 'page');
        $form = $this->bass_form($cm, ['printintro' => '1'], ['printintro']);

        $plans = $this->plan($form, [$cm]);
        $this->assertFalse($plans[$cm->id]->has_changes());
        $results = $this->apply($plans);
        $this->assertSame(apply_result::NOCHANGE, $results[$cm->id]->status);
        $this->assertEquals($revision, $DB->get_field('page', 'revision', ['id' => $page->id]));
    }

    /**
     * Completion changes on an activity with completion data need a confirmation and recalculate completion.
     */
    public function test_completion_change_with_completion_data(): void {
        global $DB;
        $course = $this->create_bass_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $cm = $this->bass_cm((int) $page->cmid);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $this->assertEquals(1, $completion->count_user_data($cm));
        $this->set_bass_page($course, 'page');
        $form = $this->bass_form($cm, ['completion' => (string) COMPLETION_TRACKING_NONE], ['completion']);

        $plans = $this->plan($form, [$cm]);
        $this->assertSame([], $plans[$cm->id]->get_errors());
        $this->assertTrue($plans[$cm->id]->needs_confirmation());
        $results = $this->apply($plans);

        $this->assertSame(apply_result::APPLIED, $results[$cm->id]->status, implode(' ', $results[$cm->id]->messages));
        $this->assertEquals(COMPLETION_TRACKING_NONE, $DB->get_field('course_modules', 'completion', ['id' => $cm->id]));
        $this->assertFalse($DB->record_exists('course_modules_completion', ['coursemoduleid' => $cm->id]));
    }

    /**
     * The fingerprint changes when an activity changes after the preview.
     */
    public function test_fingerprint_follows_the_activities(): void {
        global $DB;
        $course = $this->create_bass_course();
        $a1 = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'maxattempts' => 2]);
        $cm = $this->bass_cm((int) $a1->cmid);
        $this->set_bass_page($course, 'assign');
        $form = $this->bass_form($cm, ['maxattempts' => '4'], ['maxattempts']);
        $request = change_request::from_form($form);

        $first = planner::fingerprint($request, $this->plan($form, [$cm]));
        $this->assertSame($first, planner::fingerprint($request, $this->plan($form, [$cm])));
        $DB->set_field('assign', 'maxattempts', 3, ['id' => $a1->id]);
        $this->assertNotSame($first, planner::fingerprint($request, $this->plan($form, [$this->bass_cm((int) $a1->cmid)])));
    }

    /**
     * Posted ids of activities of another type, another course or not editable are dropped.
     */
    public function test_ineligible_ids_are_rejected(): void {
        $course = $this->create_bass_course();
        $other = $this->create_bass_course();
        $a1 = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $foreign = $this->getDataGenerator()->create_module('assign', ['course' => $other->id]);
        $cm = $this->bass_cm((int) $a1->cmid);
        $this->set_bass_page($course, 'assign');
        $form = $this->bass_form($cm, ['maxattempts' => '4'], ['maxattempts']);

        $planner = new planner();
        $plans = $planner->plan(
            (int) $course->id,
            [(int) $a1->cmid, (int) $page->cmid, (int) $foreign->cmid],
            change_request::from_form($form),
            $form
        );
        $this->assertSame([(int) $a1->cmid], array_keys($plans));
        $this->assertEqualsCanonicalizing([(int) $page->cmid, (int) $foreign->cmid], $planner->get_rejected_cmids());
    }
}
