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
 * Paddle webhook endpoint.
 *
 * Receives Paddle Billing notifications, verifies the HMAC signature, records
 * the event for idempotency and audit, and hands it to the webhook handler.
 *
 * URL: /payment/gateway/paddle/webhook.php
 *
 * This endpoint deliberately does not call require_login(): Paddle is not a
 * Moodle user. Authentication is the Paddle-Signature header, which is checked
 * before anything in the request body is trusted or stored.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../../config.php');

// Access control: this endpoint has no Moodle user and therefore no
// require_login() and no capability check. The caller is Paddle, authenticated
// by the HMAC-SHA256 signature in the Paddle-Signature header, verified against
// every configured payment account's signing secret before any part of the
// request body is trusted, parsed or stored. Requests that fail verification
// are rejected with a 403 and nothing is written.

use paygw_paddle\webhook_handler;

$rawbody = file_get_contents('php://input');
// Moodle has no wrapper for reading an arbitrary request header, so $_SERVER
// is the only portable way to get Paddle's signature. The value is treated as
// untrusted: it is parsed defensively and used only for HMAC comparison.
$signatureheader = $_SERVER['HTTP_PADDLE_SIGNATURE'] ?? '';

header('Content-Type: application/json');

if (!webhook_handler::verify_against_configured_secrets($signatureheader, $rawbody)) {
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

$eventid = (string) $event['event_id'];
$eventtype = (string) $event['event_type'];
$data = $event['data'] ?? [];
$transactionid = (string) ($data['id'] ?? ($data['transaction_id'] ?? ''));

try {
    $eventrec = webhook_handler::claim_event($eventid, $eventtype, $transactionid, $rawbody);
} catch (\Throwable $e) {
    // Could not even record the event. Ask Paddle to retry.
    http_response_code(500);
    echo json_encode(['error' => 'Could not record event']);
    exit;
}

if ($eventrec === null) {
    // Another delivery of this same event has already been processed.
    http_response_code(200);
    echo json_encode(['status' => 'already_processed']);
    exit;
}

try {
    webhook_handler::process($eventtype, $data, $eventrec);
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} catch (\Throwable $e) {
    webhook_handler::mark($eventrec, webhook_handler::RESULT_ERROR, $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Processing failed']);
}
