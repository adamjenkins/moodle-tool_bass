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
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/../test_helper_trait.php');

/**
 * The central guarantee: saving an activity through its own form with nothing changed changes nothing.
 *
 * For every core activity type that can be created without an uploaded package or an
 * external server, the activity's current settings are read from its edit form and saved back
 * through the applier, and its stored settings are compared before and after. "Unchanged"
 * means the same values (design spec, 2026-10-16): ids, timemodified, revision counters,
 * events and logs may change, as they do when Save is pressed on the edit page.
 *
 * @package    tool_bass
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(module_form::class)]
#[CoversClass(applier::class)]
final class roundtrip_test extends \advanced_testcase {
    use \tool_bass\test_helper_trait;

    /** @var string[] instance columns that a save on the edit page changes by design. */
    private const VOLATILE = ['timemodified', 'revision'];

    /**
     * The core activity types covered, with settings that differ from the defaults.
     *
     * @return array[]
     */
    public static function module_provider(): array {
        return [
            // The generator stores a few values the edit form would store differently (it never saved
            // through the form); they are set here as the form stores them.
            // "Always show description" is disabled on the edit page without an "Allow submissions from" date.
            'assign' => ['assign', ['grade' => 37, 'maxattempts' => 3, 'sendnotifications' => 1, 'markercount' => 1,
                'activity' => '', 'activityformat' => FORMAT_HTML, 'assignsubmission_comments_enabled' => 1,
                'allowsubmissionsfromdate' => 1893456000, 'alwaysshowdescription' => 1]],
            'book' => ['book', ['numbering' => 2]],
            'choice' => ['choice', ['allowupdate' => 1, 'option' => ['A', 'B']]],
            'data' => ['data', ['approval' => 1]],
            'feedback' => ['feedback', ['anonymous' => 2]],
            'folder' => ['folder', ['display' => 1]],
            'forum' => ['forum', ['forcesubscribe' => 1, 'maxattachments' => 3]],
            'glossary' => ['glossary', ['displayformat' => 'fullwithauthor']],
            'label' => ['label', ['intro' => '<p>A <strong>label</strong></p>']],
            'lesson' => ['lesson', ['grade' => 50, 'retake' => 1]],
            'page' => ['page', ['display' => 5, 'printintro' => 1]],
            // The "later, while open" review options need a close date, and the grace period needs
            // "When time expires" = grace period, or the edit page disables (and so clears) them.
            'quiz' => ['quiz', ['grade' => 20, 'attempts' => 3, 'timelimit' => 1800, 'preferredbehaviour' => 'immediatefeedback',
                'browsersecurity' => '-', 'timeclose' => 1893456000, 'overduehandling' => 'graceperiod', 'graceperiod' => 3600]],
            'resource' => ['resource', ['display' => 0]],
            'url' => ['url', ['externalurl' => 'https://example.com/a?b=1&c=2', 'display' => 1]],
            'wiki' => ['wiki', ['wikimode' => 'individual']],
            'workshop' => ['workshop', ['grade' => 60, 'gradinggrade' => 30]],
        ];
    }

    /**
     * Save an activity with nothing ticked and compare its stored settings.
     *
     * @param string $modname
     * @param array $settings
     */
    #[DataProvider('module_provider')]
    public function test_save_with_nothing_changed_keeps_every_setting(string $modname, array $settings): void {
        global $DB;
        $this->resetAfterTest();
        // The applier refuses an outer transaction, which PHPUnit opens on PostgreSQL.
        $this->preventResetByRollback();
        $this->setAdminUser();

        $course = $this->create_bass_course();
        $record = $this->getDataGenerator()->create_module($modname, $settings + [
            'course' => $course->id,
            'intro' => '<p>An <strong>intro</strong> &amp; more</p>',
            'introformat' => FORMAT_HTML,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'groupmode' => SEPARATEGROUPS,
        ]);
        if ($modname === 'quiz') {
            // Overall feedback is deleted and inserted again on every save of a quiz.
            $DB->insert_record('quiz_feedback', (object) ['quizid' => $record->id, 'feedbacktext' => '<p>Well done</p>',
                'feedbacktextformat' => FORMAT_HTML, 'mingrade' => 10, 'maxgrade' => 21]);
            $DB->insert_record('quiz_feedback', (object) ['quizid' => $record->id, 'feedbacktext' => '<p>Try again</p>',
                'feedbacktextformat' => FORMAT_HTML, 'mingrade' => 0, 'maxgrade' => 10]);
        }
        $cm = $this->bass_cm((int) $record->cmid);
        $this->set_bass_page($course, $modname);

        $before = $this->snapshot($cm);
        $post = module_form::current_post($cm);
        $this->assertNotEmpty($post, 'the edit form was read');

        $plan = new activity_plan($cm);
        $plan->add_row('test', 'test', 'a', 'b');
        $plan->set_post($post);
        $results = (new applier())->apply((int) $course->id, [(int) $cm->id => $plan]);

        $this->assertSame(apply_result::APPLIED, $results[$cm->id]->status, implode(' ', $results[$cm->id]->messages));
        $this->assertEquals($before, $this->snapshot($this->bass_cm((int) $cm->id)));
    }

    /**
     * The stored settings of an activity.
     *
     * @param \cm_info $cm
     * @return array
     */
    private function snapshot(\cm_info $cm): array {
        global $DB;
        $instance = (array) $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);
        $cmrecord = (array) $DB->get_record('course_modules', ['id' => $cm->id], '*', MUST_EXIST);
        // No forced language: null from the generator, '' from the form.
        $cmrecord['lang'] = (string) $cmrecord['lang'];
        if (isset($instance['displayoptions'])) {
            // Serialised display options: the generator stores numbers, the form strings.
            $options = unserialize((string) $instance['displayoptions'], ['allowed_classes' => false]);
            $instance['displayoptions'] = is_array($options) ? array_map('strval', $options) : $instance['displayoptions'];
        }
        if (array_key_exists('activity', $instance) && (string) $instance['activity'] === '') {
            // An empty assignment "Activity instructions" editor: null from the generator, '' from the form,
            // and the format of no text does not matter.
            $instance['activity'] = '';
            unset($instance['activityformat']);
        }
        $snapshot = [
            'instance' => array_diff_key($instance, array_flip(self::VOLATILE)),
            'cm' => $cmrecord,
            'gradeitems' => [],
            'files' => [],
            'extra' => [],
        ];
        foreach (
            $DB->get_records('grade_items', ['itemtype' => 'mod', 'itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance, 'courseid' => $cm->course], 'itemnumber') as $item
        ) {
            $snapshot['gradeitems'][] = array_intersect_key((array) $item, array_flip([
                'categoryid', 'itemname', 'itemnumber', 'gradetype', 'grademax', 'grademin', 'scaleid', 'gradepass',
                'hidden', 'locked', 'idnumber', 'decimals', 'display',
            ]));
        }
        $context = \context_module::instance($cm->id);
        foreach (
            get_file_storage()->get_area_files(
                $context->id,
                false,
                false,
                false,
                'filearea, itemid, filepath, filename',
                false
            ) as $file
        ) {
            $snapshot['files'][] = [$file->get_component(), $file->get_filearea(), $file->get_itemid(),
                $file->get_filepath(), $file->get_filename(), $file->get_contenthash()];
        }
        if ($cm->modname === 'quiz') {
            foreach ($DB->get_records('quiz_feedback', ['quizid' => $cm->instance], 'mingrade') as $row) {
                $snapshot['extra']['feedback'][] = [$row->feedbacktext, $row->feedbacktextformat, (float) $row->mingrade,
                    (float) $row->maxgrade];
            }
            $seb = $DB->get_record('quizaccess_seb_quizsettings', ['quizid' => $cm->instance]);
            $volatile = array_flip(['id', 'timemodified', 'usermodified']);
            $snapshot['extra']['seb'] = $seb ? array_diff_key((array) $seb, $volatile) : null;
        }
        if ($cm->modname === 'assign') {
            foreach ($DB->get_records('assign_plugin_config', ['assignment' => $cm->instance], 'plugin, subtype, name') as $row) {
                $snapshot['extra']['plugins'][] = [$row->plugin, $row->subtype, $row->name, $row->value];
            }
        }
        if ($cm->modname === 'choice') {
            foreach ($DB->get_records('choice_options', ['choiceid' => $cm->instance], 'id') as $row) {
                $snapshot['extra']['options'][] = [$row->text, $row->maxanswers];
            }
        }
        return $snapshot;
    }
}
