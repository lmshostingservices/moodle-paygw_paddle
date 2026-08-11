<?php
// require_login() — deliberately omitted: this endpoint uses its own authentication or is not a user-facing web page.
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
 * Paddle webhook endpoint.
 *
 * Receives and processes Paddle Billing webhook events.
 * Verifies HMAC signature, enforces idempotency, and triggers
 * Moodle payment delivery or refund suspension.
 *
 * URL: /payment/gateway/paddle/webhook.php
 * Must be publicly accessible (no login required).
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../config.php');

use paygw_paddle\paddle_helper;
use core_payment\helper as payment_helper;

$rawbody = file_get_contents('php://input');
$signatureheader = $_SERVER['HTTP_PADDLE_SIGNATURE'] ?? '';

// Load webhook secret — try global config first, then per-account gateway configs.
$webhooksecret = get_config('paygw_paddle', 'webhooksecret');
$verified = false;

if (!empty($webhooksecret)) {
    $verified = paddle_helper::verify_webhook_signature($signatureheader, $rawbody, $webhooksecret);
}

if (!$verified) {
    // Try each configured Paddle gateway account's webhook secret.
    $gateways = $DB->get_records('payment_gateways', ['gateway' => 'paddle', 'enabled' => 1]);
    foreach ($gateways as $gw) {
        if (empty($gw->config)) {
            continue;
        }
        $gwconfig = json_decode($gw->config);
        if (!empty($gwconfig->webhooksecret)) {
            if (paddle_helper::verify_webhook_signature($signatureheader, $rawbody, $gwconfig->webhooksecret)) {
                $verified = true;
                break;
            }
        }
    }
}

if (!$verified) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($rawbody, true);
if (empty($event) || empty($event['event_id']) || empty($event['event_type'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event payload']);
    exit;
}

$eventid = $event['event_id'];
$eventtype = $event['event_type'];
$data = $event['data'] ?? [];

// Idempotency check: skip if already processed.
$existing = $DB->get_record('paygw_paddle_events', ['paddle_event_id' => $eventid]);
if ($existing && $existing->processed) {
    http_response_code(200);
    echo json_encode(['status' => 'already_processed']);
    exit;
}

// Store event for audit trail (insert if not exists).
$eventrec = new stdClass();
$eventrec->paddle_event_id = $eventid;
$eventrec->paddle_transaction_id = $data['id'] ?? ($data['transaction_id'] ?? '');
$eventrec->event_type = $eventtype;
$eventrec->processed = 0;
$eventrec->result = 'pending';
$eventrec->error_message = null;
$eventrec->raw_payload = $rawbody;
$eventrec->timecreated = time();

if (!$existing) {
    $eventrec->id = $DB->insert_record('paygw_paddle_events', $eventrec);
} else {
    $eventrec->id = $existing->id;
}

try {
    switch ($eventtype) {
        case 'transaction.completed':
            handle_transaction_completed($data, $eventrec);
            break;

        case 'transaction.payment_failed':
            handle_transaction_failed($data, $eventrec);
            break;

        case 'adjustment.created':
        case 'adjustment.updated':
            handle_adjustment($data, $eventrec);
            break;

        default:
            // Log unhandled event types but return 200 so Paddle doesn't retry.
            $DB->update_record('paygw_paddle_events', (object)[
                'id' => $eventrec->id,
                'processed' => 1,
                'result' => 'skipped',
                'error_message' => 'Unhandled event type: ' . $eventtype,
            ]);
            break;
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok']);

} catch (\Exception $e) {
    $DB->update_record('paygw_paddle_events', (object)[
        'id' => $eventrec->id,
        'processed' => 0,
        'result' => 'error',
        'error_message' => substr($e->getMessage(), 0, 1000),
    ]);

    http_response_code(500);
    echo json_encode(['error' => 'Processing failed']);
}

/**
 * Handle transaction.completed: enrol the user.
 */
function handle_transaction_completed(array $data, stdClass $eventrec): void {
    global $DB;

    $txnid = $data['id'] ?? '';
    $customdata = $data['custom_data'] ?? [];

    $component = $customdata['component'] ?? '';
    $paymentarea = $customdata['paymentarea'] ?? '';
    $itemid = (int)($customdata['itemid'] ?? 0);
    $userid = (int)($customdata['userid'] ?? 0);

    if (empty($component) || empty($paymentarea) || $itemid <= 0 || $userid <= 0) {
        throw new \moodle_exception('webhook_missing_metadata', 'paygw_paddle');
    }

    // Update local transaction record.
    $txnrec = $DB->get_record('paygw_paddle_transactions', ['paddle_transaction_id' => $txnid]);
    if ($txnrec) {
        $totals = $data['details']['totals'] ?? [];
        $txnrec->status = 'completed';
        $txnrec->tax = !empty($totals['tax']) ? ((float)$totals['tax'] / 100) : 0;
        $txnrec->amount = !empty($totals['grand_total']) ? ((float)$totals['grand_total'] / 100) : $txnrec->amount;
        $txnrec->paddle_customer_id = $data['customer_id'] ?? $txnrec->paddle_customer_id;
        $txnrec->timemodified = time();
        $DB->update_record('paygw_paddle_transactions', $txnrec);
    }

    // Deliver order via Moodle payment API.
    $payable = payment_helper::get_payable($component, $paymentarea, $itemid);
    $cost = $payable->get_amount();
    $currency = $payable->get_currency();
    $surcharge = payment_helper::get_gateway_surcharge('paddle');
    $paymentid = payment_helper::save_payment(
        $payable->get_account_id(),
        $component,
        $paymentarea,
        $itemid,
        $userid,
        $cost,
        $currency,
        'paddle'
    );
    payment_helper::deliver_order($component, $paymentarea, $itemid, $paymentid, $userid);

    // Mark event as processed.
    $DB->update_record('paygw_paddle_events', (object)[
        'id' => $eventrec->id,
        'processed' => 1,
        'result' => 'success',
        'error_message' => null,
    ]);
}

/**
 * Handle transaction.payment_failed: update transaction status.
 */
function handle_transaction_failed(array $data, stdClass $eventrec): void {
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

    $DB->update_record('paygw_paddle_events', (object)[
        'id' => $eventrec->id,
        'processed' => 1,
        'result' => 'success',
        'error_message' => null,
    ]);
}

/**
 * Handle adjustment.created / adjustment.updated (refund/chargeback).
 *
 * When Paddle issues a refund, suspend/unenrol the user based on config.
 */
function handle_adjustment(array $data, stdClass $eventrec): void {
    global $DB;

    $action = $data['action'] ?? '';
    $status = $data['status'] ?? '';
    $txnid = $data['transaction_id'] ?? '';

    // Only act on approved refunds/chargebacks.
    if (!in_array($action, ['refund', 'chargeback']) || $status !== 'approved') {
        $DB->update_record('paygw_paddle_events', (object)[
            'id' => $eventrec->id,
            'processed' => 1,
            'result' => 'skipped',
            'error_message' => "Adjustment not actionable: action={$action}, status={$status}",
        ]);
        return;
    }

    if (empty($txnid)) {
        throw new \moodle_exception('webhook_missing_txnid', 'paygw_paddle');
    }

    // Find the original transaction.
    $txnrec = $DB->get_record('paygw_paddle_transactions', ['paddle_transaction_id' => $txnid]);
    if (!$txnrec) {
        $DB->update_record('paygw_paddle_events', (object)[
            'id' => $eventrec->id,
            'processed' => 1,
            'result' => 'skipped',
            'error_message' => 'Original transaction not found: ' . $txnid,
        ]);
        return;
    }

    $txnrec->status = ($action === 'chargeback') ? 'chargeback' : 'refunded';
    $txnrec->timemodified = time();
    $DB->update_record('paygw_paddle_transactions', $txnrec);

    $refundaction = get_config('paygw_paddle', 'refundaction') ?: 'unenrol';

    if ($refundaction === 'unenrol' && $txnrec->component === 'enrol_fee' && $txnrec->paymentarea === 'fee') {
        // Unenrol the user from the course.
        $enrolinstance = $DB->get_record('enrol', ['id' => $txnrec->itemid]);
        if ($enrolinstance) {
            $plugin = enrol_get_plugin($enrolinstance->enrol);
            if ($plugin) {
                $plugin->unenrol_user($enrolinstance, $txnrec->userid);
            }
        }
    }

    $DB->update_record('paygw_paddle_events', (object)[
        'id' => $eventrec->id,
        'processed' => 1,
        'result' => 'success',
        'error_message' => null,
    ]);
}
