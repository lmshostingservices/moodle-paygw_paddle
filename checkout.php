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
 * Checkout page for Paddle Billing.
 *
 * Paddle builds the checkout URL as this page plus ?_ptxn=txn_... All of the
 * browser-side work happens in the paygw_paddle/checkout AMD module; this page
 * only assembles the configuration it needs.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;

require_once(__DIR__ . '/../../../config.php');

require_login();

// Access control: require_login() only. This page renders the Paddle overlay for
// a transaction the payer has just created, so it must be reachable by any
// authenticated user. Nothing sensitive is exposed: the client-side token is
// public by design (Paddle.js runs it in the browser) and the transaction id
// comes from this user's own session or from the _ptxn parameter Paddle appends
// to its own redirect.

$component = optional_param('component', '', PARAM_ALPHANUMEXT);
$paymentarea = optional_param('paymentarea', '', PARAM_ALPHANUMEXT);
$itemid = optional_param('itemid', 0, PARAM_INT);
$txnid = optional_param('_ptxn', '', PARAM_ALPHANUMEXT);

// _ptxn arrives in the address bar, so it is only honoured when it names a
// transaction this user actually started. Paying for somebody else's
// transaction would achieve nothing now that delivery is driven by the stored
// row, but there is no reason to open the overlay for it either.
if ($txnid !== '' && !$DB->record_exists(
    'paygw_paddle_transactions',
    ['paddle_transaction_id' => $txnid, 'userid' => $USER->id]
)) {
    $txnid = '';
}

// Billing details stashed by pay.php. Cleared straight away so a refresh does
// not reuse them.
$customerdata = null;
if (!empty($SESSION->paygw_paddle_customer)) {
    $customerdata = $SESSION->paygw_paddle_customer;
    if (empty($txnid) && !empty($customerdata->txnid)) {
        $txnid = $customerdata->txnid;
    }
    unset($SESSION->paygw_paddle_customer);
}

$PAGE->set_url(new moodle_url('/payment/gateway/paddle/checkout.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('checkout', 'paygw_paddle'));
$PAGE->set_heading(get_string('checkout', 'paygw_paddle'));

$token = '';
$environment = 'live';
$processurl = '';

if ($component !== '' && $paymentarea !== '' && $itemid > 0) {
    $config = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'paddle');
    $token = (string) ($config->clienttoken ?? '');
    $environment = (string) ($config->environment ?? 'live');
    $processparams = [
        'component' => $component,
        'paymentarea' => $paymentarea,
        'itemid' => $itemid,
    ];
    $processurl = (new moodle_url('/payment/gateway/paddle/process.php', $processparams))->out(false);
}

echo $OUTPUT->header();

if ($token === '') {
    echo $OUTPUT->notification(
        get_string('missingconfig', 'paygw_paddle'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::div(
    get_string('loadingcheckout', 'paygw_paddle'),
    'paygw-paddle-checkout-status',
    ['data-region' => 'paddle-checkout-status']
);

// Assemble the customer object for Paddle.js. Property names are camelCase
// because that is what the Paddle.js v2 API expects.
$customer = null;
if ($customerdata) {
    $customer = [];
    if (!empty($customerdata->customerid)) {
        $customer['id'] = (string) $customerdata->customerid;
    } else if (!empty($customerdata->email)) {
        $customer['email'] = (string) $customerdata->email;
    }

    $address = [];
    if (!empty($customerdata->firstline)) {
        $address['firstLine'] = (string) $customerdata->firstline;
    }
    if (!empty($customerdata->city)) {
        $address['city'] = (string) $customerdata->city;
    }
    if (!empty($customerdata->postalcode)) {
        $address['postalCode'] = (string) $customerdata->postalcode;
    }
    if (!empty($customerdata->countrycode)) {
        $address['countryCode'] = (string) $customerdata->countrycode;
    }
    if (!empty($address)) {
        $customer['address'] = $address;
    }

    if (!empty($customerdata->businessname)) {
        $customer['business'] = ['name' => (string) $customerdata->businessname];
    }

    if (empty($customer)) {
        $customer = null;
    }
}

$PAGE->requires->js_call_amd(
    'paygw_paddle/checkout',
    'init',
    [[
        'token' => $token,
        'environment' => $environment,
        'transactionId' => $txnid,
        'processUrl' => $processurl,
        'customer' => $customer,
        'failureMessage' => get_string('checkoutfailed', 'paygw_paddle'),
    ]]
);

echo $OUTPUT->footer();
