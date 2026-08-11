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
 * Version metadata for the Paddle payment gateway plugin.
 *
 * v1.0.22: FIX-PADDLE-INVOICE-NAME — Generated Paddle invoices were issued
 *          to the wrong "name". Root cause: paddle_helper::create_transaction
 *          was sending the buyer's individual full name in `business.name`
 *          (which converts every transaction to B2B and labels the person
 *          as a company), and was never sending `customer.name` at all.
 *          Paddle's REST API DOES expose customer.name on POST /customers
 *          (the original code's comment claiming otherwise was wrong).
 *          Fixed by:
 *          (1) classes/paddle_helper.php — added get_or_create_customer()
 *              (POST /customers with name+email; lookup-by-email and
 *              PATCH name when stale) and create_address(), create_business().
 *              create_transaction() now sends customer_id + address_id
 *              (and business_id ONLY when buyer entered a real company name).
 *              Returns customer_id as a third tuple element.
 *          (2) pay.php — added optional "Company name" field. customer_id
 *              from create_transaction is now stashed in $SESSION->paddle_customer
 *              so checkout.php can forward it.
 *          (3) checkout.php — Paddle.Checkout.open() now passes customer.id
 *              instead of just customer.email. With customer.id, Paddle's
 *              overlay resolves the pre-created customer record (which has
 *              name populated) and the invoice "Bill to" block renders the
 *              buyer's actual name. business is only forwarded when buyer
 *              typed a company name.
 *          (4) lang/en/paygw_paddle.php — companyname/_placeholder/_help
 *              strings added.
 *          Added api_get() and api_patch() helpers (Moodle's curl wrapper
 *          has no native PATCH; uses CURLOPT_CUSTOMREQUEST).
 *          No DB schema changes. Sandbox-verifiable via
 *          scripts/paddle-invoice-verify.cjs (PADDLE_SANDBOX_API_KEY).
 * v1.0.13: FIX: Three tester-reported issues resolved.
 *          (1) AMD "No define call for paygw_paddle/gateways_modal" — amd/build/gateways_modal.js
 *          (non-minified) was missing. Moodle dev/debug mode loads amd/build/MODULE.js (not .min.js),
 *          causing a 404 → RequireJS reports "No define call". Added the missing build file.
 *          (2) [[gatewayname]] — 'gatewayname' lang string already defined in v1.0.12. If still
 *          visible, purge Moodle's lang/JS caches via Site Admin > Development > Purge all caches.
 *          (3) Price ID placement — added new "Default Product ID" (pro_...) field to gateway
 *          settings. When configured, the plugin creates Paddle transactions with a dynamic/inline
 *          price derived directly from Moodle's Enrolment on Payment cost — no per-course Paddle
 *          Price IDs required. The existing catalog Price ID fields remain for backwards compatibility.
 *          Added amount_to_minor_unit() helper for correct zero-decimal currency handling.
 * v1.0.12: FIX: Added missing AMD module amd/src/gateways_modal.js — Moodle's core payment
 *          modal requires paygw_paddle/gateways_modal to be a valid RequireJS module that
 *          exports process(). Without it, clicking "Proceed" throws "No define call for
 *          paygw_paddle/gateways_modal". Also added missing 'gatewayname' lang string —
 *          payment modal showed [[gatewayname]] instead of "Paddle". Also fixed webhook.php
 *          to try per-account gateway configs when global webhooksecret is not set.
 * v1.0.10: FIX: Set $settings = null in settings.php to prevent Moodle core from creating a duplicate
 *          admin page. paygw plugins don't need standalone settings — credentials are configured
 *          per Payment Account via the gateway form. Fully resolves "Duplicate admin page name" error.
 * v1.0.9: FIX: Removed duplicate admin_settingpage creation — Moodle core (paygw plugininfo) auto-creates the
 *         $settings page. Plugin now only adds headings/settings to the pre-existing $settings object.
 *         Fixes "Duplicate admin page name: paymentgatewaypaddle" and navigation node intersect errors.
 * v1.0.8: FIX: Settings page section name changed to 'paymentgatewaypaddle' to match Moodle's standard paygw naming convention
 * v1.0.7: FIX: Registered settings page as explicit admin_settingpage to fix sectionerror when accessed from Quick Links block
 * v1.0.6: FIX: Added missing lang strings for paddle:manage and paddle:viewreports capabilities - fixes Moodle role definition page errors
 * v1.0.5: Fixed settings.php — use Moodle's pre-created $settings page instead of replacing it,
 *         so the "Settings" link appears on the Manage Payment Gateways page.
 *         Added comprehensive Moodle payment system documentation to docs page.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'paygw_paddle';
$plugin->version   = 2026072200634;
$plugin->requires  = 2022041900; // Moodle 4.0.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0.32'; // SAVEPOINT-BUMP v1.0.29: no-op savepoint marker for clean upgrade path. No DB schema changes.; // FIX-REPORTS-BR-TAG (v1.0.28): reports.php line 151 — html_writer::tag('br') replaced with html_writer::empty_tag('br'). Moodle's html_writer::tag() requires at least 2 args; using it with 1 arg caused "Too few arguments" fatal error on the Transactions report page. No DB changes. Savepoint 2026052900628.
// FIX-CURL-BATCH: paddle_helper.php switched from raw curl_init() to Moodle \curl wrapper + write_close() for GET/POST calls (PATCH retains raw curl). No DB schema changes. Savepoint 2026051200627. // LABEL-FIX: Removed "(optional)" text from Address Line 1, Suburb / City, and Postcode field labels — these fields are now mandatory and the label was causing confusion. // FIX-PADDLE-INVOICE-NAME: Buyer name now appears on Paddle invoices. Switched from sending the individual's name in business.name (which created a B2B invoice with the person labelled as a company and never populated customer.name) to the documented pattern: POST /customers with name+email → POST /addresses → POST /transactions with customer_id + address_id. Optional Company Name field in pay.php turns the transaction B2B only when the buyer actually represents a business. checkout.php now passes customer.id (not just email) to Paddle.Checkout.open() so the overlay resolves the pre-created customer record on the invoice. PHP: classes/paddle_helper.php, pay.php, checkout.php, lang/en/paygw_paddle.php. No DB schema changes. Sandbox-verifiable via scripts/paddle-invoice-verify.cjs (PADDLE_SANDBOX_API_KEY). version.php → 2026050100622. // FIX-PADDLE-CUSTOMER: Full name and postal address now appear on Paddle invoices. Root cause: checkout.php relied on Paddle.js auto-opening the checkout from the _ptxn URL parameter, which ignores the customer object entirely. Even though pay.php already sent business.name/address.first_line/address.city to the Paddle Billing API when creating the transaction, Paddle's checkout overlay does not pre-fill or skip its form from server-side transaction data — so the invoice used only what the student typed into Paddle's form. Fix: (1) pay.php stores customer data (email, businessName, firstLine, city, countryCode — camelCase for Paddle.js) in $SESSION->paddle_customer before redirecting; (2) checkout.php reads and clears the session, strips _ptxn from the URL via history.replaceState() before Paddle.Initialize() to prevent auto-open, then explicitly calls Paddle.Checkout.open({ transactionId, customer }) with the full customer object. This skips Paddle's first form and ensures the invoice contains the student's full name and address. PHP only: pay.php, checkout.php. No DB schema changes. version.php → 2026043000621.
