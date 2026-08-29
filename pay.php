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
 * Initiate a Paddle payment.
 *
 * On GET this shows a short billing details form. Paddle's hosted checkout only
 * asks for email, country and postcode, so the payer's name and street address
 * are collected here and sent to the Paddle Billing API when the transaction is
 * created. Without them the issued invoice has no "bill to" name or address.
 *
 * On POST the details are validated, the Paddle transaction is created, and the
 * payer is redirected to the checkout page.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;
use paygw_paddle\paddle_helper;

require_once(__DIR__ . '/../../../config.php');

require_login();

// Access control: require_login() is the only gate this page needs, and no
// capability check belongs here. Paying for an item is an action any
// authenticated user may take -- requiring a capability would lock learners out
// of paying. Authorisation of the item itself is done by helper::get_payable(),
// which throws for an item that is not payable, and by the gateway
// configuration check below.

$component = required_param('component', PARAM_ALPHANUMEXT);
$paymentarea = required_param('paymentarea', PARAM_ALPHANUMEXT);
$itemid = required_param('itemid', PARAM_INT);

$payable = helper::get_payable($component, $paymentarea, $itemid);
$currency = $payable->get_currency();
$surcharge = helper::get_gateway_surcharge('paddle');
$cost = helper::get_rounded_cost($payable->get_amount(), $currency, $surcharge);
$description = helper::get_cost_as_string($cost, $currency);

$config = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'paddle');

if (empty($config->apikey) || empty($config->clienttoken) || empty($config->webhooksecret)) {
    throw new moodle_exception('missingconfig', 'paygw_paddle');
}

$pageurl = new moodle_url(
    '/payment/gateway/paddle/pay.php',
    [
        'component' => $component,
        'paymentarea' => $paymentarea,
        'itemid' => $itemid,
    ]
);

$PAGE->set_url($pageurl);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('billingdetails', 'paygw_paddle'));
$PAGE->set_heading(get_string('billingdetails', 'paygw_paddle'));

$profilecountry = strtoupper(trim((string) ($USER->country ?? '')));
$error = '';

if (optional_param('submitted', 0, PARAM_BOOL) && confirm_sesskey()) {
    $fullname = trim(optional_param('paddle_fullname', '', PARAM_TEXT));
    $companyname = trim(optional_param('paddle_company', '', PARAM_TEXT));
    $address = trim(optional_param('paddle_address', '', PARAM_TEXT));
    $city = trim(optional_param('paddle_city', '', PARAM_TEXT));
    $postcode = trim(optional_param('paddle_postcode', '', PARAM_TEXT));

    // The country select only appears when the profile has no country, so fall
    // back to the profile value when the field was submitted as a hidden input.
    $formcountry = strtoupper(trim(optional_param('paddle_country', '', PARAM_ALPHA)));
    $country = (strlen($formcountry) === 2) ? $formcountry : $profilecountry;

    if ($fullname === '' || $address === '' || $city === '' || $postcode === '' || strlen($country) !== 2) {
        $error = get_string('invalidbillingdetails', 'paygw_paddle');
    } else {
        $paddle = new paddle_helper($config->apikey, $config->environment ?? 'live');

        [$txnid, $checkouturl, $customerid] = $paddle->create_transaction(
            $config,
            $payable,
            $description,
            $cost,
            $component,
            $paymentarea,
            $itemid,
            (int) $USER->id,
            [
                'fullname' => $fullname,
                'companyname' => $companyname,
                'address' => $address,
                'city' => $city,
                'postcode' => $postcode,
                'country' => $country,
                'email' => $USER->email,
            ]
        );

        // Hand the checkout page what it needs to open the Paddle overlay
        // against the customer record just created, so the invoice carries the
        // payer's name rather than only what Paddle's own form collects.
        $SESSION->paddle_customer = (object) [
            'txnid' => $txnid,
            'customerid' => (string) ($customerid ?? ''),
            'email' => (string) ($USER->email ?? ''),
            'firstline' => $address,
            'city' => $city,
            'postalcode' => $postcode,
            'countrycode' => $country,
            // Only sent when the payer said they are buying for a business;
            // otherwise Paddle would issue a business-to-business invoice.
            'businessname' => $companyname,
        ];

        redirect($checkouturl);
    }
}

$countries = [];
if ($profilecountry === '') {
    foreach (get_string_manager()->get_list_of_countries(true) as $code => $name) {
        $countries[] = ['code' => $code, 'name' => $name];
    }
}

$templatecontext = [
    'formaction' => $pageurl->out(false),
    'sesskey' => sesskey(),
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid,
    'costdisplay' => $description,
    'fullname' => trim(($USER->firstname ?? '') . ' ' . ($USER->lastname ?? '')),
    'address' => $USER->address ?? '',
    'city' => $USER->city ?? '',
    'postcode' => '',
    'hascountry' => ($profilecountry !== ''),
    'country' => $profilecountry,
    'countries' => $countries,
];

echo $OUTPUT->header();

if ($error !== '') {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}

echo $OUTPUT->render_from_template('paygw_paddle/billing_form', $templatecontext);
echo $OUTPUT->footer();
