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
 * Privacy API implementation for paygw_paddle.
 *
 * @package    paygw_paddle
 * @copyright  2025 CB Plugins
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_paddle\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\context;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_user_data_provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('paygw_paddle_transactions', [
            'userid'               => 'privacy:metadata:paygw_paddle_transactions:userid',
            'component'            => 'privacy:metadata:paygw_paddle_transactions:component',
            'paymentarea'          => 'privacy:metadata:paygw_paddle_transactions:paymentarea',
            'itemid'               => 'privacy:metadata:paygw_paddle_transactions:itemid',
            'paddle_transaction_id'=> 'privacy:metadata:paygw_paddle_transactions:paddle_transaction_id',
            'paddle_customer_id'   => 'privacy:metadata:paygw_paddle_transactions:paddle_customer_id',
            'amount'               => 'privacy:metadata:paygw_paddle_transactions:amount',
            'currency'             => 'privacy:metadata:paygw_paddle_transactions:currency',
            'tax'                  => 'privacy:metadata:paygw_paddle_transactions:tax',
            'status'               => 'privacy:metadata:paygw_paddle_transactions:status',
            'timecreated'          => 'privacy:metadata:paygw_paddle_transactions:timecreated',
            'timemodified'         => 'privacy:metadata:paygw_paddle_transactions:timemodified',
        ], 'privacy:metadata:paygw_paddle_transactions');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        // Payment transactions are associated with the system context.
        $contextlist->add_system_context();
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql('userid',
            "SELECT DISTINCT userid FROM {paygw_paddle_transactions}",
            []);
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $records = $DB->get_records('paygw_paddle_transactions', ['userid' => $userid], 'timecreated ASC');
        if (empty($records)) {
            return;
        }
        $transactions = [];
        foreach ($records as $r) {
            $transactions[] = (object)[
                'paddle_transaction_id' => $r->paddle_transaction_id,
                'paddle_customer_id'    => $r->paddle_customer_id,
                'component'             => $r->component,
                'paymentarea'           => $r->paymentarea,
                'amount'                => number_format((float)$r->amount, 2),
                'currency'              => $r->currency,
                'tax'                   => number_format((float)$r->tax, 2),
                'status'                => $r->status,
                'timecreated'           => transform::datetime($r->timecreated),
                'timemodified'          => transform::datetime($r->timemodified),
            ];
        }
        writer::with_context(\context_system::instance())
            ->export_data([get_string('pluginname', 'paygw_paddle'), 'transactions'], (object)['transactions' => $transactions]);
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        // Payment records are retained for legal and financial audit purposes;
        // anonymise the userid rather than delete the transaction row.
        if (!$context instanceof \context_system) {
            return;
        }
        $DB->set_field('paygw_paddle_transactions', 'userid', 0, []);
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        // Anonymise: retain the transaction record but clear the user link.
        $DB->set_field('paygw_paddle_transactions', 'userid', 0,
            ['userid' => $contextlist->get_user()->id]);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $DB->execute("UPDATE {paygw_paddle_transactions} SET userid = 0 WHERE userid $insql", $params);
    }
}
