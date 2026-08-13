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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin.
$string['pluginname'] = 'Paddle Payment Gateway';
$string['gatewayname'] = 'Paddle';
$string['plugindesc'] = 'Paddle acts as Merchant of Record, handling global VAT/GST/sales tax calculation, collection, and remittance so you don\'t have to. One-time payments via hosted checkout with automatic enrolment on payment confirmation.';
$string['gatewaydescription'] = 'Pay securely via Paddle. Tax is calculated automatically based on your location.';

// Settings.
$string['apikey'] = 'API Key';
$string['apikey_desc'] = 'Your Paddle API key (server-side). Found in Paddle Dashboard > Developer Tools > Authentication.';
$string['apikey_help'] = 'The Paddle API key is used for server-to-server communication. Keep this secret.';
$string['clienttoken'] = 'Client-Side Token';
$string['clienttoken_desc'] = 'Your Paddle client-side token for Paddle.js. Found in Paddle Dashboard > Developer Tools > Authentication.';
$string['clienttoken_help'] = 'The client-side token initialises Paddle.js in the browser for the checkout overlay.';
$string['webhooksecret'] = 'Webhook Signing Secret';
$string['webhooksecret_desc'] = 'The secret key used to verify webhook signatures. Found in Paddle Dashboard > Developer Tools > Notifications.';
$string['webhooksecret_help'] = 'Paddle signs every webhook with this secret. The plugin verifies signatures to prevent tampering.';
$string['environment'] = 'Environment';
$string['environment_desc'] = 'Use Sandbox for testing, Live for production payments.';
$string['environment_help'] = 'Sandbox mode connects to Paddle\'s test environment. No real payments are processed.';
$string['environment_live'] = 'Live';
$string['environment_sandbox'] = 'Sandbox';
$string['defaultproductid'] = 'Default Product ID';
$string['defaultproductid_desc'] = 'Your Paddle Product ID (pro_...) used to create dynamic transactions. When set, the plugin creates Paddle transactions with the exact price configured in Moodle\'s Enrolment on Payment settings — no per-course Price IDs are needed. Recommended over Default Price ID for per-course pricing.';
$string['defaultproductid_help'] = 'Create a product in Paddle Dashboard under Catalog > Products and copy the Product ID (starts with pro_). The plugin will create a one-time Paddle transaction with the exact cost configured in Moodle\'s enrolment settings. Tax is calculated automatically by Paddle based on the buyer\'s location.';
$string['defaultpriceid'] = 'Default Price ID (advanced)';
$string['defaultpriceid_desc'] = 'Optional. A Paddle catalog Price ID (pri_...) used when no course-specific price mapping exists. If set, this overrides the Default Product ID. Leave empty to use per-course pricing via the Default Product ID above.';
$string['defaultpriceid_help'] = 'Advanced: Create prices in your Paddle Dashboard under Catalog > Prices. Each price has a unique ID starting with pri_. If you use per-course price mapping (admin/pricemap.php), that mapping takes priority over this field.';
$string['productid_or_priceid_required'] = 'You must provide either a Default Product ID or a Default Price ID.';
$string['checkouturl'] = 'Custom Checkout URL';
$string['checkouturl_desc'] = 'Optional. Override the default checkout page URL. Leave empty to use the built-in checkout page.';
$string['checkouturl_help'] = 'If set, this URL will be used as the Paddle checkout page instead of the built-in one. Must be an approved domain in your Paddle settings.';
$string['refundaction'] = 'Refund Action';
$string['refundaction_desc'] = 'What happens when Paddle issues a refund or chargeback for a completed payment.';
$string['refundaction_help'] = 'Choose what happens to the user\'s enrolment when a refund is processed by Paddle.';
$string['refundaction_unenrol'] = 'Unenrol user from course';

// Pre-checkout billing details form (pay.php).
$string['billingdetails']             = 'Billing Details';
$string['billingdetails_desc']        = 'Please confirm your billing details for the payment invoice. These details appear on your receipt.';
$string['ordertotal']                 = 'Order total';
$string['fullname']                   = 'Full Name';
$string['fullname_placeholder']       = 'e.g. Jane Smith';
$string['companyname']                = 'Company Name (optional)';
$string['companyname_placeholder']    = 'e.g. Acme Pty Ltd';
$string['companyname_help']           = 'Only fill this in if you are paying on behalf of a business. Leave blank to receive a personal invoice.';
$string['addressline1']               = 'Address Line 1';
$string['addressline1_placeholder']   = 'e.g. 123 Main Street';
$string['city']                       = 'Suburb / City';
$string['city_placeholder']           = 'e.g. Sydney';
$string['postcode']                   = 'Postcode';
$string['postcode_placeholder']       = 'e.g. 2000';
$string['country']                    = 'Country';
$string['country_placeholder']        = 'Select your country';
$string['paddlecollects']             = 'All payment details are handled securely by Paddle.';
$string['continuetopayment']          = 'Continue to Secure Payment';

// Checkout / Process.
$string['checkout'] = 'Secure Checkout';
$string['loadingcheckout'] = 'Loading secure checkout...';
$string['paymentprocessing'] = 'Payment Processing';
$string['paymentsuccess'] = 'Payment successful! You have been enrolled in the course.';
$string['paymentpending'] = 'Payment is being processed...';
$string['paymentpendingdetail'] = 'Your payment is being confirmed by Paddle. This usually takes a few seconds. The page will refresh automatically.';
$string['refreshstatus'] = 'Refresh Status';
$string['gotocourse'] = 'Go to Course';

// Admin pages.
$string['pricemap'] = 'Paddle Price Mapping';
$string['pricemap_desc'] = 'Map Moodle courses to Paddle Price IDs for per-course pricing.';
$string['reports'] = 'Paddle Transaction Reports';
$string['reports_desc'] = 'View and search Paddle payment transactions and webhook events.';

// Price mapping.
$string['coursename'] = 'Course';
$string['paddlepriceid'] = 'Paddle Price ID';
$string['pricemapamount'] = 'Amount';
$string['pricemapcurrency'] = 'Currency';
$string['pricemapdescription'] = 'Description';
$string['pricemapactive'] = 'Active';
$string['addpricemapping'] = 'Add Price Mapping';
$string['editpricemapping'] = 'Edit Price Mapping';
$string['deletepricemapping'] = 'Delete Price Mapping';
$string['confirmdeletepricemap'] = 'Are you sure you want to delete this price mapping?';
$string['pricemapsaved'] = 'Price mapping saved successfully.';
$string['pricemapdeleted'] = 'Price mapping deleted.';
$string['nopricemappings'] = 'No price mappings configured. The default Price ID from settings will be used for all courses.';

// Price mapping instructions.
$string['pricemap_whatisit'] = 'What is Price Mapping?';
$string['pricemap_whatisit_desc'] = 'Price mapping lets you charge different prices for different courses. In Paddle, every price is a separate "Price" object with its own ID (e.g. pri_01abc123). By default, all courses use the same Default Price ID from your Payment Account gateway settings. This page lets you override that on a per-course basis so each course can have its own price, currency, and amount.';
$string['pricemap_howitworks'] = 'How to set up a price mapping';
$string['pricemap_step1'] = 'In your <strong>Paddle Dashboard</strong>, go to <strong>Catalog > Products</strong> and create a product (or use an existing one).';
$string['pricemap_step2'] = 'Under that product, click <strong>Add Price</strong>. Set the amount, currency, and billing type to <strong>One-time</strong>. Save it and copy the <strong>Price ID</strong> (starts with <code>pri_</code>).';
$string['pricemap_step3'] = 'Back here, select the Moodle course, paste the Paddle Price ID, and enter the amount and currency for your reference (these are display-only — Paddle controls the actual charge).';
$string['pricemap_step4'] = 'Click <strong>Add Price Mapping</strong>. When a student enrols in that course, the plugin will use this specific Price ID instead of the default one.';
$string['pricemap_fields'] = 'Field descriptions';
$string['pricemap_field_course'] = '<strong>Course</strong> — The Moodle course this price applies to.';
$string['pricemap_field_priceid'] = '<strong>Paddle Price ID</strong> — The unique price identifier from your Paddle Dashboard (e.g. <code>pri_01jm5kp7...</code>). This is what Paddle uses to determine the charge amount.';
$string['pricemap_field_amount'] = '<strong>Amount</strong> — The display amount for your reference (e.g. 99.00). This does not affect the actual charge — Paddle uses the amount configured on the Price ID.';
$string['pricemap_field_currency'] = '<strong>Currency</strong> — The display currency code (e.g. AUD, USD). For reference only.';
$string['pricemap_field_description'] = '<strong>Description</strong> — An optional note for your own records (e.g. "Certificate IV in WHS enrolment").';

// Reports.
$string['transactionid'] = 'Transaction ID';
$string['paddlecustomerid'] = 'Customer ID';
$string['transactionstatus'] = 'Status';
$string['transactionamount'] = 'Amount';
$string['transactiontax'] = 'Tax';
$string['transactiondate'] = 'Date';
$string['transactionuser'] = 'User';
$string['eventtype'] = 'Event Type';
$string['eventresult'] = 'Result';
$string['notransactions'] = 'No transactions found.';
$string['noevents'] = 'No webhook events found.';
$string['searchtransactions'] = 'Search transactions...';
$string['filterbydate'] = 'Filter by date';
$string['filterbystatus'] = 'Filter by status';
$string['allstatuses'] = 'All statuses';
$string['exportcsv'] = 'Export CSV';
$string['viewevents'] = 'View Events';
$string['reprocessevent'] = 'Reprocess Event';

// Errors.
$string['missingconfig'] = 'Paddle payment gateway is not configured. Please contact the site administrator.';
$string['missingpriceid'] = 'No Paddle Price ID configured for this course. Please contact the site administrator.';
$string['apierror'] = 'Paddle API error. Please try again or contact the site administrator.';
$string['webhook_missing_metadata'] = 'Webhook event is missing required metadata (component, paymentarea, itemid, or userid).';
$string['webhook_missing_txnid'] = 'Webhook adjustment event is missing transaction ID.';
$string['paddle:manage'] = 'Manage Paddle payment gateway settings';
$string['paddle:viewreports'] = 'View Paddle transaction reports';

$string['privacy:metadata'] = 'The paygw_paddle plugin does not store any personal data.';
