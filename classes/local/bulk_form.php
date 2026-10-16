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
 * The bulk settings form: one activity's own edit form, turned into a form of settings to change.
 *
 * The form is the activity type's real mod form, built for a representative activity of the
 * course (the first eligible one in course order whose form locks nothing), so its settings, sections, order, help,
 * rules and show/hide dependencies are exactly those of the edit page. It is then decorated:
 * - per-activity content is removed: name, description, every editor, file and date field,
 *   ID number, tags, access restrictions, competencies, outcomes and repeated fields;
 * - settings the representative's own data locks (e.g. Safe Exam Browser once a quiz has
 *   attempts) are offered unlocked, without the lock notice: whether each selected activity can
 *   take the change is reported per activity in the preview ({@see planner});
 * - every remaining setting gets its own "Change" box (bass_change[<element name>]) and is
 *   disabled while the box is unticked;
 * - the grade to pass gets an "as a percentage of each activity's maximum grade" choice, and
 *   quizzes get a maximum grade setting (the quiz form has none);
 * - the edit page's buttons are replaced by Preview, Apply changes and Save configuration.
 *
 * Only ticked settings are ever read ({@see change_request::from_form()}). A setting is
 * identified by its element name; the POST keys it owns are its "roots" ({@see roots()}).
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_form {
    /** @var string the form's HTML id, referenced by the activity table's checkboxes. */
    public const FORM_ID = 'tool_bass_settings';
    /** @var string the posted array of Change boxes, keyed by setting name. */
    public const CHANGE = 'bass_change';
    /** @var string the grade to pass type select (absolute or percent). */
    public const GRADEPASSTYPE = 'bass_gradepasstype';
    /** @var string the quiz maximum grade setting. */
    public const MAXGRADE = 'bass_maxgrade';
    /** @var string the hidden marker of a bulk form submission. */
    public const MARKER = 'bassform';

    /** @var string grade to pass entered as a grade. */
    public const GRADEPASS_ABSOLUTE = 'absolute';
    /** @var string grade to pass entered as a percentage of each activity's maximum grade. */
    public const GRADEPASS_PERCENT = 'percent';

    /** @var string the header of the completion section in every mod form. */
    public const COMPLETION_HEADER = 'activitycompletionheader';

    /** @var string[] element types that are never offered (per-activity content or not a setting). */
    protected const EXCLUDED_TYPES = [
        'editor', 'filemanager', 'filepicker', 'file', 'date_time_selector', 'date_selector',
        'tags', 'submit', 'button', 'reset', 'cancel', 'image', 'submitlink',
    ];

    /** @var string[] element names that are never offered. */
    protected const EXCLUDED_NAMES = [
        'name', 'introeditor', 'cmidnumber', 'availabilityconditionsjson', 'competencies',
        'competency_rule', 'override_grade', 'completionexpected', 'coursecontentnotification',
        'buttonar', 'unlockcompletion',
    ];

    /** @var array[] notices that a setting is locked on the representative: [string id, component]. */
    protected const LOCK_NOTICES = [['settingsfrozen', 'quizaccess_seb']];

    /** @var string[] section headers whose whole content is out of scope. */
    protected const EXCLUDED_HEADERS = ['availabilityconditionsheader', 'tagshdr', 'competenciessection'];

    /** @var \moodleform_mod the decorated form. */
    protected \moodleform_mod $form;

    /** @var \MoodleQuickForm its QuickForm. */
    protected \MoodleQuickForm $mform;

    /** @var array[] setting name => ['label' => string, 'section' => string, 'sectionlabel' => string,
     *      'roots' => string[], 'completion' => bool], in form order */
    protected array $settings = [];

    /** @var string[] settings that are locked on the representative and offered unlocked here. */
    protected array $unlocked = [];

    /**
     * Build the bulk form of an activity type from a representative activity.
     *
     * @param \cm_info $representative an eligible activity of the type
     * @param \moodle_url $action the bulk page
     * @param array|null $post the bulk form's posted (or loaded) values, null when nothing was posted
     */
    public function __construct(
        /** @var \cm_info the activity whose form is the template */
        protected \cm_info $representative,
        \moodle_url $action,
        ?array $post,
    ) {
        // The JavaScript the mod form adds while it is built is discarded: it belongs to fields that
        // are removed here (the access restrictions widget throws without its field, and stops the
        // form's other scripts). What rendering adds (the form's dependencies, each field's own
        // scripts) is kept, because render() runs later, on the page.
        module_form::without_page_js(function () use ($representative, $post, $action) {
            // Completion settings are offered unlocked; each activity's own lock is handled per activity.
            $this->form = module_form::for_display($representative, $post, ['completionunlocked' => 1]);
            $this->mform = module_form::quickform($this->form);
            module_form::finalise($this->form);
            $this->decorate($action);
        });
    }

    /**
     * The bulk form built from the first eligible activity whose settings form locks nothing.
     *
     * An activity's own data can lock some of its settings (a quiz with attempts locks its Safe
     * Exam Browser settings, and may then drop the template choice altogether). The first activity
     * without such locks is the best template; when every activity has some, the first one whose
     * form can be built is used, with its locked settings offered unlocked.
     *
     * @param \stdClass[] $eligible activity_lister entries of one type, in course order
     * @param \moodle_url $action the bulk page
     * @param array|null $post posted or loaded values
     * @return self|null null when no activity's form can be built
     */
    public static function for_first(array $eligible, \moodle_url $action, ?array $post): ?self {
        $fallback = null;
        foreach ($eligible as $entry) {
            try {
                $form = new self($entry->cm, $action, $post);
                if (!$form->unlocked) {
                    return $form;
                }
                $fallback ??= $form;
            } catch (\Throwable $e) {
                // This activity's form cannot be built here (for example a module that needs a
                // request parameter, or one that calls a server): try the next.
                debugging('tool_bass: the settings form of ' . $entry->cm->modname . ' ' . $entry->cm->id .
                    ' could not be built: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $fallback;
    }

    /**
     * The decorated mod form.
     *
     * @return \moodleform_mod
     */
    public function get_form(): \moodleform_mod {
        return $this->form;
    }

    /**
     * The representative activity.
     *
     * @return \cm_info
     */
    public function get_representative(): \cm_info {
        return $this->representative;
    }

    /**
     * The activity type.
     *
     * @return string
     */
    public function get_modname(): string {
        return $this->representative->modname;
    }

    /**
     * The settings offered, in form order.
     *
     * @return array[] setting name => details (see the property)
     */
    public function get_settings(): array {
        return $this->settings;
    }

    /**
     * The settings that are locked on the representative and offered unlocked.
     *
     * @return string[]
     */
    public function get_unlocked(): array {
        return array_values($this->unlocked);
    }

    /**
     * Whether the form offers a setting.
     *
     * @param string $name
     * @return bool
     */
    public function has_setting(string $name): bool {
        return isset($this->settings[$name]);
    }

    /**
     * The element of a setting.
     *
     * @param string $name a setting name
     * @return \HTML_QuickForm_element
     */
    public function get_element(string $name): \HTML_QuickForm_element {
        return $this->mform->getElement($name);
    }

    /**
     * The submitted (or loaded) values, in POST shape.
     *
     * @return array
     */
    public function get_submitted(): array {
        return $this->mform->isSubmitted() ? (array) $this->mform->getSubmitValues() : [];
    }

    /**
     * The top-level POST keys an element posts: its name's base, or a group's children's.
     *
     * @param \HTML_QuickForm_element $element
     * @return string[]
     */
    public static function roots(\HTML_QuickForm_element $element): array {
        if ($element instanceof \HTML_QuickForm_group && empty($element->_appendName)) {
            $roots = [];
            foreach ($element->getElements() as $child) {
                $roots = array_merge($roots, self::roots($child));
            }
            return array_values(array_unique($roots));
        }
        $name = (string) $element->getName();
        if ($name === '') {
            return [];
        }
        $root = preg_replace('/\[.*$/', '', $name);
        return [$root];
    }

    /**
     * Whether an element (or any element of a group) is of a type that is never offered.
     *
     * @param \HTML_QuickForm_element $element
     * @return bool
     */
    protected static function has_excluded_type(\HTML_QuickForm_element $element): bool {
        if (in_array($element->getType(), self::EXCLUDED_TYPES, true)) {
            return true;
        }
        if ($element instanceof \HTML_QuickForm_group && !($element instanceof \MoodleQuickForm_modgrade)) {
            foreach ($element->getElements() as $child) {
                if (self::has_excluded_type($child)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether an element is a setting that can be offered.
     *
     * @param \HTML_QuickForm_element $element
     * @return bool
     */
    protected static function is_setting(\HTML_QuickForm_element $element): bool {
        $name = (string) $element->getName();
        if ($name === '' || in_array($element->getType(), ['header', 'hidden', 'static', 'html'], true)) {
            return false;
        }
        if (in_array($name, self::EXCLUDED_NAMES, true) || str_contains($name, '[') || str_starts_with($name, 'outcome_')) {
            return false;
        }
        if (self::has_excluded_type($element)) {
            return false;
        }
        return (bool) self::roots($element);
    }

    /**
     * Turn the mod form into the bulk form (see the class docblock).
     *
     * @param \moodle_url $action
     */
    protected function decorate(\moodle_url $action): void {
        $mform = $this->mform;
        $isquiz = $this->get_modname() === 'quiz';

        // 1. Decide what stays, section by section, in the form's own order.
        $keep = [];
        $section = '';
        $sectionlabel = '';
        $removed = [];
        $unfrozen = [];
        $elements = $mform->_elements;
        foreach ($elements as $index => $element) {
            $type = $element->getType();
            $name = (string) $element->getName();
            if ($type === 'header') {
                $section = $name;
                $sectionlabel = trim(strip_tags((string) $element->_text));
                $keep[$index] = !in_array($name, self::EXCLUDED_HEADERS, true);
                continue;
            }
            if ($type === 'hidden') {
                // The edit page's own hidden fields stay, whatever section they follow: its rules and
                // dependencies may refer to them, and the sesskey is added after definition()
                // (moodleform::__construct()), so it follows the last section, which on a standard
                // site is one dropped here (tags or competencies).
                $keep[$index] = $name !== '' && !str_contains($name, '[') && !str_starts_with($name, '_qf__')
                    && !in_array($name, self::EXCLUDED_NAMES, true);
            } else if (in_array($section, self::EXCLUDED_HEADERS, true)) {
                $keep[$index] = false;
            } else if (in_array($type, ['static', 'html'], true)) {
                $keep[$index] = !in_array($name, ['gradeboundarystatic1', 'gradeboundarystatic2', 'restrictgroupbutton'], true)
                    && !self::is_lock_notice($element);
            } else if (self::is_setting($element)) {
                $keep[$index] = true;
                if ($element->isFrozen()) {
                    // Locked by the representative's own data: offered unlocked, checked per activity.
                    $element->unfreeze();
                    $unfrozen[] = $element;
                    $this->unlocked[$name] = $name;
                }
                if (isset($this->settings[$name])) {
                    // A further radio button of the same setting (completion tracking is three radios).
                    continue;
                }
                $this->settings[$name] = [
                    // A radio button's own text is one of its choices: the section names the setting.
                    'label' => $type === 'radio' ? $sectionlabel : self::label_of($element),
                    'section' => $section,
                    'sectionlabel' => $sectionlabel,
                    'roots' => self::roots($element),
                    'completion' => $section === self::COMPLETION_HEADER,
                ];
            } else {
                $keep[$index] = false;
            }
            if (!$keep[$index] && $name !== '') {
                $removed[] = $name;
            }
        }
        // Headers left without a setting are dropped too.
        $lastheader = null;
        $hassetting = false;
        foreach ($elements as $index => $element) {
            if ($element->getType() === 'header') {
                if ($lastheader !== null && !$hassetting) {
                    $keep[$lastheader] = false;
                }
                $lastheader = $keep[$index] ? $index : null;
                $hassetting = false;
            } else if ($keep[$index] && isset($this->settings[(string) $element->getName()])) {
                $hassetting = true;
            }
        }
        if ($lastheader !== null && !$hassetting) {
            $keep[$lastheader] = false;
        }

        // 2. A Change box before every setting (before its first element, for radios), which
        // disables the setting until it is ticked; the grade to pass type and the quiz maximum
        // grade. QuickForm's insertElementBefore() refuses names that several radios share, so
        // the list is built here.
        $addmaxgrade = $isquiz && isset($this->settings['gradepass']);
        $final = [];
        $added = [];
        $seen = [];
        foreach ($elements as $index => $element) {
            if (!$keep[$index]) {
                continue;
            }
            $name = (string) $element->getName();
            if (isset($this->settings[$name]) && !isset($seen[$name])) {
                $seen[$name] = true;
                if ($name === 'gradepass' && $addmaxgrade) {
                    $added[] = $final[] = $this->change_box(self::MAXGRADE, get_string('maximumgrade'));
                    $added[] = $final[] = $mform->createElement('text', self::MAXGRADE, get_string('maximumgrade'), ['size' => 6]);
                }
                $added[] = $final[] = $this->change_box($name, $this->settings[$name]['label']);
                if ($name === 'gradepass') {
                    $added[] = $final[] = $mform->createElement(
                        'select',
                        self::GRADEPASSTYPE,
                        get_string('gradepasstype', 'tool_bass'),
                        [
                            self::GRADEPASS_ABSOLUTE => get_string('gradepasstype_absolute', 'tool_bass'),
                            self::GRADEPASS_PERCENT => get_string('gradepasstype_percent', 'tool_bass'),
                        ]
                    );
                }
            }
            $final[] = $element;
        }
        $this->rebuild($final);
        $this->prune_dependencies($removed);
        // Added and unlocked elements take the posted values (a frozen element ignored them).
        foreach (array_merge($added, $unfrozen) as $element) {
            $element->onQuickFormEvent('updateValue', null, $mform);
        }
        foreach (array_keys($this->settings) as $name) {
            $mform->disabledIf($name, self::CHANGE . '[' . $name . ']', 'notchecked');
        }
        if (isset($this->settings['gradepass'])) {
            $mform->disabledIf(self::GRADEPASSTYPE, self::CHANGE . '[gradepass]', 'notchecked');
        }
        if ($addmaxgrade) {
            $mform->setType(self::MAXGRADE, PARAM_RAW_TRIMMED);
            $mform->setDefault(self::MAXGRADE, format_float((float) $this->get_quiz_grade(), -1));
            $mform->disabledIf(self::MAXGRADE, self::CHANGE . '[' . self::MAXGRADE . ']', 'notchecked');
            $this->settings = $this->insert_setting_before('gradepass', self::MAXGRADE, [
                'label' => get_string('maximumgrade'),
                'section' => $this->settings['gradepass']['section'],
                'sectionlabel' => $this->settings['gradepass']['sectionlabel'],
                'roots' => [self::MAXGRADE],
                'completion' => false,
            ]);
        }

        // 4. The bulk page's own fields.
        $mform->updateAttributes(['id' => self::FORM_ID, 'action' => $action->out(false)]);
        $mform->disable_form_change_checker();
        $mform->addElement('hidden', self::MARKER, 1);
        $mform->setType(self::MARKER, PARAM_INT);
        $mform->setConstant(self::MARKER, 1);

        // Expand the sections that have a ticked setting, so a re-displayed form shows what was asked.
        $ticked = $this->get_submitted()[self::CHANGE] ?? [];
        foreach ($this->settings as $name => $setting) {
            if (!empty($ticked[$name]) && $setting['section'] !== '' && $mform->elementExists($setting['section'])) {
                $mform->setExpanded($setting['section'], true);
            }
        }
    }

    /**
     * Whether an element is a notice that settings are locked on the representative.
     *
     * @param \HTML_QuickForm_element $element a static or html element
     * @return bool
     */
    protected static function is_lock_notice(\HTML_QuickForm_element $element): bool {
        $html = (string) ($element->_text ?? '');
        foreach (self::LOCK_NOTICES as [$identifier, $component]) {
            if (
                get_string_manager()->string_exists($identifier, $component)
                    && str_contains($html, get_string($identifier, $component))
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * A setting's Change box.
     *
     * @param string $name the setting name
     * @param string $label the setting label
     * @return \HTML_QuickForm_element
     */
    protected function change_box(string $name, string $label): \HTML_QuickForm_element {
        return $this->mform->createElement(
            'advcheckbox',
            self::CHANGE . '[' . $name . ']',
            '',
            get_string('changesetting', 'tool_bass', $label)
        );
    }

    /**
     * A setting's value as plain text.
     *
     * @param string $name a setting name
     * @param array $post POST data holding the setting's roots
     * @return string
     */
    public function display(string $name, array $post): string {
        $element = $this->mform->getElement($name);
        if ($element instanceof \HTML_QuickForm_radio) {
            // Radios that share the name: the text of the chosen one.
            $value = post_values::find($post, $name);
            foreach ($this->mform->_elements as $candidate) {
                if (
                    $candidate instanceof \HTML_QuickForm_radio && $candidate->getName() === $name
                        && (string) $candidate->getValue() === (string) $value
                ) {
                    return trim(strip_tags((string) $candidate->getText()));
                }
            }
            return (string) $value;
        }
        return post_values::display($element, $post);
    }

    /**
     * The quiz's current maximum grade (the representative's).
     *
     * @return float
     */
    protected function get_quiz_grade(): float {
        global $DB;
        return (float) $DB->get_field('quiz', 'grade', ['id' => $this->representative->instance]);
    }

    /**
     * The settings with one inserted before another.
     *
     * @param string $before
     * @param string $name
     * @param array $setting
     * @return array[]
     */
    protected function insert_setting_before(string $before, string $name, array $setting): array {
        $settings = [];
        foreach ($this->settings as $key => $value) {
            if ($key === $before) {
                $settings[$name] = $setting;
            }
            $settings[$key] = $value;
        }
        return $settings;
    }

    /**
     * A plain text label for an element.
     *
     * @param \HTML_QuickForm_element $element
     * @return string
     */
    public static function label_of(\HTML_QuickForm_element $element): string {
        $label = trim(strip_tags((string) $element->getLabel()));
        if ($label === '' && method_exists($element, 'getText')) {
            $label = trim(strip_tags((string) $element->getText()));
        }
        return $label !== '' ? html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8') : (string) $element->getName();
    }

    /**
     * Replace the form's elements by a subset, keeping QuickForm's name index consistent.
     *
     * @param \HTML_QuickForm_element[] $elements
     */
    protected function rebuild(array $elements): void {
        $mform = $this->mform;
        $mform->_elements = array_values($elements);
        $mform->_elementIndex = [];
        $mform->_duplicateIndex = [];
        foreach ($mform->_elements as $index => $element) {
            $name = $element->getName();
            if ($name === null || $name === '') {
                continue;
            }
            if (isset($mform->_elementIndex[$name])) {
                $mform->_duplicateIndex[$name][] = $index;
            } else {
                $mform->_elementIndex[$name] = $index;
            }
        }
        foreach ($this->mform->_rules as $name => $rules) {
            if (!$this->mform->elementExists($name)) {
                unset($this->mform->_rules[$name]);
            }
        }
    }

    /**
     * Drop show/hide and enable/disable rules that depend on removed elements.
     *
     * A rule on a field that is not on the page would otherwise hide or disable a setting for good.
     *
     * @param string[] $removed element names
     */
    protected function prune_dependencies(array $removed): void {
        $roots = array_flip(array_map(fn($name) => preg_replace('/\[.*$/', '', $name), $removed));
        $hideifs = new \ReflectionProperty(\MoodleQuickForm::class, '_hideifs');
        $rules = ['_dependencies' => $this->mform->_dependencies, '_hideifs' => $hideifs->getValue($this->mform)];
        foreach ($rules as $property => $dependencies) {
            foreach (array_keys($dependencies) as $dependee) {
                if (isset($roots[preg_replace('/\[.*$/', '', (string) $dependee)])) {
                    unset($dependencies[$dependee]);
                }
            }
            if ($property === '_hideifs') {
                $hideifs->setValue($this->mform, $dependencies);
            } else {
                $this->mform->_dependencies = $dependencies;
            }
        }
    }

    /**
     * Add the preview section: the rendered preview, the completion confirmation and the fingerprint.
     *
     * @param string $html the rendered preview
     * @param bool $needsconfirm add the completion reset confirmation
     * @param string $fingerprint the preview's fingerprint
     */
    public function add_preview(string $html, bool $needsconfirm, string $fingerprint): void {
        $mform = $this->mform;
        $mform->addElement('header', 'section_preview', get_string('previewheading', 'tool_bass'));
        $mform->setExpanded('section_preview', true);
        $mform->addElement('html', $html);
        if ($needsconfirm) {
            $mform->addElement(
                'advcheckbox',
                'confirmreset',
                get_string('confirmresetlabel', 'tool_bass'),
                get_string('confirmreset', 'tool_bass')
            );
            // A confirmation always applies to the preview on screen, never to an earlier one.
            $mform->setConstant('confirmreset', 0);
        }
        $mform->addElement('hidden', 'fingerprint');
        $mform->setType('fingerprint', PARAM_ALPHANUM);
        $mform->setConstant('fingerprint', $fingerprint);
    }

    /**
     * Add the buttons and the Save configuration section.
     *
     * @param bool $canapply add Apply changes (a preview with something to apply is shown)
     * @param bool $canpreset offer to save the configuration as a site-wide admin preset
     */
    public function add_buttons(bool $canapply, bool $canpreset): void {
        $mform = $this->mform;
        $buttons = [$mform->createElement('submit', 'preview', get_string('preview', 'tool_bass'))];
        if ($canapply) {
            $buttons[] = $mform->createElement('submit', 'apply', get_string('apply', 'tool_bass'));
        }
        $mform->addGroup($buttons, 'bass_buttonar', '', ' ', false);
        $mform->closeHeaderBefore('bass_buttonar');

        // Save the ticked settings (and the ticked activities) under a name; this writes nothing to any activity.
        $mform->addElement('header', 'section_saveconfig', get_string('saveconfigheading', 'tool_bass'));
        $mform->setExpanded('section_saveconfig', false);
        $mform->addElement('text', 'configname', get_string('configname', 'tool_bass'), ['size' => 30]);
        $mform->setType('configname', PARAM_TEXT);
        if ($canpreset) {
            $mform->addElement('advcheckbox', 'aspreset', '', get_string('saveaspreset', 'tool_bass'));
        }
        $mform->addElement('submit', 'saveconfig', get_string('saveconfig', 'tool_bass'));
    }

    /**
     * Show an error next to an element of the bulk form.
     *
     * @param string $name element name
     * @param string $message plain text
     */
    public function set_error(string $name, string $message): void {
        if ($this->mform->elementExists($name)) {
            $this->mform->setElementError($name, s($message));
        }
    }

    /**
     * Render the form.
     *
     * @return string
     */
    public function render(): string {
        return $this->form->render();
    }
}
