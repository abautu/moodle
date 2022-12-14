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
 * CLI script allowing to work with presets.
 *
 * This is technically just a thin wrapper for {@link get_config()} and
 * {@link set_config()} functions.
 *
 * @package     core
 * @subpackage  cli
 * @copyright   2022 Andrei Bautu <abautu@gmail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__.'/../../config.php');
require_once($CFG->libdir.'/clilib.php');
require_once($CFG->dirroot . '/backup/util/xml/xml_writer.class.php');
require_once($CFG->dirroot . '/backup/util/xml/output/xml_output.class.php');
require_once($CFG->dirroot . '/backup/util/xml/output/memory_xml_output.class.php');

$usage = "Export-import the current value of the given site setting. Allows to set it to the given value, too.

Usage:
    # php admin_presets.php --export [--name=<presetname>] [--comments=<comments>] [--author=<author>] [--includesensiblesettings]
    # php admin_presets.php --download --id=<presetid> [--file=<filename>]
    # php admin_presets.php --import --file=<filename> [--name=<presetname>]
    # php admin_presets.php --apply --id=<presetid> [--simulate] [--show-applied] [--show-skipped]
    # php admin_presets.php --delete --id=<presetid>
    # php admin_presets.php --list [--id=<presetid>|--name=<presetname>]
    # php admin_presets.php [--help|-h]

Options:
    -h --help                   Print this help.
    --export                    Export current settings as a preset.
    --download                  Download/save the preset to the given file.
    --import                    Import the preset from the given file.
    --apply                     Apply the preset specified by --id.
    --delete                    Delete the preset specified by --name.
    --list                      List information about a specific or all presets.
    --id=<presetid>             The ID of the preset to export/download/apply/delete.
    --name=<presetname>         Name of the preset to import/export.
    --comments=<comments>       Comments to store in the preset.
    --author=<author>           Author name of store in the preset.
    --includesensiblesettings   Include sensitive settings (eg. API keys) in the preset.
    --file=<filename>           Name of the file to import/export.
    --simulate                  Simulate the application of the preset.
    --show-applied              Show the applied settings.
    --show-skipped              Show the skipped settings.

For each operation, the exit code is 0 if succesfull and non-0 otherwise.

Examples:
    # php admin_presets.php --export --name=MyPreset --comments='My comments' --author='My Name'
        Export the current settings as a preset named 'MyPreset' with the given comments and author.

    # php admin_presets.php --download --id=3 --file=MyPreset.xml
        Save the preset with ID 3 to the file 'MyPreset.xml'.

    # php admin_presets.php --import --file=MyPreset.xml --name=MyPreset2
        Import the preset from the file 'MyPreset.xml' and name it 'MyPreset2'.

    # php admin_presets.php --apply --id=4 --simulate
        Simulate the application of the preset with ID 4.

    # php admin_presets.php --delete --id=4
        Delete the preset with ID 4.

    # php admin_presets.php --list
        List information about all presets.

    # php admin_presets.php --list --id=1
        List information about the preset with ID 1.

    # php admin_presets.php --list --name=MyPreset
        List information about the preset named 'MyPreset'.

";

/** @var moodle_database */
global $DB;

// We need an admin account as the export/import presets backend API requires it.
if (!$admin = get_admin()) {
    cli_error('Admin account not found');
}
$USER = $admin;

list($options, $unrecognised) = cli_get_params([
    'export' => false,
    'download' => false,
    'import' => false,
    'apply' => false,
    'delete' => false,
    'list' => false,
    'help' => false,
    'name' => null,
    'comments' => null,
    'author' => null,
    'includesensiblesettings' => false,
    'file' => null,
    'id' => null,
    'simulate' => false,
    'show-applied' => false,
    'show-skipped' => false,
], [
    'h' => 'help',
]);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL.'  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
}

$manager = new core_adminpresets\manager();

if ($options['list']) {
    $filters = [];
    if ($options['name']) {
        $filters['name'] = $options['name'];
    }
    if ($options['id']) {
        $filters['id'] = $options['id'];
    }
    $presets = $DB->get_records('adminpresets', $filters, 'id');
    if (empty($presets)) {
        cli_writeln('No presets found.');
        exit(1);
    }
    foreach ($presets as $preset) {
        cli_writeln($preset->id. "\t". $preset->name);
    }
    exit(0);
}

if ($options['export']) {
    $data = (object) [
        'name' => $options['name'],
        'comments' => ['text' => $options['comments']],
        'author' => $options['author'],
        'includesensiblesettings' => $options['includesensiblesettings'],
    ];
    list($presetid) = $manager->export_preset($data);
    if (!$presetid) {
        cli_error('Preset export failed.', 1);
    }
    cli_writeln($presetid . "\t" . $options['name']);
    exit(0);
}

if ($options['download']) {
    if (!$options['id']) {
        cli_error('Missing id parameters.', 1);
    }
    $preset = $DB->get_record('adminpresets', ['id' => $options['id']]);
    if (!$preset) {
        cli_error('Preset not found.', 2);
    }
    list($xmlstr) = $manager->download_preset($preset->id);
    if ($options['file']) {
        file_put_contents($options['file'], $xmlstr);
    } else {
        echo $xmlstr;
    }
    exit(0);
}

if ($options['import']) {
    if (!$options['file']) {
        cli_error('File not specified.', 1);
    }
    if (!file_exists($options['file'])) {
        cli_error('File not found.', 2);
    }
    $xmlstr = file_get_contents($options['file']);
    list(, $preset,,) = $manager->import_preset($xmlstr, $options['name']);
    if (!$preset->id) {
        cli_error('Preset import failed.', 3);
    }
    cli_writeln($preset->id . "\t" . $preset->name);
    exit(0);
}

if ($options['apply']) {
    if (!$options['id']) {
        cli_error('Missing id parameters.', 1);
    }
    $preset = $DB->get_record('adminpresets', ['id' => $options['id']]);
    if (!$preset) {
        cli_error('Preset not found.', 2);
    }
    list($applied, $skipped) = $manager->apply_preset($preset->id, $options['simulate']);
    cli_writeln($preset->id . "\t" . $preset->name);
    if ($options['show-applied'] || $options['show-skipped']) {
        cli_writeln("Status\tPlugin\tSetting\tNew value\tOld value");
    }
    if ($options['show-applied']) {
        foreach($applied as $i) {
            cli_writeln("Applied\t$i[plugin]\t$i[visiblename]\t$i[visiblevalue]\t$i[oldvisiblevalue]");
        }
    }
    if ($options['show-skipped']) {
        foreach ($skipped as $i) {
            cli_writeln("Skipped\t$i[plugin]\t$i[visiblename]\t$i[visiblevalue]\t");
        }
    }
    exit(0);
}

if ($options['delete']) {
    if (!$options['id']) {
        cli_error('Missing id parameters.', 1);
    }
    $preset = $DB->get_record('adminpresets', ['id' => $options['id']]);
    if (!$preset) {
        cli_error('Preset not found.', 2);
    }
    list($applied, $skipped) = $manager->delete_preset($preset->id);
    exit(0);
}


// fallback or no command will display help
cli_writeln($usage);