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

/**
 * Reading a rendered form as a browser posts it, and comparing and showing POST values.
 *
 * @package    tool_bass
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(module_form::class)]
#[CoversClass(post_values::class)]
final class module_form_test extends \advanced_testcase {
    /**
     * Controls are read as a browser posts them.
     */
    public function test_parse_post(): void {
        $html = '<form>
            <input type="text" name="name" value="A &amp; B">
            <input type="hidden" name="grade[modgrade_type]" value="point">
            <input name="grade[modgrade_point]" value="10">
            <input type="checkbox" name="ticked" value="1" checked>
            <input type="checkbox" name="unticked" value="1">
            <input type="checkbox" name="noval" checked>
            <input type="radio" name="r" value="a"><input type="radio" name="r" value="b" checked>
            <input type="text" name="off" value="x" disabled>
            <fieldset disabled><input name="infieldset" value="x"></fieldset>
            <input type="submit" name="submitbutton" value="Save">
            <input type="file" name="upload">
            <select name="first"><option value="1">One</option><option value="2">Two</option></select>
            <select name="chosen"><option value="1">One</option><option value="2" selected>Two</option></select>
            <select name="multi[]" multiple><option value="a" selected>A</option><option value="b">B</option>
                <option value="c" selected>C</option></select>
            <select name="skipdisabled"><option value="1" disabled>One</option><option value="2">Two</option></select>
            <textarea name="text">Line 1&#10;Line 2 &lt;b&gt;</textarea>
            <input type="text" name="unicode" value="日本語">
        </form>';
        $post = module_form::parse_post($html);
        $this->assertSame([
            'name' => 'A & B',
            'grade' => ['modgrade_type' => 'point', 'modgrade_point' => '10'],
            'ticked' => '1',
            'noval' => 'on',
            'r' => 'b',
            'first' => '1',
            'chosen' => '2',
            'multi' => ['a', 'c'],
            'skipdisabled' => '2',
            'text' => "Line 1\nLine 2 <b>",
            'unicode' => '日本語',
        ], $post);
    }

    /**
     * Fields whose disabledIf() or hideIf() condition holds are not posted, as with the edit page's JavaScript.
     */
    public function test_parse_post_applies_dependencies(): void {
        $html = '<form>
            <input type="hidden" name="usegrade" value="0"><input type="checkbox" name="usegrade" value="1">
            <select name="itemnumber"><option value="0">Rating</option><option value="1">Whole forum</option></select>
            <input type="radio" name="completion" value="0"><input type="radio" name="completion" value="2" checked>
            <input name="view" value="1">
            <select name="type"><option value="none" selected>None</option><option value="point">Point</option></select>
            <input name="grade[modgrade_point]" value="100">
            <input name="kept" value="x">
            <input name="onlyhidden" value="y">
        </form>';
        $dependencies = [
            // As hideIf on itemnumber when usegrade is not checked: hidden AND disabled.
            'usegrade' => ['notchecked' => ['' => [1 => ['itemnumber']]]],
            // As disabledIf on view when completion is not 2: the checked radio is 2, so it stays.
            'completion' => ['neq' => ['2' => [0 => ['view']]]],
            // As hideIf on grade when type is none: the group's members go with it.
            'type' => ['eq' => ['none' => [1 => ['grade']]], 'hide' => ['' => [1 => ['onlyhidden']]]],
        ];
        $post = module_form::parse_post($html, $dependencies);
        $this->assertSame(['usegrade' => '0', 'completion' => '2', 'view' => '1', 'type' => 'none', 'kept' => 'x',
            'onlyhidden' => 'y'], $post);
    }

    /**
     * The request is restored after an isolated callback, even when it throws.
     */
    public function test_isolated_restores_the_request(): void {
        $_POST = ['a' => 1];
        $_GET = ['b' => 2];
        $seen = module_form::isolated(['c' => 3], fn() => [$_POST, $_GET, $_REQUEST]);
        $this->assertSame([['c' => 3], [], ['c' => 3]], $seen);
        $this->assertSame(['a' => 1], $_POST);
        try {
            module_form::isolated([], function () {
                throw new \coding_exception('x');
            });
        } catch (\coding_exception $e) {
            $this->assertSame(['b' => 2], $_GET);
        }
        $_POST = $_GET = $_REQUEST = [];
    }

    /**
     * POST values compare by value, overlay by root, and find nested names.
     */
    public function test_post_values(): void {
        $this->assertTrue(post_values::same(['g' => ['b' => '10.00', 'a' => 'x']], ['g' => ['a' => 'x', 'b' => '10']]));
        $this->assertFalse(post_values::same(['a' => '1'], ['a' => '2']));
        $this->assertFalse(post_values::same(['a' => '1'], []));
        $this->assertSame(['keep' => 1, 'b' => 'new'], post_values::overlay(
            ['keep' => 1, 'a' => 'old', 'b' => 'old'],
            ['a', 'b'],
            ['b' => 'new']
        ));
        $this->assertSame('point', post_values::find(['grade' => ['modgrade_type' => 'point']], 'grade[modgrade_type]'));
        $this->assertNull(post_values::find(['grade' => 'x'], 'grade[modgrade_type]'));
        $this->assertSame(['x'], post_values::find(['m' => ['x']], 'm[]'));
    }
}
