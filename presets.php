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
 * Site administration: the site-wide admin presets, offered on every course's bulk settings page.
 *
 * Presets are saved from a course's bulk settings page (Save configuration, "Save as an admin
 * preset"), where the activity type's form is available; this page lists and deletes them.
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use tool_bass\local\saved_configs;

admin_externalpage_setup('tool_bass_presets');

$url = new moodle_url('/admin/tool/bass/presets.php');
$deleteid = optional_param('delete', 0, PARAM_INT);

if ($deleteid) {
    $preset = saved_configs::get(saved_configs::PRESETS, $deleteid);
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        saved_configs::delete(saved_configs::PRESETS, $deleteid);
        redirect($url, get_string('presetdeleted', 'tool_bass', s($preset->name)), null, notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('adminpresets', 'tool_bass'));
    echo $OUTPUT->confirm(
        get_string('deletepresetconfirm', 'tool_bass', s($preset->name)),
        new moodle_url($url, ['delete' => $deleteid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('adminpresets', 'tool_bass'));
echo html_writer::tag('p', s(get_string('adminpresetsintro', 'tool_bass')));

$presets = saved_configs::list(saved_configs::PRESETS, null);
if (!$presets) {
    echo $OUTPUT->notification(get_string('nopresets', 'tool_bass'), notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [
        get_string('activitytype', 'tool_bass'),
        get_string('presetname', 'tool_bass'),
        get_string('configsavedon', 'tool_bass'),
        html_writer::span(get_string('actions'), 'visually-hidden'),
    ];
    $table->attributes['class'] = 'generaltable w-auto';
    $pluginman = core_plugin_manager::instance();
    foreach ($presets as $preset) {
        $typename = $pluginman->get_plugin_info('mod_' . $preset->modname)
            ? get_string('modulename', $preset->modname) : $preset->modname;
        $table->data[] = [
            s($typename),
            s($preset->name),
            userdate($preset->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
            html_writer::link(
                new moodle_url($url, ['delete' => $preset->id]),
                get_string('delete') . html_writer::span(' ' . s($preset->name), 'visually-hidden'),
                ['class' => 'btn btn-outline-danger btn-sm']
            ),
        ];
    }
    echo html_writer::table($table);
}
echo $OUTPUT->footer();
