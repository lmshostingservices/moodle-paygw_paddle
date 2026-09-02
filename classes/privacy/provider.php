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
 * Payment rows are financial records, so a deletion request anonymises them
 * (clearing the link to the Moodle user and stripping the stored webhook
 * payload) rather than removing the row, which sites are generally required
 * to retain for tax and audit purposes.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_paddle\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the Paddle payment gateway.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\core_user_data_provider {
    /**
     * Describe the personal data this plugin stores and transmits.
     *
     * @param collection $collection The initialised collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $transactionfields = [
            'userid' => 'privacy:metadata:paygw_paddle_transactions:userid',
            'component' => 'privacy:metadata:paygw_paddle_transactions:component',
            'paymentarea' => 'privacy:metadata:paygw_paddle_transactions:paymentarea',
            'itemid' => 'privacy:metadata:paygw_paddle_transactions:itemid',
            'paddle_transaction_id' => 'privacy:metadata:paygw_paddle_transactions:paddle_transaction_id',
            'paddle_customer_id' => 'privacy:metadata:paygw_paddle_transactions:paddle_customer_id',
            'amount' => 'privacy:metadata:paygw_paddle_transactions:amount',
            'currency' => 'privacy:metadata:paygw_paddle_transactions:currency',
            'tax' => 'privacy:metadata:paygw_paddle_transactions:tax',
            'status' => 'privacy:metadata:paygw_paddle_transactions:status',
            'timecreated' => 'privacy:metadata:paygw_paddle_transactions:timecreated',
            'timemodified' => 'privacy:metadata:paygw_paddle_transactions:timemodified',
        ];
        $collection->add_database_table(
            'paygw_paddle_transactions',
            $transactionfields,
            'privacy:metadata:paygw_paddle_transactions'
        );

        // The webhook audit trail keeps the notification body Paddle sent, which
        // carries the payer's name, email address and billing address.
        $eventfields = [
            'paddle_event_id' => 'privacy:metadata:paygw_paddle_events:paddle_event_id',
            'paddle_transaction_id' => 'privacy:metadata:paygw_paddle_events:paddle_transaction_id',
            'event_type' => 'privacy:metadata:paygw_paddle_events:event_type',
            'result' => 'privacy:metadata:paygw_paddle_events:result',
            'error_message' => 'privacy:metadata:paygw_paddle_events:error_message',
            'raw_payload' => 'privacy:metadata:paygw_paddle_events:raw_payload',
            'timecreated' => 'privacy:metadata:paygw_paddle_events:timecreated',
        ];
        $collection->add_database_table(
            'paygw_paddle_events',
            $eventfields,
            'privacy:metadata:paygw_paddle_events'
        );

        // Billing details are sent to Paddle, which acts as Merchant of Record.
        $paddlefields = [
            'name' => 'privacy:metadata:paddle:name',
            'email' => 'privacy:metadata:paddle:email',
            'address' => 'privacy:metadata:paddle:address',
            'city' => 'privacy:metadata:paddle:city',
            'postcode' => 'privacy:metadata:paddle:postcode',
            'country' => 'privacy:metadata:paddle:country',
            'business' => 'privacy:metadata:paddle:business',
            'userid' => 'privacy:metadata:paddle:userid',
        ];
        $collection->add_external_location_link(
            'paddle',
            $paddlefields,
            'privacy:metadata:paddle'
        );

        return $collection;
    }

    /**
     * Return the contexts holding data for a user.
     *
     * Payments are recorded against the system context, and only for users who
     * have actually paid.
     *
     * @param int $userid The user to search for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if ($DB->record_exists('paygw_paddle_transactions', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Return the users who have data in the given context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            "SELECT DISTINCT userid FROM {paygw_paddle_transactions} WHERE userid > 0",
            []
        );
    }

    /**
     * Export all Paddle data held for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $hassystemcontext = false;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                $hassystemcontext = true;
                break;
            }
        }
        if (!$hassystemcontext) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $records = $DB->get_records('paygw_paddle_transactions', ['userid' => $userid], 'timecreated ASC');
        if (empty($records)) {
            return;
        }

        $transactions = [];
        $txnids = [];
        foreach ($records as $record) {
            $txnids[] = $record->paddle_transaction_id;
            $transactions[] = (object) [
                'paddle_transaction_id' => $record->paddle_transaction_id,
                'paddle_customer_id' => $record->paddle_customer_id,
                'component' => $record->component,
                'paymentarea' => $record->paymentarea,
                'itemid' => $record->itemid,
                'amount' => format_float((float) $record->amount, 2),
                'currency' => $record->currency,
                'tax' => format_float((float) $record->tax, 2),
                'status' => $record->status,
                'timecreated' => transform::datetime($record->timecreated),
                'timemodified' => transform::datetime($record->timemodified),
            ];
        }

        $context = \context_system::instance();
        $rootpath = [get_string('pluginname', 'paygw_paddle')];

        writer::with_context($context)->export_data(
            array_merge($rootpath, [get_string('privacy:path:transactions', 'paygw_paddle')]),
            (object) ['transactions' => $transactions]
        );

        // Export the webhook notifications tied to those transactions.
        [$insql, $params] = $DB->get_in_or_equal($txnids, SQL_PARAMS_NAMED);
        $eventrecords = $DB->get_records_select(
            'paygw_paddle_events',
            "paddle_transaction_id {$insql}",
            $params,
            'timecreated ASC'
        );
        if (empty($eventrecords)) {
            return;
        }

        $events = [];
        foreach ($eventrecords as $event) {
            $events[] = (object) [
                'paddle_event_id' => $event->paddle_event_id,
                'paddle_transaction_id' => $event->paddle_transaction_id,
                'event_type' => $event->event_type,
                'result' => $event->result,
                'error_message' => $event->error_message,
                'raw_payload' => $event->raw_payload,
                'timecreated' => transform::datetime($event->timecreated),
            ];
        }

        writer::with_context($context)->export_data(
            array_merge($rootpath, [get_string('privacy:path:events', 'paygw_paddle')]),
            (object) ['events' => $events]
        );
    }

    /**
     * Anonymise every payment record in the given context.
     *
     * @param \context $context The context to purge.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_system) {
            return;
        }

        // Financial records are retained; the personal data in them is removed.
        $DB->set_field_select('paygw_paddle_transactions', 'userid', 0, 'userid > 0', []);
        $DB->set_field_select('paygw_paddle_transactions', 'paddle_customer_id', null, '1 = 1', []);
        $DB->set_field_select('paygw_paddle_events', 'raw_payload', null, '1 = 1', []);
    }

    /**
     * Anonymise the payment records belonging to one user.
     *
     * @param approved_contextlist $contextlist The approved contexts and user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                self::anonymise_users([$userid]);
                return;
            }
        }
    }

    /**
     * Anonymise the payment records belonging to a list of users.
     *
     * @param approved_userlist $userlist The approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_system) {
            return;
        }
        self::anonymise_users($userlist->get_userids());
    }

    /**
     * Clear the user link and stored payloads for the given users.
     *
     * @param int[] $userids The users to anonymise.
     * @return void
     */
    protected static function anonymise_users(array $userids): void {
        global $DB;

        $userids = array_filter(array_map('intval', $userids));
        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Strip the stored webhook bodies for these users first: once the
        // transactions are anonymised the link back to them is gone.
        $txnids = $DB->get_fieldset_select(
            'paygw_paddle_transactions',
            'paddle_transaction_id',
            "userid {$insql}",
            $params
        );
        if (!empty($txnids)) {
            [$txnsql, $txnparams] = $DB->get_in_or_equal($txnids, SQL_PARAMS_NAMED);
            $DB->set_field_select(
                'paygw_paddle_events',
                'raw_payload',
                null,
                "paddle_transaction_id {$txnsql}",
                $txnparams
            );
        }

        $DB->set_field_select(
            'paygw_paddle_transactions',
            'paddle_customer_id',
            null,
            "userid {$insql}",
            $params
        );
        $DB->set_field_select('paygw_paddle_transactions', 'userid', 0, "userid {$insql}", $params);
    }
}
