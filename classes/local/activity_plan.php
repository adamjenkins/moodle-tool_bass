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
 * The plan for one activity: what would change, and why it may not.
 *
 * Built by {@see planner}, shown by the preview and carried out by {@see applier}.
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity_plan {
    /** @var string[] errors: the activity is skipped. */
    protected array $errors = [];
    /** @var string[] warnings shown with the changes. */
    protected array $warnings = [];
    /** @var array[] changed settings: name, label, old, new (plain text). */
    protected array $rows = [];
    /** @var array the POST data to save: the activity's current settings with the ticked ones replaced. */
    protected array $post = [];
    /** @var array the activity's current values of the ticked settings, for the fingerprint. */
    protected array $current = [];
    /** @var float|null the quiz's new maximum grade, when it changes. */
    protected ?float $maxgrade = null;
    /** @var bool whether applying resets completion data, so it needs a confirmation. */
    protected bool $needsconfirm = false;

    /**
     * Constructor.
     *
     * @param \cm_info $cm the activity
     */
    public function __construct(
        /** @var \cm_info the activity */
        protected \cm_info $cm,
    ) {
    }

    /**
     * The activity.
     *
     * @return \cm_info
     */
    public function get_cm(): \cm_info {
        return $this->cm;
    }

    /**
     * Add an error: the activity will be skipped.
     *
     * @param string $message plain text
     */
    public function add_error(string $message): void {
        $this->errors[] = $message;
    }

    /**
     * Add a warning.
     *
     * @param string $message plain text
     */
    public function add_warning(string $message): void {
        $this->warnings[] = $message;
    }

    /**
     * Add a changed setting.
     *
     * @param string $name setting name
     * @param string $label setting label
     * @param string $old current value (plain text)
     * @param string $new new value (plain text)
     */
    public function add_row(string $name, string $label, string $old, string $new): void {
        $this->rows[] = ['name' => $name, 'label' => $label, 'old' => $old, 'new' => $new];
    }

    /**
     * Set the POST data to save.
     *
     * @param array $post
     */
    public function set_post(array $post): void {
        $this->post = $post;
    }

    /**
     * Set the current values of the ticked settings.
     *
     * @param array $current
     */
    public function set_current(array $current): void {
        $this->current = $current;
    }

    /**
     * Set the quiz's new maximum grade.
     *
     * @param float|null $maxgrade
     */
    public function set_maxgrade(?float $maxgrade): void {
        $this->maxgrade = $maxgrade;
    }

    /**
     * Mark the plan as resetting completion data.
     *
     * @param bool $needsconfirm
     */
    public function set_needs_confirmation(bool $needsconfirm): void {
        $this->needsconfirm = $needsconfirm;
    }

    /**
     * The errors.
     *
     * @return string[]
     */
    public function get_errors(): array {
        return $this->errors;
    }

    /**
     * The warnings.
     *
     * @return string[]
     */
    public function get_warnings(): array {
        return $this->warnings;
    }

    /**
     * The changed settings.
     *
     * @return array[]
     */
    public function get_rows(): array {
        return $this->rows;
    }

    /**
     * The POST data to save.
     *
     * @return array
     */
    public function get_post(): array {
        return $this->post;
    }

    /**
     * The current values of the ticked settings.
     *
     * @return array
     */
    public function get_current(): array {
        return $this->current;
    }

    /**
     * The quiz's new maximum grade, or null when it does not change.
     *
     * @return float|null
     */
    public function get_maxgrade(): ?float {
        return $this->maxgrade;
    }

    /**
     * Whether anything would change.
     *
     * @return bool
     */
    public function has_changes(): bool {
        return !empty($this->rows);
    }

    /**
     * Whether the plan can be applied: something changes and nothing is wrong.
     *
     * @return bool
     */
    public function can_apply(): bool {
        return !$this->errors && $this->has_changes();
    }

    /**
     * Whether applying resets completion data.
     *
     * @return bool
     */
    public function needs_confirmation(): bool {
        return $this->needsconfirm;
    }
}
