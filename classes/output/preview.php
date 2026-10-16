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

use tool_bass\local\activity_plan;

/**
 * The preview of a change request: per selected activity, what changes and why it may not.
 *
 * Template: tool_bass/preview.
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview implements \renderable, \templatable {
    /**
     * Constructor.
     *
     * @param activity_plan[] $plans from {@see \tool_bass\local\planner::plan()}
     */
    public function __construct(
        /** @var activity_plan[] cmid => plan */
        protected array $plans,
    ) {
    }

    /**
     * Whether any activity can be changed.
     *
     * @return bool
     */
    public function can_apply(): bool {
        foreach ($this->plans as $plan) {
            if ($plan->can_apply()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether applying would reset some activity's completion data, so it needs the teacher's confirmation.
     *
     * @return bool
     */
    public function needs_confirmation(): bool {
        foreach ($this->plans as $plan) {
            if ($plan->can_apply() && $plan->needs_confirmation()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Messages as template rows.
     *
     * @param string[] $messages plain text messages
     * @return array[]
     */
    protected static function messages(array $messages): array {
        return array_map(fn(string $message): array => ['message' => $message], array_values($messages));
    }

    /**
     * Data for the template.
     *
     * @param \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $activities = [];
        $counts = ['change' => 0, 'nochange' => 0, 'error' => 0];
        foreach ($this->plans as $cmid => $plan) {
            if ($plan->get_errors()) {
                $status = 'error';
            } else if ($plan->has_changes()) {
                $status = 'change';
            } else {
                $status = 'nochange';
            }
            $counts[$status]++;
            $cm = $plan->get_cm();
            [$name] = activitytable::activity_name($cm);
            $rows = $status === 'error' ? [] : $plan->get_rows();
            $activities[] = [
                'cmid' => (int) $cmid,
                'name' => $name,
                'url' => $cm->url ? $cm->url->out(false) : null,
                'status' => $status,
                'statustext' => get_string('status_' . $status, 'tool_bass'),
                'iserror' => $status === 'error',
                'ischange' => $status === 'change',
                'hasrows' => !empty($rows),
                'rows' => array_map(fn(array $row): array => [
                    'label' => $row['label'],
                    'old' => $row['old'],
                    'new' => $row['new'],
                ], $rows),
                'errors' => self::messages($plan->get_errors()),
                'warnings' => self::messages($plan->get_warnings()),
            ];
        }
        return [
            'summary' => get_string('previewsummary', 'tool_bass', (object) $counts),
            'activities' => $activities,
            'canapply' => $this->can_apply(),
            'needsconfirm' => $this->needs_confirmation(),
        ];
    }
}
