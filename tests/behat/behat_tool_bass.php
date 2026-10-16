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

/**
 * Behat steps for tool_bass.
 *
 * @package    tool_bass
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps for tool_bass.
 */
class behat_tool_bass extends behat_base {
    /**
     * Convert page names to URLs for 'I am on the "[identifier]" "tool_bass > [page]" page'.
     *
     * | page          | identifier                                 | description                       |
     * | Bulk settings | Course shortname, or shortname:activitytype | The bulk page, optionally by type |
     *
     * @param string $page The page type: 'Bulk settings'.
     * @param string $identifier The course shortname, optionally followed by :modname.
     * @return moodle_url
     * @throws Exception If the page type is unknown.
     */
    protected function resolve_page_instance_url(string $page, string $identifier): moodle_url {
        if (strtolower($page) !== 'bulk settings') {
            throw new Exception("Unrecognised tool_bass page type '{$page}'");
        }
        [$shortname, $modname] = array_pad(explode(':', $identifier, 2), 2, '');
        $params = ['courseid' => $this->get_course_id($shortname)];
        if ($modname !== '') {
            $params['modname'] = $modname;
        }
        return new moodle_url('/admin/tool/bass/index.php', $params);
    }

    /**
     * Open the bulk page of a course directly and check that the current user is refused.
     *
     * Every step is followed by a check for exceptions on the page, so this step checks the
     * missing-capability error itself and then leaves the error page.
     *
     * @Then /^I should be refused access to the bulk settings page of "(?P<identifier>[^"]*)"$/
     *
     * @param string $identifier The course shortname.
     * @throws ExpectationException If the page opened, or failed for another reason.
     */
    public function i_should_be_refused_access_to_the_bulk_settings_page(string $identifier): void {
        $url = $this->resolve_page_instance_url('Bulk settings', $identifier);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));

        $error = $this->getSession()->getPage()->find('xpath', "//div[@data-rel='fatalerror']");
        if (!$error) {
            throw new ExpectationException('The bulk settings page opened; access should have been refused', $this->getSession());
        }
        $expected = get_string('nopermissions', 'error', get_capability_string('tool/bass:manage'));
        if (!str_contains($error->getText(), $expected)) {
            throw new ExpectationException(
                "Expected '{$expected}' but the page showed: " . $error->getText(),
                $this->getSession()
            );
        }
        $this->getSession()->visit($this->locate_path('/'));
    }

    /**
     * Check a stored setting of an activity, read from the database.
     *
     * "gradepass" and "grademax" are read from the activity's main grade item; anything else
     * from the activity's own table. Numbers are compared as numbers.
     *
     * @Then /^the "(?P<setting>[^"]*)" of activity "(?P<idnumber>[^"]*)" should be "(?P<value>[^"]*)"$/
     *
     * @param string $setting The column name.
     * @param string $idnumber The activity's idnumber.
     * @param string $value The expected value.
     * @throws ExpectationException If the value differs.
     */
    public function the_setting_of_activity_should_be(string $setting, string $idnumber, string $value): void {
        global $DB;
        $cm = $DB->get_record_sql(
            "SELECT cm.*, m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.idnumber = :idnumber",
            ['idnumber' => $idnumber],
            MUST_EXIST
        );
        if ($setting === 'gradepass' || $setting === 'grademax') {
            $actual = $DB->get_field('grade_items', $setting, [
                'courseid' => $cm->course,
                'itemtype' => 'mod',
                'itemmodule' => $cm->modname,
                'iteminstance' => $cm->instance,
                'itemnumber' => 0,
            ], MUST_EXIST);
        } else {
            if (!isset($DB->get_columns($cm->modname)[$setting])) {
                throw new ExpectationException("The {$cm->modname} table has no '{$setting}' column", $this->getSession());
            }
            $actual = $DB->get_field($cm->modname, $setting, ['id' => $cm->instance], MUST_EXIST);
        }

        if (is_numeric($value) && is_numeric($actual)) {
            $same = abs((float) $actual - (float) $value) < 0.000001;
        } else {
            $same = (string) $actual === $value;
        }
        if (!$same) {
            throw new ExpectationException(
                "The '{$setting}' of activity '{$idnumber}' is '" . var_export($actual, true) . "', not '{$value}'",
                $this->getSession()
            );
        }
    }
}
