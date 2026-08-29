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
 * Language strings for paygw_paddle.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Plugin identity.
$string['pluginname'] = 'Paddle Payment Gateway';
$string['gatewayname'] = 'Paddle';
$string['plugindesc'] = 'Paddle acts as Merchant of Record, handling global VAT, GST and sales tax calculation, collection and remittance on your behalf. One-time payments through a hosted checkout, with automatic enrolment once payment is confirmed.';
$string['gatewaydescription'] = 'Pay securely via Paddle. Tax is calculated automatically based on your location.';

// Gateway account settings.
$string['apikey'] = 'API key';
$string['apikey_help'] = 'The server-side Paddle API key, used for server-to-server calls. Find it in Paddle Dashboard > Developer tools > Authentication. Keep it secret.';
$string['clienttoken'] = 'Client-side token';
$string['clienttoken_help'] = 'The client-side token that initialises Paddle.js in the browser for the checkout overlay. Find it in Paddle Dashboard > Developer tools > Authentication.';
$string['webhooksecret'] = 'Webhook signing secret';
$string['webhooksecret_help'] = 'Paddle signs every webhook with this secret. The plugin verifies each signature to reject tampered or replayed notifications. Find it in Paddle Dashboard > Developer tools > Notifications, next to the notification destination you created for this site.';
$string['environment'] = 'Environment';
$string['environment_help'] = 'Sandbox connects to Paddle\'s test environment, where no real payments are processed. Switch to Live only once you have completed a successful sandbox purchase.';
$string['environment_live'] = 'Live';
$string['environment_sandbox'] = 'Sandbox';
$string['defaultproductid'] = 'Default product ID';
$string['defaultproductid_help'] = 'Create a product in Paddle Dashboard under Catalog > Products and paste its ID here (it starts with pro_). The plugin then creates each transaction with the exact cost configured in Moodle\'s enrolment settings, so you do not need a Paddle price for every course. Tax is calculated by Paddle based on the buyer\'s location. This is the recommended setup.';
$string['defaultpriceid'] = 'Default price ID (advanced)';
$string['defaultpriceid_help'] = 'Optional. A Paddle catalog price ID (starting with pri_) used when no course-specific mapping exists. If set, it overrides the default product ID and Paddle controls the amount charged rather than Moodle. Leave empty unless you manage pricing inside Paddle.';
$string['productid_or_priceid_required'] = 'Enter either a default product ID or a default price ID.';
$string['checkouturl'] = 'Custom checkout URL';
$string['checkouturl_help'] = 'Optional. Overrides the built-in checkout page. Whatever you enter must be listed as an approved domain in your Paddle checkout settings. Leave empty to use the page that ships with this plugin.';
$string['refundaction'] = 'On refund or chargeback';
$string['refundaction_help'] = 'What should happen to the learner\'s enrolment when Paddle approves a refund or a chargeback for a completed payment.';
$string['refundaction_unenrol'] = 'Unenrol the learner from the course';
$string['refundaction_nothing'] = 'Leave the enrolment in place';

// Pre-checkout billing details form.
$string['billingdetails'] = 'Billing details';
$string['billingdetails_desc'] = 'Confirm your billing details. These appear on the invoice Paddle issues for this payment.';
$string['ordertotal'] = 'Order total';
$string['fullname'] = 'Full name';
$string['fullname_placeholder'] = 'e.g. Jane Smith';
$string['companyname'] = 'Company name (optional)';
$string['companyname_placeholder'] = 'e.g. Acme Pty Ltd';
$string['companyname_help'] = 'Fill this in only if you are paying on behalf of a business. Leave it blank to receive a personal invoice.';
$string['addressline1'] = 'Address line 1';
$string['addressline1_placeholder'] = 'e.g. 123 Main Street';
$string['city'] = 'Suburb or city';
$string['city_placeholder'] = 'e.g. Sydney';
$string['postcode'] = 'Postcode';
$string['postcode_placeholder'] = 'e.g. 2000';
$string['country'] = 'Country';
$string['country_placeholder'] = 'Select your country';
$string['continuetopayment'] = 'Continue to secure payment';
$string['paddlecollects'] = 'Card details are collected and stored by Paddle. This site never sees them.';

// Checkout and return pages.
$string['checkout'] = 'Secure checkout';
$string['loadingcheckout'] = 'Loading secure checkout...';
$string['checkoutfailed'] = 'The checkout could not be loaded. Return to the course and try again, or contact the site administrator if the problem continues.';
$string['paymentprocessing'] = 'Payment processing';
$string['paymentsuccess'] = 'Payment successful. You have been enrolled in the course.';
$string['paymentpending'] = 'Confirming your payment...';
$string['paymentpendingdetail'] = 'Paddle is confirming your payment. This usually takes a few seconds and this page will update on its own.';
$string['paymenttakinglonger'] = 'Your payment is taking longer than usual to confirm. Your card has not been charged twice. Refresh this page in a few minutes, or contact the site administrator if you have received a Paddle receipt but are still not enrolled.';
$string['refreshstatus'] = 'Refresh status';
$string['gotocourse'] = 'Go to course';

// Admin pages.
$string['pricemap'] = 'Paddle price mapping';
$string['reports'] = 'Paddle transaction reports';
$string['tabtransactions'] = 'Transactions';
$string['tabevents'] = 'Webhook events';

// Price mapping.
$string['coursename'] = 'Course';
$string['coursedeleted'] = 'Course {$a} (deleted)';
$string['paddlepriceid'] = 'Paddle price ID';
$string['pricemapamount'] = 'Amount';
$string['pricemapcurrency'] = 'Currency';
$string['pricemapdescription'] = 'Description';
$string['pricemapactive'] = 'Active';
$string['pricemapenable'] = 'Enable';
$string['pricemapdisable'] = 'Disable';
$string['pricemapactions'] = 'Actions';
$string['addpricemapping'] = 'Add price mapping';
$string['confirmdeletepricemap'] = 'Are you sure you want to delete this price mapping?';
$string['pricemapsaved'] = 'Price mapping saved.';
$string['pricemapdeleted'] = 'Price mapping deleted.';
$string['pricemapduplicate'] = 'That course already has a price mapping. Edit or delete the existing one first.';
$string['nopricemappings'] = 'No price mappings are configured. Every course uses the default product or price ID from the payment account gateway settings.';

// Price mapping guidance.
$string['pricemap_whatisit'] = 'What is price mapping?';
$string['pricemap_whatisit_desc'] = 'Price mapping lets you charge a different amount for particular courses by pointing them at a specific Paddle price. Most sites do not need it: with a default product ID configured, each course is charged the cost set in its enrolment on payment settings. Use this page only when you manage prices inside the Paddle catalog instead.';
$string['pricemap_howitworks'] = 'How to set up a price mapping';
$string['pricemap_step1'] = 'In your Paddle dashboard, go to Catalog > Products and create a product, or open an existing one.';
$string['pricemap_step2'] = 'Under that product, choose Add price. Set the amount and currency, set the billing type to one-time, save, and copy the price ID (it starts with pri_).';
$string['pricemap_step3'] = 'Back on this page, choose the Moodle course, paste the price ID, and record the amount and currency for your own reference. Paddle, not Moodle, controls what is actually charged.';
$string['pricemap_step4'] = 'Save the mapping. Learners enrolling in that course are charged using this price ID instead of the default.';
$string['pricemap_fields'] = 'Field descriptions';
$string['pricemap_field_course'] = 'Course: the Moodle course this price applies to.';
$string['pricemap_field_priceid'] = 'Paddle price ID: the identifier from your Paddle dashboard, for example pri_01jm5kp7. This is what determines the amount charged.';
$string['pricemap_field_amount'] = 'Amount: a display value for your own reference. It does not affect the charge.';
$string['pricemap_field_currency'] = 'Currency: a display value for your own reference.';
$string['pricemap_field_description'] = 'Description: an optional note for your records.';

// Reports.
$string['transactionid'] = 'Transaction ID';
$string['paddlecustomerid'] = 'Customer ID';
$string['transactionstatus'] = 'Status';
$string['transactionamount'] = 'Amount';
$string['transactiontax'] = 'Tax';
$string['transactiondate'] = 'Date';
$string['transactionuser'] = 'User';
$string['transactioncomponent'] = 'Component';
$string['transactionitemid'] = 'Item ID';
$string['transactionemail'] = 'Email';
$string['eventid'] = 'Event ID';
$string['eventtype'] = 'Event type';
$string['eventresult'] = 'Result';
$string['notransactions'] = 'No transactions found.';
$string['noevents'] = 'No webhook events found.';
$string['searchtransactions'] = 'Search transactions';
$string['filter'] = 'Filter';
$string['filterbystatus'] = 'Filter by status';
$string['allstatuses'] = 'All statuses';
$string['exportcsv'] = 'Export CSV';
$string['status_pending'] = 'Pending';
$string['status_completed'] = 'Completed';
$string['status_failed'] = 'Failed';
$string['status_refunded'] = 'Refunded';
$string['status_chargeback'] = 'Chargeback';
$string['result_pending'] = 'Pending';
$string['result_success'] = 'Success';
$string['result_skipped'] = 'Skipped';
$string['result_error'] = 'Error';

// Errors.
$string['missingconfig'] = 'The Paddle payment gateway is not configured. Please contact the site administrator.';
$string['missingpriceid'] = 'No Paddle product or price ID is configured for this course. Please contact the site administrator.';
$string['apierror'] = 'Paddle could not be reached. Please try again, or contact the site administrator if the problem continues.';
$string['invalidbillingdetails'] = 'Please complete every required billing field before continuing.';
$string['invalidcurrency'] = 'Enter a three-letter currency code that Paddle supports, for example AUD.';
$string['webhook_missing_metadata'] = 'Webhook event is missing required metadata (component, paymentarea, itemid or userid).';
$string['webhook_missing_txnid'] = 'Webhook adjustment event is missing a transaction ID.';

// Capabilities.
$string['paddle:manage'] = 'Manage Paddle payment gateway settings and price mappings';
$string['paddle:viewreports'] = 'View Paddle transaction reports';

// Privacy.
$string['privacy:metadata:paygw_paddle_transactions'] = 'Payment records created when a learner pays through the Paddle gateway.';
$string['privacy:metadata:paygw_paddle_transactions:userid'] = 'The Moodle user ID of the payer.';
$string['privacy:metadata:paygw_paddle_transactions:component'] = 'The Moodle payment component, for example enrol_fee.';
$string['privacy:metadata:paygw_paddle_transactions:paymentarea'] = 'The Moodle payment area.';
$string['privacy:metadata:paygw_paddle_transactions:itemid'] = 'The Moodle item ID being paid for.';
$string['privacy:metadata:paygw_paddle_transactions:paddle_transaction_id'] = 'The Paddle transaction ID.';
$string['privacy:metadata:paygw_paddle_transactions:paddle_customer_id'] = 'The Paddle customer ID linked to the payer.';
$string['privacy:metadata:paygw_paddle_transactions:amount'] = 'The payment amount.';
$string['privacy:metadata:paygw_paddle_transactions:currency'] = 'The ISO 4217 currency code.';
$string['privacy:metadata:paygw_paddle_transactions:tax'] = 'The tax amount calculated by Paddle.';
$string['privacy:metadata:paygw_paddle_transactions:status'] = 'The transaction status.';
$string['privacy:metadata:paygw_paddle_transactions:timecreated'] = 'The time the transaction was created.';
$string['privacy:metadata:paygw_paddle_transactions:timemodified'] = 'The time the transaction last changed.';

$string['privacy:metadata:paygw_paddle_events'] = 'Webhook notifications received from Paddle, retained as an audit trail and to stop the same notification being processed twice. The stored payload can contain the payer\'s name, email address and billing address.';
$string['privacy:metadata:paygw_paddle_events:paddle_event_id'] = 'The Paddle event ID.';
$string['privacy:metadata:paygw_paddle_events:paddle_transaction_id'] = 'The Paddle transaction the event relates to.';
$string['privacy:metadata:paygw_paddle_events:event_type'] = 'The type of Paddle event, for example transaction.completed.';
$string['privacy:metadata:paygw_paddle_events:result'] = 'The outcome of processing the event.';
$string['privacy:metadata:paygw_paddle_events:error_message'] = 'Any error recorded while processing the event.';
$string['privacy:metadata:paygw_paddle_events:raw_payload'] = 'The full notification body sent by Paddle, which can include the payer\'s name, email address and billing address.';
$string['privacy:metadata:paygw_paddle_events:timecreated'] = 'The time the event was received.';

$string['privacy:metadata:paddle'] = 'To take a payment, billing details are sent to Paddle, which acts as Merchant of Record for the transaction. Paddle stores this data under its own privacy policy.';
$string['privacy:metadata:paddle:name'] = 'The payer\'s full name, shown on the invoice Paddle issues.';
$string['privacy:metadata:paddle:email'] = 'The payer\'s email address, used to identify the Paddle customer record and to deliver the receipt.';
$string['privacy:metadata:paddle:address'] = 'The payer\'s street address.';
$string['privacy:metadata:paddle:city'] = 'The payer\'s suburb or city.';
$string['privacy:metadata:paddle:postcode'] = 'The payer\'s postcode.';
$string['privacy:metadata:paddle:country'] = 'The payer\'s country, used to calculate the applicable tax.';
$string['privacy:metadata:paddle:business'] = 'The company name, when the payer states they are buying on behalf of a business.';
$string['privacy:metadata:paddle:userid'] = 'The Moodle user ID, sent as transaction metadata so the payment can be matched back to the right account when Paddle confirms it.';

$string['privacy:path:transactions'] = 'Paddle transactions';
$string['privacy:path:events'] = 'Paddle webhook events';
