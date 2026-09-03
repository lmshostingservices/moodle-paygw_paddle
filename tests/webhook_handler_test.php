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
 * Unit tests for Paddle webhook event handling.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_paddle;

/**
 * Tests that repeat deliveries of the same notification cannot be processed twice.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \paygw_paddle\webhook_handler
 */
final class webhook_handler_test extends \advanced_testcase {
    /**
     * The first delivery of an event claims it.
     *
     * @return void
     */
    public function test_first_delivery_claims_the_event(): void {
        global $DB;
        $this->resetAfterTest();

        $claimed = webhook_handler::claim_event('evt_01', 'transaction.completed', 'txn_01', '{}');

        $this->assertNotNull($claimed);
        $this->assertEquals('evt_01', $claimed->paddle_event_id);
        $this->assertEquals(1, $DB->count_records('paygw_paddle_events'));
    }

    /**
     * A repeat delivery of an already processed event is turned away.
     *
     * @return void
     */
    public function test_repeat_delivery_of_processed_event_is_refused(): void {
        global $DB;
        $this->resetAfterTest();

        $claimed = webhook_handler::claim_event('evt_02', 'transaction.completed', 'txn_02', '{}');
        webhook_handler::mark($claimed, webhook_handler::RESULT_SUCCESS);

        $second = webhook_handler::claim_event('evt_02', 'transaction.completed', 'txn_02', '{}');

        $this->assertNull($second);
        $this->assertEquals(1, $DB->count_records('paygw_paddle_events'));
    }

    /**
     * A delivery following a failed attempt is allowed to retry.
     *
     * Paddle keeps retrying after a 500, and those retries have to be able to
     * make progress once whatever failed has been fixed.
     *
     * @return void
     */
    public function test_delivery_after_an_error_may_retry(): void {
        global $DB;
        $this->resetAfterTest();

        $claimed = webhook_handler::claim_event('evt_03', 'transaction.completed', 'txn_03', '{}');
        webhook_handler::mark($claimed, webhook_handler::RESULT_ERROR, 'Database was unavailable');

        $retry = webhook_handler::claim_event('evt_03', 'transaction.completed', 'txn_03', '{}');

        $this->assertNotNull($retry);
        $this->assertEquals($claimed->id, $retry->id);
        $this->assertEquals(1, $DB->count_records('paygw_paddle_events'));
    }

    /**
     * Marking an event as errored leaves it open for another attempt.
     *
     * @return void
     */
    public function test_error_result_leaves_event_unprocessed(): void {
        global $DB;
        $this->resetAfterTest();

        $claimed = webhook_handler::claim_event('evt_04', 'transaction.completed', 'txn_04', '{}');
        webhook_handler::mark($claimed, webhook_handler::RESULT_ERROR, 'Something broke');

        $stored = $DB->get_record('paygw_paddle_events', ['paddle_event_id' => 'evt_04']);

        $this->assertEquals(0, $stored->processed);
        $this->assertEquals(webhook_handler::RESULT_ERROR, $stored->result);
        $this->assertEquals('Something broke', $stored->error_message);
    }

    /**
     * An event type the plugin does not act on is recorded and skipped.
     *
     * @return void
     */
    public function test_unhandled_event_type_is_skipped(): void {
        global $DB;
        $this->resetAfterTest();

        $claimed = webhook_handler::claim_event('evt_05', 'subscription.created', 'txn_05', '{}');
        webhook_handler::process('subscription.created', [], $claimed);

        $stored = $DB->get_record('paygw_paddle_events', ['paddle_event_id' => 'evt_05']);

        $this->assertEquals(1, $stored->processed);
        $this->assertEquals(webhook_handler::RESULT_SKIPPED, $stored->result);
    }

    /**
     * A refund that Paddle has not approved yet changes nothing.
     *
     * @return void
     */
    public function test_unapproved_adjustment_does_not_change_the_transaction(): void {
        global $DB;
        $this->resetAfterTest();

        $transaction = (object) [
            'component' => 'enrol_fee',
            'paymentarea' => 'fee',
            'itemid' => 1,
            'paddle_transaction_id' => 'txn_06',
            'userid' => 2,
            'amount' => 10.00,
            'currency' => 'AUD',
            'tax' => 0,
            'status' => 'completed',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $DB->insert_record('paygw_paddle_transactions', $transaction);

        $claimed = webhook_handler::claim_event('evt_06', 'adjustment.created', 'txn_06', '{}');
        $adjustment = [
            'action' => 'refund',
            'status' => 'pending_approval',
            'transaction_id' => 'txn_06',
        ];
        webhook_handler::process('adjustment.created', $adjustment, $claimed);

        $stored = $DB->get_record('paygw_paddle_transactions', ['paddle_transaction_id' => 'txn_06']);
        $event = $DB->get_record('paygw_paddle_events', ['paddle_event_id' => 'evt_06']);

        $this->assertEquals('completed', $stored->status);
        $this->assertEquals(webhook_handler::RESULT_SKIPPED, $event->result);
    }

    /**
     * An approved refund marks the transaction refunded.
     *
     * @return void
     */
    public function test_approved_refund_marks_the_transaction_refunded(): void {
        global $DB;
        $this->resetAfterTest();

        $transaction = (object) [
            'component' => 'enrol_fee',
            'paymentarea' => 'fee',
            'itemid' => 999,
            'paddle_transaction_id' => 'txn_07',
            'userid' => 0,
            'amount' => 10.00,
            'currency' => 'AUD',
            'tax' => 0,
            'status' => 'completed',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $DB->insert_record('paygw_paddle_transactions', $transaction);

        $claimed = webhook_handler::claim_event('evt_07', 'adjustment.created', 'txn_07', '{}');
        $adjustment = [
            'action' => 'refund',
            'status' => 'approved',
            'transaction_id' => 'txn_07',
        ];
        webhook_handler::process('adjustment.created', $adjustment, $claimed);

        // The fixture transaction points at an item id that has no payable, so
        // the refund action falls back to the site default and reports that in
        // developer debugging. That fallback is the behaviour under test here.
        $this->assertDebuggingCalled();

        $stored = $DB->get_record('paygw_paddle_transactions', ['paddle_transaction_id' => 'txn_07']);

        $this->assertEquals('refunded', $stored->status);
    }

    /**
     * A completed notification with no local transaction row delivers nothing.
     *
     * The local row is what says which item was bought and by whom. Without it
     * there is nothing to deliver, whatever the notification claims.
     *
     * @return void
     */
    public function test_completed_event_without_a_local_row_is_skipped(): void {
        global $DB;
        $this->resetAfterTest();

        $claimed = webhook_handler::claim_event('evt_08', 'transaction.completed', 'txn_08', '{}');
        $data = [
            'id' => 'txn_08',
            'custom_data' => [
                'component' => 'enrol_fee',
                'paymentarea' => 'fee',
                'itemid' => 42,
                'userid' => 7,
            ],
        ];
        webhook_handler::process('transaction.completed', $data, $claimed);

        $event = $DB->get_record('paygw_paddle_events', ['paddle_event_id' => 'evt_08']);

        $this->assertEquals(webhook_handler::RESULT_SKIPPED, $event->result);
        $this->assertEquals(0, $DB->count_records('payments', ['gateway' => 'paddle']));
    }

    /**
     * Notified custom_data that contradicts the stored row delivers nothing.
     *
     * custom_data travels through the payer's browser, so a notification that
     * names a different item or user than the row this site wrote must not be
     * acted on.
     *
     * @return void
     */
    public function test_completed_event_with_mismatched_custom_data_is_refused(): void {
        global $DB;
        $this->resetAfterTest();

        $transaction = (object) [
            'component' => 'enrol_fee',
            'paymentarea' => 'fee',
            'itemid' => 42,
            'paddle_transaction_id' => 'txn_09',
            'userid' => 7,
            'amount' => 10.00,
            'currency' => 'AUD',
            'tax' => 0,
            'status' => 'pending',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $DB->insert_record('paygw_paddle_transactions', $transaction);

        $claimed = webhook_handler::claim_event('evt_09', 'transaction.completed', 'txn_09', '{}');
        $data = [
            'id' => 'txn_09',
            // A different learner than the one the row was written for.
            'custom_data' => [
                'component' => 'enrol_fee',
                'paymentarea' => 'fee',
                'itemid' => 42,
                'userid' => 999,
            ],
            'details' => ['totals' => ['subtotal' => 1000, 'tax' => 0, 'grand_total' => 1000]],
        ];
        webhook_handler::process('transaction.completed', $data, $claimed);

        $event = $DB->get_record('paygw_paddle_events', ['paddle_event_id' => 'evt_09']);
        $stored = $DB->get_record('paygw_paddle_transactions', ['paddle_transaction_id' => 'txn_09']);

        $this->assertEquals(webhook_handler::RESULT_ERROR, $event->result);
        $this->assertEquals('pending', $stored->status);
        $this->assertEquals(0, $DB->count_records('payments', ['gateway' => 'paddle']));
    }

    /**
     * A notified subtotal below the expected cost does not cover it.
     *
     * @return void
     */
    public function test_amount_covers_cost_compares_the_subtotal(): void {
        $method = new \ReflectionMethod(webhook_handler::class, 'amount_covers_cost');
        $method->setAccessible(true);

        // Tax sits on top of the item price, so the subtotal is what has to
        // match: a grand total inflated by tax must not stand in for it.
        $this->assertTrue($method->invoke(null, ['subtotal' => 1000, 'tax' => 100], 'AUD', 10.00));
        $this->assertTrue($method->invoke(null, ['subtotal' => 1500], 'AUD', 10.00));
        $this->assertFalse($method->invoke(null, ['subtotal' => 999], 'AUD', 10.00));
        $this->assertFalse($method->invoke(null, ['tax' => 100], 'AUD', 10.00));

        // A notification priced in another currency never covers the cost.
        $this->assertFalse(
            $method->invoke(null, ['subtotal' => 1000, 'currency_code' => 'USD'], 'AUD', 10.00)
        );

        // Zero decimal currencies are not divided by a hundred.
        $this->assertTrue($method->invoke(null, ['subtotal' => 1000], 'JPY', 1000.00));
        $this->assertFalse($method->invoke(null, ['subtotal' => 999], 'JPY', 1000.00));
    }
}
