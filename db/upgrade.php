<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_paygw_paddle_upgrade($oldversion) {
    if ($oldversion < 2026072200) {
        upgrade_plugin_savepoint(true, 2026072200, 'paygw', 'paddle');
    }
    return true;
}
