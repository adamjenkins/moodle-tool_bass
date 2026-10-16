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
 * Named configurations of one activity type's ticked settings: per course, or site-wide admin presets.
 *
 * A configuration is a JSON snapshot of a change request (the ticked settings and their values
 * in POST shape) and, for a course configuration, the ticked activities. Admin presets
 * (courseid 0) hold no activities. Saving and loading write nothing to any activity: a loaded
 * configuration is only put into the bulk form, and still goes through Preview and Apply changes
 * and each activity's own form validation, like typed values. On loading, settings the type's
 * form no longer offers are dropped.
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class saved_configs {
    /** @var string the table. */
    const TABLE = 'tool_bass_config';

    /** @var int the courseid of admin presets. */
    const PRESETS = 0;

    /** @var int the snapshot format. */
    const VERSION = 1;

    /** @var int the longest string value kept. */
    const MAXVALUE = 4096;

    /** @var int the deepest array nesting kept in a value. */
    const MAXDEPTH = 4;

    /**
     * The configurations of a course (or the admin presets) for an activity type, by name.
     *
     * @param int $courseid the course id, or PRESETS
     * @param string|null $modname the activity type, null for all types
     * @return \stdClass[] id, courseid, modname, name, timemodified, keyed by id
     */
    public static function list(int $courseid, ?string $modname): array {
        global $DB;
        $conditions = ['courseid' => $courseid];
        if ($modname !== null) {
            $conditions['modname'] = $modname;
        }
        return $DB->get_records(
            self::TABLE,
            $conditions,
            'modname ASC, name ASC, id ASC',
            'id, courseid, modname, name, timemodified'
        );
    }

    /**
     * One configuration of a course (or one admin preset).
     *
     * @param int $courseid the course id, or PRESETS
     * @param int $id the configuration id, which must belong to that course (or be a preset)
     * @return \stdClass
     * @throws \dml_missing_record_exception when there is no such configuration there
     */
    public static function get(int $courseid, int $id): \stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id, 'courseid' => $courseid], '*', MUST_EXIST);
    }

    /**
     * Clean a configuration name: plain text, trimmed, at most 255 characters.
     *
     * @param string $name the name as typed
     * @return string the name, '' when nothing is left
     */
    public static function clean_name(string $name): string {
        return trim(\core_text::substr(trim(clean_param($name, PARAM_TEXT)), 0, 255));
    }

    /**
     * Save a snapshot under a name, replacing the configuration of that name and type there.
     *
     * @param int $courseid the course id, or PRESETS
     * @param string $modname the activity type
     * @param string $name the name, already cleaned (see clean_name())
     * @param array $snapshot from snapshot()
     * @return int the configuration's id
     */
    public static function save(int $courseid, string $modname, string $name, array $snapshot): int {
        global $DB;
        $now = time();
        $data = json_encode($snapshot);
        $existing = $DB->get_record(self::TABLE, ['courseid' => $courseid, 'modname' => $modname, 'name' => $name]);
        if ($existing) {
            $DB->update_record(self::TABLE, (object) ['id' => $existing->id, 'data' => $data, 'timemodified' => $now]);
            return (int) $existing->id;
        }
        return (int) $DB->insert_record(self::TABLE, (object) [
            'courseid' => $courseid,
            'modname' => $modname,
            'name' => $name,
            'data' => $data,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Whether a configuration of that name and type exists there.
     *
     * @param int $courseid
     * @param string $modname
     * @param string $name
     * @return bool
     */
    public static function exists(int $courseid, string $modname, string $name): bool {
        global $DB;
        return $DB->record_exists(self::TABLE, ['courseid' => $courseid, 'modname' => $modname, 'name' => $name]);
    }

    /**
     * Delete one configuration of a course (or one admin preset).
     *
     * @param int $courseid the course id, or PRESETS
     * @param int $id the configuration id, which must belong there
     * @return \stdClass the deleted record
     */
    public static function delete(int $courseid, int $id): \stdClass {
        global $DB;
        $record = self::get($courseid, $id);
        $DB->delete_records(self::TABLE, ['id' => $record->id]);
        return $record;
    }

    /**
     * The snapshot of a change request (and the ticked activities).
     *
     * @param change_request $request
     * @param int[] $cmids the ticked activities (already filtered to the eligible ones); [] for a preset
     * @return array
     */
    public static function snapshot(change_request $request, array $cmids = []): array {
        return [
            'version' => self::VERSION,
            'ticked' => array_values($request->ticked),
            'values' => self::clean_value($request->values, 0) ?? [],
            'gradepasstype' => $request->gradepasstype,
            'cmids' => array_values(array_unique(array_map('intval', $cmids))),
        ];
    }

    /**
     * Keep only scalars and arrays of them, within the size limits.
     *
     * @param mixed $value
     * @param int $depth
     * @return mixed null when nothing is kept
     */
    protected static function clean_value($value, int $depth) {
        if (is_array($value)) {
            if ($depth >= self::MAXDEPTH) {
                return null;
            }
            $result = [];
            foreach ($value as $key => $item) {
                $item = self::clean_value($item, $depth + 1);
                if ($item !== null) {
                    $result[(string) $key] = $item;
                }
            }
            return $result;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return \core_text::substr($value, 0, self::MAXVALUE);
        }
        return null;
    }

    /**
     * A configuration as POST data for the bulk form, keeping only settings the form offers.
     *
     * @param \stdClass $record a configuration record
     * @param bulk_form $form the bulk form of the configuration's activity type
     * @return array [array POST data, int[] cmids]
     */
    public static function to_post(\stdClass $record, bulk_form $form): array {
        $data = json_decode((string) $record->data, true);
        $data = is_array($data) ? $data : [];
        $stored = is_array($data['values'] ?? null) ? self::clean_value($data['values'], 0) : [];
        $ticked = [];
        $values = [];
        $settings = $form->get_settings();
        foreach (is_array($data['ticked'] ?? null) ? $data['ticked'] : [] as $name) {
            if (!is_string($name) || !isset($settings[$name])) {
                continue;
            }
            $ticked[] = $name;
            $values += post_values::subset($stored, $settings[$name]['roots']);
        }
        $type = ($data['gradepasstype'] ?? '') === bulk_form::GRADEPASS_PERCENT
            ? bulk_form::GRADEPASS_PERCENT : bulk_form::GRADEPASS_ABSOLUTE;
        $request = new change_request($form->get_modname(), $ticked, $values, $type);

        $cmids = [];
        foreach (is_array($data['cmids'] ?? null) ? $data['cmids'] : [] as $cmid) {
            if ((is_int($cmid) && $cmid > 0) || (is_string($cmid) && ctype_digit($cmid) && (int) $cmid > 0)) {
                $cmids[] = (int) $cmid;
            }
        }
        return [$request->to_post(), $cmids];
    }
}
