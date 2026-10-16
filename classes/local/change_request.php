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
 * What the teacher asked for: the ticked settings of one activity type and their new values.
 *
 * Values are kept in POST shape (the keys the settings' elements post), so that they can be
 * posted to each activity's own edit form unchanged. Only settings the bulk form offers are
 * kept; anything else in the request is ignored.
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class change_request {
    /**
     * Constructor.
     *
     * @param string $modname the activity type
     * @param string[] $ticked the ticked setting names, in form order
     * @param array $values POST key => posted value, for the roots of the ticked settings
     * @param string $gradepasstype bulk_form::GRADEPASS_ABSOLUTE or GRADEPASS_PERCENT
     */
    public function __construct(
        /** @var string the activity type */
        public readonly string $modname,
        /** @var string[] ticked setting names, in form order */
        public readonly array $ticked,
        /** @var array POST key => value for the ticked settings' roots */
        public readonly array $values,
        /** @var string how the grade to pass is given */
        public readonly string $gradepasstype = bulk_form::GRADEPASS_ABSOLUTE,
    ) {
    }

    /**
     * The request posted with (or loaded into) the bulk form.
     *
     * @param bulk_form $form
     * @return self
     */
    public static function from_form(bulk_form $form): self {
        $submitted = $form->get_submitted();
        $boxes = is_array($submitted[bulk_form::CHANGE] ?? null) ? $submitted[bulk_form::CHANGE] : [];
        $ticked = [];
        $values = [];
        foreach ($form->get_settings() as $name => $setting) {
            if (empty($boxes[$name])) {
                continue;
            }
            $ticked[] = $name;
            foreach ($setting['roots'] as $root) {
                if (array_key_exists($root, $submitted)) {
                    $values[$root] = $submitted[$root];
                }
            }
        }
        $type = (string) ($submitted[bulk_form::GRADEPASSTYPE] ?? bulk_form::GRADEPASS_ABSOLUTE);
        if ($type !== bulk_form::GRADEPASS_PERCENT) {
            $type = bulk_form::GRADEPASS_ABSOLUTE;
        }
        return new self($form->get_modname(), $ticked, $values, $type);
    }

    /**
     * Whether nothing is ticked.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return !$this->ticked;
    }

    /**
     * Whether a setting is ticked.
     *
     * @param string $name
     * @return bool
     */
    public function is_ticked(string $name): bool {
        return in_array($name, $this->ticked, true);
    }

    /**
     * The values to post again to re-create the request in the bulk form (saved configurations).
     *
     * @return array POST shape: the values, the Change boxes and the grade to pass type
     */
    public function to_post(): array {
        $post = $this->values;
        $post[bulk_form::CHANGE] = array_fill_keys($this->ticked, 1);
        $post[bulk_form::GRADEPASSTYPE] = $this->gradepasstype;
        return $post;
    }

    /**
     * A stable description of the request, for the preview's fingerprint.
     *
     * @return array
     */
    public function describe(): array {
        return [
            'modname' => $this->modname,
            'ticked' => $this->ticked,
            'values' => post_values::normalise($this->values),
            'gradepasstype' => $this->gradepasstype,
        ];
    }
}
