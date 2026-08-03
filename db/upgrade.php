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

    if ($oldversion < 2026072600) {
        $table = new xmldb_table('kopfuebung_labels');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('position', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('coursefk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_index('course_position', XMLDB_INDEX_UNIQUE, ['courseid', 'position']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026072600, 'kopfuebung');
    }

    if ($oldversion < 2026073002) {
        $table = new xmldb_table('kopfuebung_ready');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('kopfuebungid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('kopfuebungfk', XMLDB_KEY_FOREIGN, ['kopfuebungid'], 'kopfuebung', ['id']);
        $table->add_key('userfk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('kopfuebung_user', XMLDB_INDEX_UNIQUE, ['kopfuebungid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026073002, 'kopfuebung');
    }

    if ($oldversion < 2026080201) {
        $gridtable = new xmldb_table('kopfuebung_labelgrids');
        $gridtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $gridtable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $gridtable->add_field('startcmid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $gridtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $gridtable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $gridtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $gridtable->add_key('coursefk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $gridtable->add_index('course_startcm', XMLDB_INDEX_UNIQUE, ['courseid', 'startcmid']);

        if (!$dbman->table_exists($gridtable)) {
            $dbman->create_table($gridtable);
        }

        $labeltable = new xmldb_table('kopfuebung_labels');
        $gridfield = new xmldb_field(
            'gridid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'courseid'
        );
        if (!$dbman->field_exists($labeltable, $gridfield)) {
            $dbman->add_field($labeltable, $gridfield);
        }

        $oldindex = new xmldb_index('course_position', XMLDB_INDEX_UNIQUE, ['courseid', 'position']);
        if ($dbman->index_exists($labeltable, $oldindex)) {
            $dbman->drop_index($labeltable, $oldindex);
        }
        $newindex = new xmldb_index(
            'course_grid_position',
            XMLDB_INDEX_UNIQUE,
            ['courseid', 'gridid', 'position']
        );
        if (!$dbman->index_exists($labeltable, $newindex)) {
            $dbman->add_index($labeltable, $newindex);
        }

        upgrade_mod_savepoint(true, 2026080201, 'kopfuebung');
    }

    if ($oldversion < 2026080300) {
        $table = new xmldb_table('kopfuebung');
        $field = new xmldb_field(
            'questioncount', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '10', 'timelimit'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026080300, 'kopfuebung');
    }

    if ($oldversion < 2026080301) {
        $table = new xmldb_table('kopfuebung_feedback');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('authorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('message', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $table->add_field('messageformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('coursefk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('authorfk', XMLDB_KEY_FOREIGN, ['authorid'], 'user', ['id']);
        $table->add_index('course_recipient', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026080301, 'kopfuebung');
    }

    return true;
}
