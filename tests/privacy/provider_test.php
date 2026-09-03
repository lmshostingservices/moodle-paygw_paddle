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
 * Unit tests for the paygw_paddle privacy provider.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_paddle\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests that the provider reports, exports and anonymises the right records.
 *
 * The plugin keeps its financial rows and strips the personal data out of them
 * rather than deleting them, so these tests check that the rows survive and
 * that nothing identifying survives with them.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \paygw_paddle\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Insert one transaction and one related event for a user.
     *
     * @param int $userid The learner the transaction belongs to.
     * @param string $txnid The Paddle transaction id.
     * @return void
     */
    protected function create_transaction(int $userid, string $txnid): void {
        global $DB;

        $DB->insert_record('paygw_paddle_transactions', (object) [
            'component' => 'enrol_fee',
            'paymentarea' => 'fee',
            'itemid' => 1,
            'paddle_transaction_id' => $txnid,
            'paddle_customer_id' => 'ctm_' . $userid,
            'userid' => $userid,
            'amount' => 10.00,
            'currency' => 'AUD',
            'tax' => 1.00,
            'status' => 'completed',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $DB->insert_record('paygw_paddle_events', (object) [
            'paddle_event_id' => 'evt_' . $txnid,
            'paddle_transaction_id' => $txnid,
            'event_type' => 'transaction.completed',
            'processed' => 1,
            'result' => 'success',
            'raw_payload' => '{"customer":{"email":"someone@example.com"}}',
            'timecreated' => time(),
        ]);
    }

    /**
     * A user with a transaction is reported in the system context.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_transaction((int) $user->id, 'txn_p1');

        $contexts = provider::get_contexts_for_userid((int) $user->id)->get_contexts();
        $this->assertCount(1, $contexts);
        $this->assertInstanceOf(\context_system::class, reset($contexts));

        // Somebody who never paid is in no context at all.
        $this->assertCount(0, provider::get_contexts_for_userid((int) $other->id)->get_contexts());
    }

    /**
     * Only users who actually paid appear in the system context userlist.
     *
     * @return void
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_transaction((int) $user->id, 'txn_p2');

        $userlist = new userlist(\context_system::instance(), 'paygw_paddle');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing([(int) $user->id], $userlist->get_userids());
        $this->assertNotContains((int) $other->id, $userlist->get_userids());
    }

    /**
     * Export writes the learner's transactions and their related events.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->create_transaction((int) $user->id, 'txn_p3');

        $context = \context_system::instance();
        $this->export_context_data_for_user((int) $user->id, $context, 'paygw_paddle');

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $root = [get_string('pluginname', 'paygw_paddle')];
        $transactions = $writer->get_data(
            array_merge($root, [get_string('privacy:path:transactions', 'paygw_paddle')])
        );
        $this->assertCount(1, $transactions->transactions);
        $this->assertEquals('txn_p3', $transactions->transactions[0]->paddle_transaction_id);

        $events = $writer->get_data(
            array_merge($root, [get_string('privacy:path:events', 'paygw_paddle')])
        );
        $this->assertCount(1, $events->events);
        $this->assertEquals('evt_txn_p3', $events->events[0]->paddle_event_id);
    }

    /**
     * Deleting one user's data anonymises their rows and leaves other users alone.
     *
     * @return void
     */
    public function test_delete_data_for_user_anonymises_only_that_user(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_transaction((int) $user->id, 'txn_p4');
        $this->create_transaction((int) $other->id, 'txn_p5');

        $contextlist = new approved_contextlist($user, 'paygw_paddle', [\context_system::instance()->id]);
        provider::delete_data_for_user($contextlist);

        // The financial row survives; the personal data in it does not.
        $mine = $DB->get_record('paygw_paddle_transactions', ['paddle_transaction_id' => 'txn_p4']);
        $this->assertNotFalse($mine);
        $this->assertEquals(0, $mine->userid);
        $this->assertNull($mine->paddle_customer_id);
        $this->assertEquals(10.00, (float) $mine->amount);

        $theirs = $DB->get_record('paygw_paddle_transactions', ['paddle_transaction_id' => 'txn_p5']);
        $this->assertEquals((int) $other->id, (int) $theirs->userid);
        $this->assertEquals('ctm_' . $other->id, $theirs->paddle_customer_id);

        // The payload of the anonymised user's event is cleared, not theirs.
        $this->assertNull($DB->get_field('paygw_paddle_events', 'raw_payload', ['paddle_event_id' => 'evt_txn_p4']));
        $this->assertNotNull($DB->get_field('paygw_paddle_events', 'raw_payload', ['paddle_event_id' => 'evt_txn_p5']));
    }

    /**
     * Deleting an approved list of users anonymises exactly those users.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_transaction((int) $user->id, 'txn_p6');
        $this->create_transaction((int) $other->id, 'txn_p7');

        $approved = new approved_userlist(\context_system::instance(), 'paygw_paddle', [(int) $user->id]);
        provider::delete_data_for_users($approved);

        $this->assertEquals(
            0,
            (int) $DB->get_field('paygw_paddle_transactions', 'userid', ['paddle_transaction_id' => 'txn_p6'])
        );
        $this->assertEquals(
            (int) $other->id,
            (int) $DB->get_field('paygw_paddle_transactions', 'userid', ['paddle_transaction_id' => 'txn_p7'])
        );
    }

    /**
     * Purging the system context anonymises every row but keeps them all.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_transaction((int) $user->id, 'txn_p8');
        $this->create_transaction((int) $other->id, 'txn_p9');

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertEquals(2, $DB->count_records('paygw_paddle_transactions'));
        $this->assertEquals(0, $DB->count_records_select('paygw_paddle_transactions', 'userid > 0'));
        $this->assertEquals(0, $DB->count_records_select('paygw_paddle_transactions', 'paddle_customer_id IS NOT NULL'));
        $this->assertEquals(0, $DB->count_records_select('paygw_paddle_events', 'raw_payload IS NOT NULL'));
    }

    /**
     * A non system context is left untouched.
     *
     * @return void
     */
    public function test_other_contexts_are_ignored(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->create_transaction((int) $user->id, 'txn_p10');

        provider::delete_data_for_all_users_in_context(\context_course::instance($course->id));

        $this->assertEquals(
            (int) $user->id,
            (int) $DB->get_field('paygw_paddle_transactions', 'userid', ['paddle_transaction_id' => 'txn_p10'])
        );
    }
}
