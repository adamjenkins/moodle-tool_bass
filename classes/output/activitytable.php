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

namespace tool_bass\output;

/**
 * The table of the course's activities of one type the user may bulk edit, with a selection checkbox each.
 *
 * Template: tool_bass/activitytable. The checkboxes sit outside the settings form and
 * carry form="<formid>" so they post with it as cmids[].
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activitytable implements \renderable, \templatable {
    /**
     * Constructor.
     *
     * @param array $eligible the result of {@see \tool_bass\local\activity_lister::get_eligible()}
     * @param int[] $selected the course module ids to show ticked (already filtered to eligible ones)
     * @param bool $showcompletion whether to show the Completion tracking column
     * @param string $formid the id of the form the checkboxes submit with
     */
    public function __construct(
        /** @var array cmid => activity_lister entry */
        protected array $eligible,
        /** @var int[] ticked course module ids */
        protected array $selected,
        /** @var bool whether to show the Completion tracking column */
        protected bool $showcompletion,
        /** @var string the settings form's id */
        protected string $formid,
    ) {
    }

    /**
     * The activity name as HTML (format_string()) and as plain text (for the name filter).
     *
     * @param \cm_info $cm
     * @return string[] [html, text]
     */
    public static function activity_name(\cm_info $cm): array {
        $html = format_string($cm->name, true, ['context' => $cm->context]);
        return [$html, html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    }

    /**
     * A completion tracking value as text.
     *
     * @param int $completion
     * @return string
     */
    protected static function completion_text(int $completion): string {
        switch ($completion) {
            case COMPLETION_TRACKING_MANUAL:
                return get_string('completion_manual', 'completion');
            case COMPLETION_TRACKING_AUTOMATIC:
                return get_string('completion_automatic', 'completion');
            default:
                return get_string('completion_none', 'completion');
        }
    }

    /**
     * Data for the template.
     *
     * @param \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $selected = array_flip(array_map('intval', $this->selected));
        $activities = [];
        $graded = false;
        foreach ($this->eligible as $entry) {
            $graded = $graded || $entry->gradepass !== null;
        }
        foreach ($this->eligible as $cmid => $entry) {
            [$namehtml, $nametext] = self::activity_name($entry->cm);
            $activities[] = [
                'cmid' => (int) $cmid,
                'name' => $namehtml,
                'filtername' => $nametext,
                'selectlabel' => get_string('selectactivity', 'tool_bass', $nametext),
                'url' => $entry->cm->url ? $entry->cm->url->out(false) : null,
                'section' => $entry->section,
                'selected' => isset($selected[(int) $cmid]),
                'maxgrade' => $entry->grademax !== null ? format_float($entry->grademax, $entry->decimals) : '-',
                'gradepass' => $entry->gradepass !== null ? format_float($entry->gradepass, $entry->decimals) : '-',
                'completion' => $this->showcompletion ? self::completion_text((int) $entry->cm->completion) : '',
            ];
        }
        return [
            'formid' => $this->formid,
            'hasrows' => !empty($activities),
            'showgrades' => $graded,
            'showcompletion' => $this->showcompletion,
            'colcount' => 3 + ($graded ? 2 : 0) + ($this->showcompletion ? 1 : 0),
            'activities' => $activities,
        ];
    }
}
