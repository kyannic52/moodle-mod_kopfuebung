<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_kopfuebung\privacy;

defined('MOODLE_INTERNAL') || die();

class provider implements \core_privacy\local\metadata\provider {
    public static function get_metadata(\core_privacy\local\metadata\collection $collection): \core_privacy\local\metadata\collection {
        $collection->add_database_table(
            'kopfuebung_attempts',
            [
                'userid' => 'privacy:metadata:kopfuebung_attempts:userid',
                'timestarted' => 'privacy:metadata:kopfuebung_attempts:timestarted',
                'timefinished' => 'privacy:metadata:kopfuebung_attempts:timefinished',
                'status' => 'privacy:metadata:kopfuebung_attempts:status',
            ],
            'privacy:metadata:kopfuebung_attempts'
        );

        $collection->add_database_table(
            'kopfuebung_answers',
            [
                'answertext' => 'privacy:metadata:kopfuebung_answers:answertext',
                'answeredtime' => 'privacy:metadata:kopfuebung_answers:answeredtime',
            ],
            'privacy:metadata:kopfuebung_answers'
        );

        $collection->add_database_table(
            'kopfuebung_ready',
            [
                'userid' => 'privacy:metadata:kopfuebung_ready:userid',
                'timecreated' => 'privacy:metadata:kopfuebung_ready:timecreated',
            ],
            'privacy:metadata:kopfuebung_ready'
        );

        $collection->add_database_table(
            'kopfuebung_feedback',
            [
                'userid' => 'privacy:metadata:kopfuebung_feedback:userid',
                'authorid' => 'privacy:metadata:kopfuebung_feedback:authorid',
                'message' => 'privacy:metadata:kopfuebung_feedback:message',
                'timecreated' => 'privacy:metadata:kopfuebung_feedback:timecreated',
            ],
            'privacy:metadata:kopfuebung_feedback'
        );

        $collection->add_database_table(
            'kopfuebung_offers',
            [
                'hint' => 'privacy:metadata:kopfuebung_offers:hint',
                'explanationtarget' => 'privacy:metadata:kopfuebung_offers:explanationtarget',
                'practicetarget' => 'privacy:metadata:kopfuebung_offers:practicetarget',
            ],
            'privacy:metadata:kopfuebung_offers'
        );

        $collection->add_database_table(
            'kopfuebung_offer_users',
            ['userid' => 'privacy:metadata:kopfuebung_offer_users:userid'],
            'privacy:metadata:kopfuebung_offer_users'
        );

        return $collection;
    }
}
