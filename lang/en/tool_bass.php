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
 * English language strings for tool_bass.
 *
 * @package    tool_bass
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activitytype'] = 'Activity type';
$string['adminpresets'] = 'Bulk activity settings presets';
$string['adminpresetsintro'] = 'Site-wide presets of activity settings, one activity type each. Every course\'s Bulk Activity Settings Selector page lists the presets of the chosen type under Admin presets; loading one fills in the settings form, and nothing changes until the teacher previews and applies it. To create a preset, save a configuration on a course\'s page with "Save as an admin preset for the whole site" ticked.';
$string['apply'] = 'Apply changes';
$string['bass:manage'] = 'Bulk edit the settings of activities in a course';
$string['changesetting'] = 'Change: {$a}';
$string['choosetype'] = 'Choose the type of activity whose settings you want to change.';
$string['configdeleted'] = 'Configuration "{$a}" deleted.';
$string['configloaded'] = 'Configuration "{$a}" loaded. Preview and apply it to change the activities.';
$string['configname'] = 'Configuration name';
$string['configreplaced'] = 'Configuration "{$a}" replaced.';
$string['configsaved'] = 'Configuration "{$a}" saved.';
$string['configsavedon'] = 'Saved';
$string['confirmneeded'] = 'Applying these changes deletes and recalculates the completion data of the activities marked above. Tick the confirmation below to apply them.';
$string['confirmreset'] = 'Delete and recalculate the completion data of the activities marked above';
$string['confirmresetlabel'] = 'Completion data';
$string['deleteconfigconfirm'] = 'Delete the saved configuration "{$a}"? The activities are not changed.';
$string['deletepresetconfirm'] = 'Delete the admin preset "{$a}"? Activities it was applied to are not changed.';
$string['error_configname'] = 'Enter a name.';
$string['error_configtype'] = 'This configuration belongs to another activity type.';
$string['error_confirmreset'] = 'These changes delete completion data. Tick the confirmation next to "Completion data" to apply them.';
$string['error_form'] = 'The settings form of this activity could not be used: {$a}';
$string['error_frozen'] = 'This setting is locked on this activity (for example, because it already has attempts), so it cannot be changed.';
$string['error_gradelocked'] = 'A grade item of this activity is locked in the gradebook. Unlock it before changing the activity\'s settings here.';
$string['error_maxgrade'] = 'Enter a maximum grade greater than 0 and less than 100000.';
$string['error_noactivities'] = 'Select at least one activity in the table.';
$string['error_nosettings'] = 'Tick "Change" next to at least one setting.';
$string['error_notinform'] = 'This activity does not have this setting, or you may not change it here.';
$string['error_notsaved'] = 'The activity\'s settings form refused the changes: {$a}';
$string['error_percent'] = 'Enter a percentage from 0 to 100 as the grade to pass.';
$string['error_percentnograde'] = 'This activity has no maximum grade in points, so a grade to pass cannot be set as a percentage.';
$string['error_setting'] = '{$a->label}: {$a->message}';
$string['error_toomanyfields'] = 'The activity\'s settings form has more fields ({$a}) than the server accepts in one request (max_input_vars).';
$string['error_typeform'] = 'The settings form of this activity type cannot be used on this page.';
$string['filterbyname'] = 'Filter by name';
$string['gradepasstype'] = 'Grade to pass entered as';
$string['gradepasstype_absolute'] = 'A grade';
$string['gradepasstype_percent'] = 'Percentage of each activity\'s maximum grade';
$string['intro'] = 'Choose an activity type, tick the activities to change, then tick "Change" next to each setting to set on all of them. Settings without a tick keep each activity\'s own value. Preview shows what would change; nothing is saved until you apply the changes.';
$string['load'] = 'Load';
$string['noactivities'] = 'There are no activities in this course whose settings you can edit.';
$string['nofiltermatch'] = 'No activities match the filter.';
$string['nopresets'] = 'There are no presets yet.';
$string['nosavedconfigs'] = 'This course has no saved configurations for this activity type yet. Use Save configuration below the settings to save one.';
$string['nothingtoapply'] = 'None of the selected activities can be changed with these settings.';
$string['pluginname'] = 'Bulk Activity Settings Selector';
$string['presetdeleted'] = 'Preset "{$a}" deleted.';
$string['presetloaded'] = 'Admin preset "{$a}" loaded. Tick the activities, then preview and apply it.';
$string['presetname'] = 'Preset name';
$string['presetreplaced'] = 'Admin preset "{$a}" replaced.';
$string['presetsaved'] = 'Admin preset "{$a}" saved.';
$string['preview'] = 'Preview';
$string['previewchanged'] = 'The settings, the selected activities or the activities themselves changed after the preview. Nothing was saved. Check the new preview and apply again.';
$string['previewheading'] = 'Preview';
$string['previewnew'] = 'New value';
$string['previewnochange'] = 'These settings already have the requested values.';
$string['previewold'] = 'Current value';
$string['previewsetting'] = 'Setting';
$string['previewstale'] = 'The settings or the selected activities changed after this preview. Press Preview again before applying.';
$string['previewsummary'] = 'Activities that will change: {$a->change}. Already as requested: {$a->nochange}. Cannot be changed: {$a->error}.';
$string['privacy:metadata'] = 'The Bulk Activity Settings Selector changes activity settings. Its saved configurations hold settings and activity selections for a course or the site, and no personal data.';
$string['rejectedcmids'] = '{$a} of the selected activities cannot be edited by you and were ignored.';
$string['representative'] = 'The settings below start from the current settings of {$a}. Only the settings with a ticked "Change" box are changed, on every selected activity.';
$string['representativenew'] = 'Every activity of this type in the course has settings that its own data locks (for example a quiz with attempts), so the settings below start from those of a new {$a}. Only the settings with a ticked "Change" box are changed, on every selected activity; the preview shows which activities cannot take a change.';
$string['resultfailed'] = 'Changing {$a->name} failed, so nothing was changed in it: {$a->messages}';
$string['resultskipped'] = '{$a->name} was not changed: {$a->messages}';
$string['resultsummary'] = 'Activities changed: {$a->applied}. Already as requested: {$a->nochange}. Skipped: {$a->skipped}. Failed: {$a->failed}.';
$string['saveaspreset'] = 'Save as an admin preset for the whole site (without the selected activities)';
$string['saveconfig'] = 'Save configuration';
$string['saveconfigheading'] = 'Save configuration';
$string['savedconfigs'] = 'Saved configurations';
$string['selectactivities'] = 'Activities';
$string['selectactivity'] = 'Select {$a}';
$string['selectallshown'] = 'Select all shown activities';
$string['settingsheading'] = 'Settings';
$string['status_change'] = 'Will change';
$string['status_error'] = 'Cannot be changed';
$string['status_nochange'] = 'Already as requested';
$string['typewithcount'] = '{$a->name} ({$a->count})';
$string['warning_completionreset'] = 'Students have completion data for this activity. Changing its completion settings deletes that data and recalculates it.';
$string['warning_rescale'] = 'This quiz has attempts. Changing the maximum grade rescales all existing grades.';
