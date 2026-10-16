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
 * An activity's own settings form (mod_<type>_mod_form), built and submitted the way course/modedit.php does.
 *
 * This is how tool_bass reads and writes every activity type without per-type code:
 * - {@see current_post()} renders the activity's edit form with its current settings and reads
 *   the controls back as a browser without JavaScript would post them;
 * - {@see with_submission()} posts such values to a fresh copy of the form, so the form's own
 *   rules, validation(), data_postprocessing() and the module's update_instance() all run
 *   exactly as when a teacher presses Save on the edit page.
 *
 * Every form is built with the request isolated ({@see isolated()}): a mod form reads the
 * request while it is built (its submission marker, the sesskey, draft item ids), and the
 * page's own POST must not leak into another form.
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class module_form {
    /** @var string[] input types a browser never posts. */
    protected const UNPOSTED_INPUTS = ['submit', 'button', 'image', 'reset', 'file'];

    /**
     * Run a callback with $_POST, $_GET, $_REQUEST and $_FILES replaced, restoring them afterwards.
     *
     * @param array $post the POST data the callback sees ([] for an unsubmitted request)
     * @param callable $callback
     * @return mixed the callback's result
     */
    public static function isolated(array $post, callable $callback) {
        $saved = [$_POST, $_GET, $_REQUEST, $_FILES];
        $_POST = $post;
        $_GET = [];
        $_REQUEST = $post;
        $_FILES = [];
        try {
            return $callback();
        } finally {
            [$_POST, $_GET, $_REQUEST, $_FILES] = $saved;
        }
    }

    /**
     * Run a callback with the page's JavaScript requirements sent to a throwaway manager.
     *
     * Forms that are built but never shown still add JavaScript to $PAGE (editors, file
     * pickers, form dependencies), which would run on the bulk page against elements that are
     * not there. moodle_page::start_collecting_javascript_requirements() cannot be used: it
     * needs the page header to have been printed, and the forms are built before it.
     *
     * @param callable $callback
     * @return mixed the callback's result
     */
    public static function without_page_js(callable $callback) {
        global $PAGE;
        $requires = new \ReflectionProperty(\moodle_page::class, '_requires');
        $saved = $requires->getValue($PAGE);
        $requires->setValue($PAGE, new \page_requirements_manager());
        try {
            return $callback();
        } finally {
            $requires->setValue($PAGE, $saved);
        }
    }

    /**
     * The form's MoodleQuickForm (moodleform keeps it protected).
     *
     * @param \moodleform $form
     * @return \MoodleQuickForm
     */
    public static function quickform(\moodleform $form): \MoodleQuickForm {
        return (new \ReflectionProperty(\moodleform::class, '_form'))->getValue($form);
    }

    /**
     * Run the form's definition_after_data() now, once, as display() and get_data() would.
     *
     * @param \moodleform $form
     */
    public static function finalise(\moodleform $form): void {
        $finalised = new \ReflectionProperty(\moodleform::class, '_definition_finalized');
        if (!$finalised->getValue($form)) {
            $finalised->setValue($form, true);
            $form->definition_after_data();
        }
    }

    /**
     * The class name of a module's settings form, loading its file.
     *
     * @param string $modname
     * @return string|null null when the module has no settings form
     */
    public static function form_class(string $modname): ?string {
        global $CFG;
        $file = $CFG->dirroot . '/mod/' . $modname . '/mod_form.php';
        if (!file_exists($file)) {
            return null;
        }
        require_once($file);
        $class = 'mod_' . $modname . '_mod_form';
        return class_exists($class) ? $class : null;
    }

    /**
     * Build an activity's settings form, inside the isolated request, as course/modedit.php does.
     *
     * A form that is not displayed must be built inside {@see without_page_js()}.
     *
     * @param \cm_info $cm
     * @param array|null $post the posted values (the form is then submitted), or null
     * @param array $overrides values to put into the form's data before set_data()
     * @return array [\moodleform_mod form, \stdClass course_modules record, \stdClass data]
     */
    protected static function build_in_request(\cm_info $cm, ?array $post, array $overrides): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        $class = self::form_class($cm->modname);
        if ($class === null) {
            throw new \moodle_exception('noformdesc');
        }
        if ($post !== null) {
            // Posted before the data is loaded, as on the edit page: get_moduleinfo_data() and the
            // form's data_preprocessing() reuse the posted draft areas (file_get_submitted_draft_itemid()).
            $post['_qf__' . $class] = 1;
            $post['sesskey'] = sesskey();
            $_POST = $post;
            $_REQUEST = $post;
        }
        $course = $cm->get_course();
        $cmrecord = get_coursemodule_from_id('', $cm->id, 0, false, MUST_EXIST);
        [$cmrecord, , , $data, $section] = get_moduleinfo_data($cmrecord, $course);
        $data->update = $cm->id;
        $data->return = 0;
        foreach ($overrides as $name => $value) {
            $data->$name = $value;
        }

        $form = new $class($data, $section->section, $cmrecord, $course);
        $form->set_data($data);
        return [$form, $cmrecord, $data];
    }

    /**
     * Build an activity's settings form for display (the bulk page's representative form).
     *
     * @param \cm_info $cm
     * @param array|null $post the bulk page's posted values, or null when nothing was posted
     * @param array $overrides values to put into the form's data before set_data()
     * @return \moodleform_mod
     */
    public static function for_display(\cm_info $cm, ?array $post, array $overrides = []): \moodleform_mod {
        return self::isolated([], function () use ($cm, $post, $overrides) {
            [$form] = self::build_in_request($cm, $post, $overrides);
            return $form;
        });
    }

    /**
     * An activity's current settings as its edit page would post them back unchanged.
     *
     * The edit form is built with the activity's current data and read as a browser posts it
     * ({@see browser_post_of()}).
     *
     * @param \cm_info $cm
     * @return array the POST data (PHP's own array shape for names like a[b])
     */
    public static function current_post(\cm_info $cm): array {
        return self::current_state($cm, [])[0];
    }

    /**
     * An activity's current settings ({@see current_post()}) and which of some settings it cannot take.
     *
     * A setting cannot be changed on an activity whose edit form does not have it, or shows it
     * locked (frozen): a quiz with attempts locks its Safe Exam Browser settings, and may drop
     * the template choice altogether.
     *
     * @param \cm_info $cm
     * @param string[] $names setting (element) names
     * @return array [array the POST data, string[] name => 'missing' or 'frozen', for the names it lacks or locks]
     */
    public static function current_state(\cm_info $cm, array $names): array {
        return self::without_page_js(fn() => self::isolated([], function () use ($cm, $names) {
            [$form] = self::build_in_request($cm, null, []);
            $quickform = self::quickform($form);
            $unavailable = [];
            foreach ($names as $name) {
                if (!$quickform->elementExists($name)) {
                    $unavailable[$name] = 'missing';
                } else if ($quickform->getElement($name)->isFrozen()) {
                    $unavailable[$name] = 'frozen';
                }
            }
            return [self::browser_post_of($form), $unavailable];
        }));
    }

    /**
     * What a browser posts after a teacher put some values into an activity's edit form.
     *
     * The form is built with the given values as its submission, so it shows them, and is read
     * as a browser posts it: the form's own show/hide and enable/disable rules are evaluated
     * for the new values, as the edit page's JavaScript does before the form is sent.
     *
     * @param \cm_info $cm
     * @param array $post POST data: the activity's current settings with some replaced
     * @return array the POST data the browser would send
     */
    public static function browser_post(\cm_info $cm, array $post): array {
        return self::without_page_js(fn() => self::isolated([], function () use ($cm, $post) {
            [$form] = self::build_in_request($cm, $post, []);
            return self::browser_post_of($form);
        }));
    }

    /**
     * Render a form and read it as a browser with JavaScript posts it.
     *
     * @param \moodleform $form
     * @return array the POST data
     */
    protected static function browser_post_of(\moodleform $form): array {
        $html = $form->render();
        // The form's JavaScript gets [form id, dependencies] from getLockOptionObject().
        [, $dependencies] = self::quickform($form)->getLockOptionObject();
        return self::parse_post($html, $dependencies);
    }

    /**
     * Post values to a fresh copy of an activity's settings form and hand the result to a callback.
     *
     * The callback runs inside the isolated request (a module's update_instance() may read the
     * request, as forum_update_instance() does) and receives:
     * - \moodleform_mod $form the submitted form
     * - \stdClass|null $data its get_data(): null when the form's validation failed
     * - \stdClass $cmrecord the course_modules record, for update_moduleinfo()
     *
     * @param \cm_info $cm
     * @param array $post the POST data
     * @param callable $callback
     * @return mixed the callback's result
     */
    public static function with_submission(\cm_info $cm, array $post, callable $callback) {
        return self::without_page_js(fn() => self::isolated([], function () use ($cm, $post, $callback) {
            [$form, $cmrecord] = self::build_in_request($cm, $post, []);
            return $callback($form, $form->get_data(), $cmrecord);
        }));
    }

    /**
     * The validation errors of a submitted form.
     *
     * @param \moodleform $form
     * @return string[] element name => message
     */
    public static function errors(\moodleform $form): array {
        $errors = [];
        foreach (self::quickform($form)->_errors as $name => $message) {
            $errors[(string) $name] = trim(strip_tags((string) $message));
        }
        return $errors;
    }

    /**
     * Read a rendered form's controls as a browser posts them.
     *
     * Every enabled control is posted (checked boxes and radios only, a single select's selected
     * or first option), except the controls the form's dependency rules disable: the edit page's
     * JavaScript (lib/form/form.js) disables every element whose disabledIf() or hideIf()
     * condition holds, and a disabled control is not posted. The same conditions are evaluated
     * here ({@see dependency_holds()}).
     *
     * @param string $html the rendered form
     * @param array $dependencies the dependencies of MoodleQuickForm::getLockOptionObject() (its second item)
     * @return array the POST data
     */
    public static function parse_post(string $html, array $dependencies = []): array {
        $controls = self::read_controls($html);

        // Disable the dependents of every condition that holds, as checkDependencies() does.
        $locked = [];
        foreach ($dependencies as $dependon => $conditions) {
            $elements = array_values(array_filter($controls, fn(array $control) => $control['name'] === (string) $dependon));
            foreach ($conditions as $condition => $values) {
                foreach ($values as $value => $kinds) {
                    if (!self::dependency_holds((string) $condition, $elements, (string) $value)) {
                        continue;
                    }
                    foreach ($kinds as $dependents) {
                        foreach ($dependents as $dependent) {
                            $locked[(string) $dependent] = true;
                        }
                    }
                }
            }
        }

        $pairs = [];
        foreach ($controls as $control) {
            if ($control['disabled'] || self::is_locked($control['name'], $locked)) {
                continue;
            }
            foreach ($control['posts'] as $value) {
                $pairs[] = [$control['name'], $value];
            }
        }

        $limit = (int) ini_get('max_input_vars');
        if ($limit > 0 && count($pairs) > $limit) {
            // The edit page itself could not be saved either; never save a truncated copy.
            throw new \moodle_exception('error_toomanyfields', 'tool_bass', '', count($pairs));
        }
        $query = implode('&', array_map(fn(array $pair): string => rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]), $pairs));
        $post = [];
        parse_str($query, $post);
        return $post;
    }

    /**
     * Whether a control is disabled by the locked element names (an element, or a group's member).
     *
     * @param string $name the control's name
     * @param bool[] $locked locked element name => true
     * @return bool
     */
    protected static function is_locked(string $name, array $locked): bool {
        if (isset($locked[$name])) {
            return true;
        }
        // A group's members (name[...]) and an editor's text (name[text]) go with it.
        $base = preg_replace('/\[.*$/', '', $name);
        if ($base !== $name && isset($locked[$base])) {
            return true;
        }
        return false;
    }

    /**
     * Read a rendered form's controls.
     *
     * @param string $html
     * @return array[] each: name, type (input type, or select/textarea), value (what JavaScript reads),
     *      checked, multiple, selected (select values), disabled, isadvhidden (an advcheckbox's hidden
     *      input), filepicker, posts (the values a browser posts when the control is enabled)
     */
    protected static function read_controls(string $html): array {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $controls = [];
        $checkboxnames = [];
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//input|//select|//textarea') as $node) {
            $name = $node->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $tag = strtolower($node->nodeName);
            $type = $tag === 'input' ? strtolower($node->getAttribute('type') ?: 'text') : $tag;
            $control = [
                'name' => $name,
                'type' => $type,
                'value' => '',
                'checked' => $node->hasAttribute('checked'),
                'multiple' => $node->hasAttribute('multiple'),
                'selected' => [],
                'disabled' => $node->hasAttribute('disabled') || $xpath->query('ancestor::fieldset[@disabled]', $node)->length > 0,
                'isadvhidden' => false,
                'filepicker' => strtolower($node->getAttribute('class')) === 'filepickerhidden',
                'posts' => [],
            ];
            if ($tag === 'select') {
                [$selected, $first] = self::selected_options($xpath, $node);
                $control['selected'] = $selected;
                $control['value'] = $selected ? end($selected) : (string) $first;
                $control['posts'] = $control['multiple'] ? $selected : ($selected ? [end($selected)] :
                    ($first === null ? [] : [$first]));
            } else if ($tag === 'textarea') {
                $control['value'] = $node->textContent;
                $control['posts'] = [$control['value']];
            } else {
                $control['value'] = $node->hasAttribute('value') ? $node->getAttribute('value') :
                    (in_array($type, ['checkbox', 'radio'], true) ? 'on' : '');
                if (in_array($type, self::UNPOSTED_INPUTS, true)) {
                    $control['posts'] = [];
                } else if ($type === 'checkbox' || $type === 'radio') {
                    $control['posts'] = $control['checked'] ? [$control['value']] : [];
                    if ($type === 'checkbox') {
                        $checkboxnames[$name] = true;
                    }
                } else {
                    $control['posts'] = [$control['value']];
                }
            }
            $controls[] = $control;
        }
        foreach ($controls as $index => $control) {
            // An advcheckbox is a hidden input followed by a checkbox of the same name.
            $controls[$index]['isadvhidden'] = $control['type'] === 'hidden' && isset($checkboxnames[$control['name']]);
        }
        return $controls;
    }

    /**
     * Whether a dependency condition holds, as lib/form/form.js decides it (the _dependency* functions).
     *
     * Conditions: notchecked, checked, noitemselected, eq, in, hide (hides without disabling),
     * and anything else as "neq" (the default). A file picker counts as empty: no file is ever
     * added here.
     *
     * @param string $condition
     * @param array[] $elements the controls named as the element depended on
     * @param string $value the condition's value
     * @return bool whether the dependents are disabled
     */
    protected static function dependency_holds(string $condition, array $elements, string $value): bool {
        $lock = false;
        $hiddenval = false;
        $values = explode('|', $value);
        foreach ($elements as $element) {
            $type = $element['type'];
            switch ($condition) {
                case 'hide':
                    return false;
                case 'notchecked':
                case 'checked':
                    if ($element['isadvhidden'] || ($type === 'radio' && $element['value'] !== $value)) {
                        break;
                    }
                    $lock = $lock || ($condition === 'checked' ? $element['checked'] : !$element['checked']);
                    break;
                case 'noitemselected':
                    $none = !$element['selected'] && ($element['multiple'] || $element['value'] === '');
                    $lock = $lock || ($type === 'select' && $none);
                    break;
                case 'eq':
                case 'in':
                default:
                    if ($type === 'radio' && !$element['checked']) {
                        break;
                    }
                    if ($element['isadvhidden']) {
                        $hiddenval = match ($condition) {
                            'eq' => $element['value'] == $value,
                            'in' => in_array($element['value'], $values),
                            default => $element['value'] != $value,
                        };
                        break;
                    }
                    if ($type === 'checkbox' && !$element['checked']) {
                        $lock = $lock || $hiddenval;
                        break;
                    }
                    if ($element['filepicker']) {
                        $lock = $condition === 'eq' || $condition === 'in';
                        break;
                    }
                    if ($type === 'select' && $element['multiple']) {
                        $lock = self::multiselect_lock($condition, $element['selected'], $values);
                        break;
                    }
                    $lock = $lock || match ($condition) {
                        'eq' => $element['value'] == $value,
                        'in' => in_array($element['value'], $values),
                        default => $element['value'] != $value,
                    };
            }
        }
        return $lock;
    }

    /**
     * The eq, in and default conditions on a multiple select, as lib/form/form.js decides them.
     *
     * @param string $condition
     * @param string[] $selected the selected values
     * @param string[] $values the condition's values
     * @return bool
     */
    protected static function multiselect_lock(string $condition, array $selected, array $values): bool {
        if ($condition === 'in') {
            $selected = $selected ?: [''];
            return !array_diff($selected, $values);
        }
        $same = ($values === ['']) ? !$selected :
            (count($selected) === count($values) && !array_diff($selected, $values));
        return $condition === 'eq' ? $same : !$same;
    }

    /**
     * A select's selected options and its first enabled option (what a single select posts with none selected).
     *
     * @param \DOMXPath $xpath
     * @param \DOMElement $select
     * @return array [string[] selected values, string|null first enabled option value]
     */
    protected static function selected_options(\DOMXPath $xpath, \DOMElement $select): array {
        $first = null;
        $selected = [];
        foreach ($xpath->query('.//option', $select) as $option) {
            $value = $option->hasAttribute('value') ? $option->getAttribute('value') : trim($option->textContent);
            $disabled = $option->hasAttribute('disabled') || $xpath->query('ancestor::optgroup[@disabled]', $option)->length;
            if ($disabled) {
                continue;
            }
            if ($option->hasAttribute('selected')) {
                $selected[] = $value;
            }
            $first = $first ?? $value;
        }
        return [$selected, $first];
    }
}
