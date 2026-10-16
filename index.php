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
 * Bulk edit the settings of a course's activities of one type.
 *
 * Choose an activity type; the page lists the course's activities of that type and that
 * type's own settings form, with a Change box per setting. Preview plans the ticked settings
 * for the ticked activities and shows what would change; Apply changes plans again, refuses
 * when anything changed since the preview (the fingerprint), saves each activity through its
 * own edit form, and redirects back with a summary (POST-redirect-GET).
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/completionlib.php');

use core\output\notification;
use tool_bass\local\activity_lister;
use tool_bass\local\applier;
use tool_bass\local\apply_result;
use tool_bass\local\bulk_form;
use tool_bass\local\change_request;
use tool_bass\local\planner;
use tool_bass\local\saved_configs;
use tool_bass\output\activitytable;
use tool_bass\output\preview;

$courseid = required_param('courseid', PARAM_INT);
$modname = optional_param('modname', '', PARAM_PLUGIN);
$course = get_course($courseid);

require_login($course);
$context = context_course::instance($courseid);
require_capability('tool/bass:manage', $context);

$baseurl = new moodle_url('/admin/tool/bass/index.php', ['courseid' => $courseid]);
$PAGE->set_url($modname !== '' ? new moodle_url($baseurl, ['modname' => $modname]) : $baseurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'tool_bass'));
$PAGE->set_heading($course->fullname);
navigation_node::override_active_url($baseurl);

// Delete a saved configuration of the course: ask first, then delete on the confirmed, sesskey'd request.
$deleteid = optional_param('deleteconfig', 0, PARAM_INT);
if ($deleteid) {
    $config = saved_configs::get($courseid, $deleteid);
    $back = new moodle_url($baseurl, ['modname' => $config->modname]);
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        saved_configs::delete($courseid, $deleteid);
        redirect($back, get_string('configdeleted', 'tool_bass', s($config->name)), null, notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('savedconfigs', 'tool_bass'));
    echo $OUTPUT->confirm(
        get_string('deleteconfigconfirm', 'tool_bass', s($config->name)),
        new moodle_url($baseurl, ['deleteconfig' => $deleteid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $back
    );
    echo $OUTPUT->footer();
    exit;
}

// The activity types this user may bulk edit here; a posted type is only ever used through this list.
$types = activity_lister::get_types($courseid);
if (!isset($types[$modname])) {
    $modname = '';
}
$typemenu = [];
foreach ($types as $type => $info) {
    $typemenu[$type] = get_string('typewithcount', 'tool_bass', (object) ['name' => $info['name'], 'count' => $info['count']]);
}
$picker = new single_select($baseurl, 'modname', $typemenu, $modname, ['' => 'choosedots'], 'tool_bass_type');
$picker->set_label(get_string('activitytype', 'tool_bass'));

if ($modname === '') {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'tool_bass'));
    echo html_writer::tag('p', s(get_string('intro', 'tool_bass')));
    if ($types) {
        echo $OUTPUT->render($picker);
        echo html_writer::tag('p', s(get_string('choosetype', 'tool_bass')), ['class' => 'mt-3']);
    } else {
        echo $OUTPUT->notification(get_string('noactivities', 'tool_bass'), notification::NOTIFY_INFO);
    }
    echo $OUTPUT->footer();
    exit;
}

$url = new moodle_url($baseurl, ['modname' => $modname]);
$PAGE->set_url($url);
// Every request re-derives the activities this user may edit; posted ids are only ever filtered through it.
$eligible = activity_lister::get_eligible($courseid, $modname);

$submitted = (bool) optional_param(bulk_form::MARKER, 0, PARAM_BOOL);
$post = null;
if ($submitted) {
    require_sesskey();
    $post = (array) data_submitted();
}
$postedcmids = optional_param_array('cmids', [], PARAM_INT);

// Load a course configuration or an admin preset (a sesskey'd link): it only fills the form.
$loadid = optional_param('loadconfig', 0, PARAM_INT);
$loadpresetid = optional_param('loadpreset', 0, PARAM_INT);
if (($loadid || $loadpresetid) && !$submitted) {
    require_sesskey();
    $config = $loadid ? saved_configs::get($courseid, $loadid) : saved_configs::get(saved_configs::PRESETS, $loadpresetid);
    if ($config->modname !== $modname) {
        throw new moodle_exception('error_configtype', 'tool_bass');
    }
    // Only to read which settings the type offers: its JavaScript must not reach the page.
    $template = \tool_bass\local\module_form::without_page_js(fn() => bulk_form::for_first($eligible, $url, null));
    if ($template) {
        [$post, $configcmids] = saved_configs::to_post($config, $template);
        if ($loadid) {
            $postedcmids = $configcmids;
        }
        \core\notification::info(get_string($loadid ? 'configloaded' : 'presetloaded', 'tool_bass', s($config->name)));
    }
}

$form = $eligible ? bulk_form::for_first($eligible, $url, $post) : null;
$selected = activity_lister::filter_cmids($postedcmids, $eligible);
$canpreset = has_capability('moodle/site:config', context_system::instance());

$previewdata = null;
if ($form && $submitted) {
    $request = change_request::from_form($form);
    if (optional_param('saveconfig', '', PARAM_RAW) !== '') {
        // Save configuration: the ticked settings and activities; nothing is written to any activity.
        $name = saved_configs::clean_name(optional_param('configname', '', PARAM_TEXT));
        $aspreset = $canpreset && optional_param('aspreset', 0, PARAM_BOOL);
        if ($name === '') {
            $form->set_error('configname', get_string('error_configname', 'tool_bass'));
        } else if ($request->is_empty()) {
            \core\notification::error(get_string('error_nosettings', 'tool_bass'));
        } else {
            $owner = $aspreset ? saved_configs::PRESETS : $courseid;
            $replaced = saved_configs::exists($owner, $modname, $name);
            $id = saved_configs::save($owner, $modname, $name, saved_configs::snapshot($request, $aspreset ? [] : $selected));
            $strings = $aspreset ? ['presetreplaced', 'presetsaved'] : ['configreplaced', 'configsaved'];
            redirect(
                new moodle_url($url, [$aspreset ? 'loadpreset' : 'loadconfig' => $id, 'sesskey' => sesskey()]),
                get_string($replaced ? $strings[0] : $strings[1], 'tool_bass', s($name)),
                null,
                notification::NOTIFY_SUCCESS
            );
        }
    } else if (!$selected) {
        \core\notification::error(get_string('error_noactivities', 'tool_bass'));
    } else if ($request->is_empty()) {
        \core\notification::error(get_string('error_nosettings', 'tool_bass'));
    } else {
        $planner = new planner();
        $plans = $planner->plan($courseid, $postedcmids, $request, $form);
        $fingerprint = planner::fingerprint($request, $plans);
        $preview = new preview($plans);
        if ($planner->get_rejected_cmids()) {
            \core\notification::warning(get_string('rejectedcmids', 'tool_bass', count($planner->get_rejected_cmids())));
        }

        // The button is read with PARAM_RAW: PARAM_INT would clean its label to 0.
        if (optional_param('apply', '', PARAM_RAW) !== '') {
            $postedfingerprint = optional_param('fingerprint', '', PARAM_ALPHANUM);
            if (!hash_equals($fingerprint, $postedfingerprint)) {
                \core\notification::warning(get_string('previewchanged', 'tool_bass'));
            } else if ($preview->needs_confirmation() && !optional_param('confirmreset', 0, PARAM_BOOL)) {
                \core\notification::error(get_string('error_confirmreset', 'tool_bass'));
            } else {
                $results = (new applier())->apply($courseid, $plans);
                $counts = [
                    apply_result::APPLIED => 0,
                    apply_result::NOCHANGE => 0,
                    apply_result::SKIPPED => 0,
                    apply_result::FAILED => 0,
                ];
                foreach ($results as $result) {
                    $counts[$result->status]++;
                    if ($result->status === apply_result::APPLIED || $result->status === apply_result::NOCHANGE) {
                        continue;
                    }
                    [$name] = activitytable::activity_name($eligible[$result->cmid]->cm);
                    $a = (object) ['name' => $name, 'messages' => s(implode(' ', $result->messages))];
                    if ($result->status === apply_result::FAILED) {
                        \core\notification::error(get_string('resultfailed', 'tool_bass', $a));
                    } else {
                        \core\notification::warning(get_string('resultskipped', 'tool_bass', $a));
                    }
                }
                $summary = get_string('resultsummary', 'tool_bass', (object) $counts);
                $type = notification::NOTIFY_SUCCESS;
                if ($counts[apply_result::FAILED] || $counts[apply_result::SKIPPED]) {
                    $type = notification::NOTIFY_WARNING;
                } else if (!$counts[apply_result::APPLIED]) {
                    $type = notification::NOTIFY_INFO;
                }
                redirect($url, $summary, null, $type);
            }
        }
        $previewdata = [$preview, $fingerprint];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_bass'));
echo html_writer::tag('p', s(get_string('intro', 'tool_bass')));
echo $OUTPUT->render($picker);

if (!$eligible || !$form) {
    echo $OUTPUT->notification(get_string($eligible ? 'error_typeform' : 'noactivities', 'tool_bass'), notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$showcompletion = (bool) (new completion_info($course))->is_enabled();
$table = new activitytable($eligible, $selected, $showcompletion, bulk_form::FORM_ID);
echo $OUTPUT->render_from_template('tool_bass/activitytable', $table->export_for_template($OUTPUT));

$configlist = function (int $owner, string $param, bool $deletable) use ($url, $baseurl, $modname): array {
    $configs = [];
    foreach (saved_configs::list($owner, $modname) as $config) {
        $configs[] = [
            'name' => $config->name,
            'timemodified' => userdate($config->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
            'loadurl' => (new moodle_url($url, [$param => $config->id, 'sesskey' => sesskey()]))->out(false),
            'deleteurl' => $deletable ? (new moodle_url($baseurl, ['deleteconfig' => $config->id]))->out(false) : null,
        ];
    }
    return $configs;
};
$courseconfigs = $configlist($courseid, 'loadconfig', true);
$presets = $configlist(saved_configs::PRESETS, 'loadpreset', false);
echo $OUTPUT->render_from_template('tool_bass/savedconfigs', [
    'configs' => $courseconfigs,
    'hasconfigs' => (bool) $courseconfigs,
    'presets' => $presets,
    'haspresets' => (bool) $presets,
]);

[$representativename] = activitytable::activity_name($form->get_representative());
echo html_writer::tag('h3', s(get_string('settingsheading', 'tool_bass')));
echo html_writer::tag('p', $form->is_new()
    ? get_string('representativenew', 'tool_bass', \core_text::strtolower(get_string('modulename', $form->get_modname())))
    : get_string('representative', 'tool_bass', $representativename));

if ($previewdata) {
    [$preview, $fingerprint] = $previewdata;
    $form->add_preview(
        $OUTPUT->render_from_template('tool_bass/preview', $preview->export_for_template($OUTPUT)),
        $preview->needs_confirmation(),
        $fingerprint
    );
}
$form->add_buttons($previewdata && $previewdata[0]->can_apply(), $canpreset);

$PAGE->requires->js_call_amd('tool_bass/activitytable', 'init', [bulk_form::FORM_ID]);
echo $form->render();
echo $OUTPUT->footer();
