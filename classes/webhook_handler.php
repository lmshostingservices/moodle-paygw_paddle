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
 * Processing of Paddle Billing webhook notifications.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_paddle;

use core_payment\helper as payment_helper;

/**
 * Verifies, records and acts on Paddle webhook notifications.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webhook_handler {
    /** @var string Event has been recorded and acted on. */
    public const RESULT_SUCCESS = 'success';

    /** @var string Event was recognised but required no action. */
    public const RESULT_SKIPPED = 'skipped';

    /** @var string Event processing failed and Paddle should retry. */
    public const RESULT_ERROR = 'error';

    /** @var string Event has not been processed yet. */
    public const RESULT_PENDING = 'pending';

    /**
     * Check a raw request body against every webhook secret configured on this site.
     *
     * Each enabled Paddle payment account holds its own signing secret, so every
     * one is tried in turn and sites running more than one Paddle account still
     * verify correctly.
     *
     * @param string $signatureheader The Paddle-Signature request header.
     * @param string $rawbody The raw request body, exactly as received.
     * @return bool True when the signature matches one of the configured secrets.
     */
    public static function verify_against_configured_secrets(string $signatureheader, string $rawbody): bool {
        global $DB;

        $gateways = $DB->get_records('payment_gateways', ['gateway' => 'paddle', 'enabled' => 1]);
        foreach ($gateways as $gateway) {
            if (empty($gateway->config)) {
                continue;
            }
            $config = json_decode($gateway->config);
            if (empty($config->webhooksecret)) {
                continue;
            }
            if (paddle_helper::verify_webhook_signature($signatureheader, $rawbody, $config->webhooksecret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record an incoming event, claiming it so that a concurrent delivery cannot
     * process the same notification twice.
     *
     * The unique index on paddle_event_id is the lock: whichever request inserts
     * the row first owns the event. A duplicate delivery either finds the event
     * already processed (nothing more to do) or finds a previous attempt that
     * errored, in which case it is allowed to retry.
     *
     * @param string $eventid The Paddle event ID.
     * @param string $eventtype The Paddle event type.
     * @param string $transactionid The related Paddle transaction ID, if any.
     * @param string $rawbody The raw notification body.
     * @return \stdClass|null The claimed event record, or null when another
     *                        request has already dealt with this event.
     */
    public static function claim_event(
        string $eventid,
        string $eventtype,
        string $transactionid,
        string $rawbody
    ): ?\stdClass {
        global $DB;

        $record = new \stdClass();
        $record->paddle_event_id = $eventid;
        $record->paddle_transaction_id = $transactionid;
        $record->event_type = $eventtype;
        $record->processed = 0;
        $record->result = self::RESULT_PENDING;
        $record->error_message = null;
        $record->raw_payload = $rawbody;
        $record->timecreated = time();

        try {
            $record->id = $DB->insert_record('paygw_paddle_events', $record);
            return $record;
        } catch (\dml_exception $e) {
            // The unique index rejected the insert, so this is a repeat delivery.
            $existing = $DB->get_record('paygw_paddle_events', ['paddle_event_id' => $eventid]);
            if (empty($existing)) {
                // The insert failed for some other reason; let the caller report it.
                throw $e;
            }
            if (!empty($existing->processed)) {
                return null;
            }
            // A previous attempt errored. Allow this delivery to retry it.
            return $existing;
        }
    }

    /**
     * Act on a claimed event.
     *
     * @param string $eventtype The Paddle event type.
     * @param array $data The event data payload.
     * @param \stdClass $eventrec The claimed event record.
     * @return void
     */
    public static function process(string $eventtype, array $data, \stdClass $eventrec): void {
        switch ($eventtype) {
            case 'transaction.completed':
                self::handle_transaction_completed($data, $eventrec);
                break;

            case 'transaction.payment_failed':
                self::handle_transaction_failed($data, $eventrec);
                break;

            case 'adjustment.created':
            case 'adjustment.updated':
                self::handle_adjustment($data, $eventrec);
                break;

            default:
                self::mark($eventrec, self::RESULT_SKIPPED, 'Unhandled event type: ' . $eventtype);
                break;
        }
    }

    /**
     * Record the outcome of processing an event.
     *
     * @param \stdClass $eventrec The event record to update.
     * @param string $result One of the RESULT_* constants.
     * @param string|null $message An optional explanation.
     * @return void
     */
    public static function mark(\stdClass $eventrec, string $result, ?string $message = null): void {
        global $DB;

        $DB->update_record(
            'paygw_paddle_events',
            (object) [
                'id' => $eventrec->id,
                'processed' => ($result === self::RESULT_ERROR) ? 0 : 1,
                'result' => $result,
                'error_message' => $message === null ? null : \core_text::substr($message, 0, 1000),
            ]
        );
    }

    /**
     * Deliver the order for a completed Paddle transaction.
     *
     * @param array $data The transaction data from the notification.
     * @param \stdClass $eventrec The claimed event record.
     * @return void
     */
    protected static function handle_transaction_completed(array $data, \stdClass $eventrec): void {
        global $DB;

        $txnid = $data['id'] ?? '';
        $customdata = $data['custom_data'] ?? [];

        $component = $customdata['component'] ?? '';
        $paymentarea = $customdata['paymentarea'] ?? '';
        $itemid = (int) ($customdata['itemid'] ?? 0);
        $userid = (int) ($customdata['userid'] ?? 0);

        if (empty($component) || empty($paymentarea) || $itemid <= 0 || $userid <= 0) {
            throw new \moodle_exception('webhook_missing_metadata', 'paygw_paddle');
        }

        // Update the local record before delivering, so the return page can see
        // the payment as confirmed even if delivery is slow.
        $txnrec = $DB->get_record('paygw_paddle_transactions', ['paddle_transaction_id' => $txnid]);
        if ($txnrec) {
            $totals = $data['details']['totals'] ?? [];
            $txnrec->status = 'completed';
            $txnrec->tax = !empty($totals['tax']) ? ((float) $totals['tax'] / 100) : 0;
            $txnrec->amount = !empty($totals['grand_total'])
                ? ((float) $totals['grand_total'] / 100)
                : $txnrec->amount;
            $txnrec->paddle_customer_id = $data['customer_id'] ?? $txnrec->paddle_customer_id;
            $txnrec->timemodified = time();
            $DB->update_record('paygw_paddle_transactions', $txnrec);
        }

        // Second guard against double delivery: if this learner already has a
        // Paddle payment recorded against this item, do not enrol them again.
        $alreadypaid = $DB->record_exists(
            'payments',
            [
                'component' => $component,
                'paymentarea' => $paymentarea,
                'itemid' => $itemid,
                'userid' => $userid,
                'gateway' => 'paddle',
            ]
        );
        if ($alreadypaid) {
            self::mark($eventrec, self::RESULT_SKIPPED, 'Payment already recorded for this item and user.');
            return;
        }

        $payable = payment_helper::get_payable($component, $paymentarea, $itemid);
        $cost = payment_helper::get_rounded_cost(
            $payable->get_amount(),
            $payable->get_currency(),
            payment_helper::get_gateway_surcharge('paddle')
        );

        $paymentid = payment_helper::save_payment(
            $payable->get_account_id(),
            $component,
            $paymentarea,
            $itemid,
            $userid,
            $cost,
            $payable->get_currency(),
            'paddle'
        );
        payment_helper::deliver_order($component, $paymentarea, $itemid, $paymentid, $userid);

        self::mark($eventrec, self::RESULT_SUCCESS);
    }

    /**
     * Record a failed payment attempt.
     *
     * @param array $data The transaction data from the notification.
     * @param \stdClass $eventrec The claimed event record.
     * @return void
     */
    protected static function handle_transaction_failed(array $data, \stdClass $eventrec): void {
        global $DB;

        $txnid = $data['id'] ?? '';
        if ($txnid) {
            $txnrec = $DB->get_record('paygw_paddle_transactions', ['paddle_transaction_id' => $txnid]);
            if ($txnrec) {
                $txnrec->status = 'failed';
                $txnrec->timemodified = time();
                $DB->update_record('paygw_paddle_transactions', $txnrec);
            }
        }

        self::mark($eventrec, self::RESULT_SUCCESS);
    }

    /**
     * Act on an approved refund or chargeback.
     *
     * @param array $data The adjustment data from the notification.
     * @param \stdClass $eventrec The claimed event record.
     * @return void
     */
    protected static function handle_adjustment(array $data, \stdClass $eventrec): void {
        global $DB;

        $action = $data['action'] ?? '';
        $status = $data['status'] ?? '';
        $txnid = $data['transaction_id'] ?? '';

        if (!in_array($action, ['refund', 'chargeback'], true) || $status !== 'approved') {
            self::mark(
                $eventrec,
                self::RESULT_SKIPPED,
                "Adjustment not actionable: action={$action}, status={$status}"
            );
            return;
        }

        if (empty($txnid)) {
            throw new \moodle_exception('webhook_missing_txnid', 'paygw_paddle');
        }

        $txnrec = $DB->get_record('paygw_paddle_transactions', ['paddle_transaction_id' => $txnid]);
        if (!$txnrec) {
            self::mark($eventrec, self::RESULT_SKIPPED, 'Original transaction not found: ' . $txnid);
            return;
        }

        $newstatus = ($action === 'chargeback') ? 'chargeback' : 'refunded';
        if ($txnrec->status === $newstatus) {
            self::mark($eventrec, self::RESULT_SKIPPED, 'Adjustment already applied.');
            return;
        }

        $txnrec->status = $newstatus;
        $txnrec->timemodified = time();
        $DB->update_record('paygw_paddle_transactions', $txnrec);

        $refundaction = self::get_refund_action($txnrec);
        if ($refundaction === 'unenrol'
                && $txnrec->component === 'enrol_fee'
                && $txnrec->paymentarea === 'fee'
                && $txnrec->userid > 0) {
            $enrolinstance = $DB->get_record('enrol', ['id' => $txnrec->itemid]);
            if ($enrolinstance) {
                $plugin = enrol_get_plugin($enrolinstance->enrol);
                if ($plugin) {
                    $plugin->unenrol_user($enrolinstance, $txnrec->userid);
                }
            }
        }

        self::mark($eventrec, self::RESULT_SUCCESS);
    }

    /**
     * Work out what should happen to an enrolment after a refund.
     *
     * Reads the setting from the payment account that took the payment. If that
     * account has since been removed, the historical default of unenrolling is
     * kept so behaviour does not change silently.
     *
     * @param \stdClass $txnrec The local transaction record.
     * @return string Either 'unenrol' or 'nothing'.
     */
    protected static function get_refund_action(\stdClass $txnrec): string {
        try {
            $config = payment_helper::get_gateway_configuration(
                $txnrec->component,
                $txnrec->paymentarea,
                (int) $txnrec->itemid,
                'paddle'
            );
            if (!empty($config['refundaction'])) {
                return $config['refundaction'];
            }
        } catch (\Throwable $e) {
            // The payable may no longer exist; fall through to the site default.
            debugging(
                'paygw_paddle: could not read gateway config for refund action: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }

        return 'unenrol';
    }
}
