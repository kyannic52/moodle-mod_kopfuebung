<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for mod_kopfuebung.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_kopfuebung_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026052400) {
        $table = new xmldb_table('kopfuebung_attempts');
        $field = new xmldb_field('questionusageid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'userid');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026052400, 'kopfuebung');
    }

    return true;
}
