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
 * Helpers for values in POST shape: comparing them and turning them into readable text.
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class post_values {
    /**
     * The POST keys of some roots, taken from POST data.
     *
     * @param array $post
     * @param string[] $roots
     * @return array root => value, for the roots that are present
     */
    public static function subset(array $post, array $roots): array {
        $subset = [];
        foreach ($roots as $root) {
            if (array_key_exists($root, $post)) {
                $subset[$root] = $post[$root];
            }
        }
        return $subset;
    }

    /**
     * Replace the given roots of POST data by the values of another.
     *
     * A root that the new values do not have is removed (an unchecked checkbox posts nothing).
     *
     * @param array $post
     * @param string[] $roots
     * @param array $values
     * @return array
     */
    public static function overlay(array $post, array $roots, array $values): array {
        foreach ($roots as $root) {
            unset($post[$root]);
            if (array_key_exists($root, $values)) {
                $post[$root] = $values[$root];
            }
        }
        return $post;
    }

    /**
     * A canonical form of POST values for comparison: sorted keys, numbers compared as numbers.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function normalise($value) {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[(string) $key] = self::normalise($item);
            }
            ksort($result, SORT_STRING);
            return $result;
        }
        if ($value === null) {
            return '';
        }
        $value = trim((string) $value);
        if (is_numeric($value)) {
            // 10, 10.0 and 10.00000 are the same grade.
            return rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');
        }
        return $value;
    }

    /**
     * Whether two sets of POST values are the same.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    public static function same(array $a, array $b): bool {
        return self::normalise($a) === self::normalise($b);
    }

    /**
     * Find a value in POST data by an element name such as grade[modgrade_type].
     *
     * @param array $post
     * @param string $name
     * @return mixed null when absent
     */
    public static function find(array $post, string $name) {
        $name = preg_replace('/\[\]$/', '', $name);
        if (!preg_match('/^([^\[]+)((?:\[[^\]]*\])*)$/', $name, $matches)) {
            return null;
        }
        $keys = [$matches[1]];
        if ($matches[2] !== '') {
            preg_match_all('/\[([^\]]*)\]/', $matches[2], $parts);
            $keys = array_merge($keys, $parts[1]);
        }
        $value = $post;
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * A setting's value as plain text, read the way its element shows it.
     *
     * @param \HTML_QuickForm_element $element the setting's element (in the bulk form)
     * @param array $post POST data holding the setting's roots
     * @return string
     */
    public static function display(\HTML_QuickForm_element $element, array $post): string {
        $text = implode(', ', array_filter(self::parts($element, $post, ''), fn($part) => $part !== ''));
        return $text !== '' ? $text : get_string('none');
    }

    /**
     * The readable parts of an element's value.
     *
     * @param \HTML_QuickForm_element $element
     * @param array $post
     * @param string $prefix the enclosing group's name when the group appends its name, else ''
     * @return string[]
     */
    protected static function parts(\HTML_QuickForm_element $element, array $post, string $prefix): array {
        $name = (string) $element->getName();
        $fullname = $prefix !== '' ? $prefix . '[' . $name . ']' : $name;

        if ($element instanceof \MoodleQuickForm_modgrade) {
            return [self::modgrade($element, $post, $fullname)];
        }
        if ($element instanceof \MoodleQuickForm_duration) {
            return [self::duration($element, $post, $fullname)];
        }
        if ($element instanceof \HTML_QuickForm_group) {
            $childprefix = !empty($element->_appendName) ? $fullname : $prefix;
            $parts = [];
            foreach ($element->getElements() as $child) {
                if ($child instanceof \HTML_QuickForm_checkbox && !($child instanceof \HTML_QuickForm_advcheckbox)) {
                    // Inside a group a checkbox is shown by its text when ticked (the quiz review options).
                    $childname = $childprefix !== '' ? $childprefix . '[' . $child->getName() . ']' : $child->getName();
                    if (self::find($post, $childname) !== null) {
                        $parts[] = bulk_form::label_of($child);
                    }
                    continue;
                }
                if (in_array($child->getType(), ['html', 'static', 'header', 'hidden'], true) || $child->getName() === null) {
                    continue;
                }
                $parts = array_merge($parts, self::parts($child, $post, $childprefix));
            }
            return $parts;
        }

        $value = self::find($post, $fullname);
        if ($element instanceof \HTML_QuickForm_advcheckbox) {
            $values = $element->_values ?? [0, 1];
            return [(string) $value === (string) ($values[1] ?? 1) ? get_string('yes') : get_string('no')];
        }
        if ($element instanceof \HTML_QuickForm_checkbox) {
            return [$value !== null ? get_string('yes') : get_string('no')];
        }
        if ($element instanceof \HTML_QuickForm_select) {
            return [self::option_texts($element, $value)];
        }
        if (in_array($element->getType(), ['password', 'passwordunmask'], true)) {
            return [(string) $value === '' ? '' : '********'];
        }
        if (is_array($value)) {
            return [implode(', ', array_map('strval', array_filter($value, 'is_scalar')))];
        }
        return [(string) $value];
    }

    /**
     * The texts of a select's chosen options.
     *
     * @param \HTML_QuickForm_select $select
     * @param mixed $value
     * @return string
     */
    protected static function option_texts(\HTML_QuickForm_select $select, $value): string {
        $wanted = array_map('strval', is_array($value) ? $value : ($value === null ? [] : [$value]));
        $texts = [];
        foreach ($select->_options as $option) {
            $optionvalue = (string) ($option['attr']['value'] ?? '');
            if (in_array($optionvalue, $wanted, true)) {
                $texts[] = trim(html_entity_decode(strip_tags((string) $option['text']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        return $texts ? implode(', ', $texts) : implode(', ', $wanted);
    }

    /**
     * A grade setting (point, scale or none) as text.
     *
     * @param \MoodleQuickForm_modgrade $element
     * @param array $post
     * @param string $name
     * @return string
     */
    protected static function modgrade(\MoodleQuickForm_modgrade $element, array $post, string $name): string {
        $type = (string) self::find($post, $name . '[modgrade_type]');
        foreach ($element->getElements() as $child) {
            $childname = (string) $child->getName();
            if ($type === 'scale' && $childname === 'modgrade_scale' && $child instanceof \HTML_QuickForm_select) {
                return get_string('modgradetypescale', 'grades') . ': ' .
                    self::option_texts($child, self::find($post, $name . '[modgrade_scale]'));
            }
        }
        if ($type === 'point') {
            return get_string('modgradetypepoint', 'grades') . ': ' . (string) self::find($post, $name . '[modgrade_point]');
        }
        return get_string('modgradetypenone', 'grades');
    }

    /**
     * A duration setting as text.
     *
     * @param \MoodleQuickForm_duration $element
     * @param array $post
     * @param string $name
     * @return string
     */
    protected static function duration(\MoodleQuickForm_duration $element, array $post, string $name): string {
        $value = self::find($post, $name);
        if (!is_array($value)) {
            return '';
        }
        $options = (new \ReflectionProperty(\MoodleQuickForm_duration::class, '_options'))->getValue($element);
        if (!empty($options['optional']) && empty($value['enabled'])) {
            return get_string('no');
        }
        $number = unformat_float((string) ($value['number'] ?? '0'));
        $unit = (int) ($value['timeunit'] ?? 1);
        $seconds = (int) round((float) $number * max(1, $unit));
        return $seconds > 0 ? format_time($seconds) : '0';
    }
}
