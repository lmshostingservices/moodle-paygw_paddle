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
 * Upgrade steps for the Paddle payment gateway plugin.
 *
 * Steps carry no descriptive prose: what changed in each release is
 * recorded in CHANGELOG.md.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Run the paygw_paddle upgrade steps.
 *
 * @param int $oldversion The version currently installed on this site.
 * @return bool
 */
function xmldb_paygw_paddle_upgrade($oldversion) {

    // Savepoints up to 1.0.32. None of these changed the database schema;
    // they exist so that sites upgrading from an older release follow a
    // continuous savepoint chain.
    $nochangesavepoints = [
        2026033014,
        2026033015,
        2026040416,
        2026041617,
        2026041700,
        2026042900,
        2026050100,
        2026050600,
        2026050700,
        2026051200,
        2026060400,
        2026072100,
        2026072200,
    ];

    foreach ($nochangesavepoints as $savepoint) {
        if ($oldversion < $savepoint) {
            upgrade_plugin_savepoint(true, $savepoint, 'paygw', 'paddle');
        }
    }

    // 1.0.33 and 1.0.34. No schema changes: these releases rework PHP,
    // JavaScript, the privacy provider and coding style only.
    if ($oldversion < 2026082901) {
        upgrade_plugin_savepoint(true, 2026082901, 'paygw', 'paddle');
    }

    // 1.0.35. No schema changes: JavaScript only.
    if ($oldversion < 2026090100) {
        upgrade_plugin_savepoint(true, 2026090100, 'paygw', 'paddle');
    }

    // 1.0.36. No schema changes: version reissue only.
    if ($oldversion < 2026090101) {
        upgrade_plugin_savepoint(true, 2026090101, 'paygw', 'paddle');
    }

    // 1.0.37. No schema changes: JavaScript spacing only.
    if ($oldversion < 2026090102) {
        upgrade_plugin_savepoint(true, 2026090102, 'paygw', 'paddle');
    }

    return true;
}
