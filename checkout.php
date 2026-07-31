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
 * Paddle composes the checkout URL as this page + ?_ptxn=<txn_...>.
 * Paddle.js v2 is loaded via a direct <script> tag (NOT through Moodle's AMD/RequireJS
 * system) to avoid two critical bugs:
 *
 *  1. AMD CONFLICT: $PAGE->requires->js_init_code() wraps code in require([],function(){...}).
 *     When Paddle.js is then loaded dynamically inside that wrapper, Paddle.js calls AMD's
 *     define() internally. RequireJS is already active and throws "Mismatched anonymous
 *     define() module", preventing Paddle from setting its global — causing "Paddle is not
 *     defined" and a broken checkout for the student.
 *
 *  2. S3 KEY TOO LONG: js_init_code() feeds the JS blob through Moodle's JS caching pipeline.
 *     URL-encoding the ~1500-char JS blob pushes the resulting cache/module key well above
 *     S3's 1024-char key limit, returning a KeyTooLongError XML response to the browser.
 *
 * v1.0.18 extends the fix further: even with a plain <script> tag, Moodle's require.min.js
 * (already loaded in $OUTPUT->header()) intercepts the define() call that Paddle.js makes
 * via its UMD wrapper and throws "Mismatched anonymous define() module: function(){...}".
 * This prevents Paddle.js reaching the window.Paddle = Paddle assignment, so "Paddle is
 * not defined" still fires. Fix: null out window.define and window.require in a preceding
 * inline <script> block so Paddle.js's UMD wrapper sees no AMD loader and falls through to
 * the plain global assignment. Both are restored immediately in a following inline <script>.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;

require_once(__DIR__ . '/../../../config.php');

$component = optional_param('component', '', PARAM_ALPHANUMEXT);
$paymentarea = optional_param('paymentarea', '', PARAM_ALPHANUMEXT);
$itemid = optional_param('itemid', 0, PARAM_INT);
$txnid = optional_param('_ptxn', '', PARAM_TEXT);

// FIX-PADDLE-CUSTOMER: Read customer data stored in session by pay.php POST.
// The session object uses camelCase property names matching the Paddle.js API.
// Cleared immediately so it is not reused on a page refresh.
global $SESSION;
$paddleCustomer = null;
if (!empty($SESSION->paddle_customer)) {
    $paddleCustomer = $SESSION->paddle_customer;
    // Use the txnid from the session when available (more reliable than URL).
    if (!empty($paddleCustomer->txnid) && empty($txnid)) {
        $txnid = $paddleCustomer->txnid;
    }
    unset($SESSION->paddle_customer);
}

$config = null;
$processurl = '';

if ($component !== '' && $paymentarea !== '' && $itemid > 0) {
    $config = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'paddle');
    $processurl = (new moodle_url('/payment/gateway/paddle/process.php', [
        'component' => $component,
        'paymentarea' => $paymentarea,
        'itemid' => $itemid,
    ]))->out(false);
} else {
    $config = (object)[
        'clienttoken' => get_config('paygw_paddle', 'clienttoken'),
        'environment' => get_config('paygw_paddle', 'environment'),
    ];
}

// Merge global config.
$global = get_config('paygw_paddle');
if (empty($config->clienttoken) && !empty($global->clienttoken)) {
    $config->clienttoken = $global->clienttoken;
}
if (empty($config->environment) && !empty($global->environment)) {
    $config->environment = $global->environment;
}

$token = (string)($config->clienttoken ?? '');
$env = (string)($config->environment ?? 'live');

$PAGE->set_url(new moodle_url('/payment/gateway/paddle/checkout.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('checkout', 'paygw_paddle'));
$PAGE->set_heading(get_string('checkout', 'paygw_paddle'));

echo $OUTPUT->header();

if (empty($token)) {
    echo $OUTPUT->notification(get_string('missingconfig', 'paygw_paddle'), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::tag('div', get_string('loadingcheckout', 'paygw_paddle'),
    ['id' => 'paddle-checkout-message', 'class' => 'text-center p-4']);

$txnidjs = json_encode($txnid);
$tokenjs = json_encode($token);
$processjs = json_encode($processurl);
$envjs = json_encode($env);

// FIX-PADDLE-INVOICE-NAME (v1.0.22): Build the customer object for
// Paddle.Checkout.open(). Properties use camelCase per the Paddle.js v2 API.
//
// IMPORTANT: Paddle.js v2's Checkout.open() customer object accepts `id`,
// `email`, `address`, and `business` — but NOT a top-level `name` field.
// The customer's name only appears on the invoice when Paddle resolves
// `customer.id` against a customer record that has `name` populated. That
// record is created server-side in paddle_helper::get_or_create_customer().
//
// We therefore prefer customer.id over customer.email. Email is only sent
// as a fallback if the server-side customer create raced/failed.
//
// We only forward `business` when the buyer typed a real company name on
// the pre-checkout form — sending business with the buyer's individual
// name would (incorrectly) produce a B2B invoice.
$customerjs = 'null';
if ($paddleCustomer) {
    $cust = [];
    if (!empty($paddleCustomer->customerId)) {
        $cust['id'] = (string)$paddleCustomer->customerId;
    } else if (!empty($paddleCustomer->email)) {
        // Fallback path: server didn't get a customer_id, so let the
        // overlay collect/find the customer by email. Name will be missing
        // from the invoice in this branch — treat it as a degraded mode.
        $cust['email'] = (string)$paddleCustomer->email;
    }
    $addr = [];
    if (!empty($paddleCustomer->firstLine))   { $addr['firstLine']   = (string)$paddleCustomer->firstLine; }
    if (!empty($paddleCustomer->city))        { $addr['city']        = (string)$paddleCustomer->city; }
    if (!empty($paddleCustomer->postalCode))  { $addr['postalCode']  = (string)$paddleCustomer->postalCode; }
    if (!empty($paddleCustomer->countryCode)) { $addr['countryCode'] = (string)$paddleCustomer->countryCode; }
    if (!empty($addr)) {
        $cust['address'] = $addr;
    }
    if (!empty($paddleCustomer->businessName)) {
        $cust['business'] = ['name' => (string)$paddleCustomer->businessName];
    }
    if (!empty($cust)) {
        $customerjs = json_encode($cust);
    }
}

// FIX v1.0.18: Moodle's require.min.js is already active on this page (loaded by
// $OUTPUT->header()). Even a plain synchronous <script> tag that loads Paddle.js will
// trigger the "Mismatched anonymous define() module" error because Paddle.js uses a UMD
// wrapper: if window.define is a function, it calls define() — RequireJS intercepts this,
// throws the mismatch error, and Paddle.js never reaches window.Paddle = Paddle.
// Solution: null out window.define (and window.require) in an inline <script> immediately
// before the Paddle.js <script> tag. Paddle's UMD wrapper sees no AMD loader and falls
// through to the plain global window.Paddle assignment. Restore immediately after load.
echo '<script>window.__pdef=window.define;window.__preq=window.require;window.define=undefined;window.require=undefined;</script>' . "\n";
echo '<script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>' . "\n";
echo '<script>window.define=window.__pdef;window.require=window.__preq;delete window.__pdef;delete window.__preq;</script>' . "\n";

// FIX: Inline init code as a plain <script> block. Paddle is guaranteed to be defined
// because the synchronous <script> above has already fully executed.
// FIX-PADDLE-CUSTOMER: Strip _ptxn from the URL via history.replaceState BEFORE
// Paddle.Initialize() so Paddle.js does NOT auto-open the checkout overlay (which
// would ignore the customer object). We then call Paddle.Checkout.open() ourselves
// with both transactionId and the customer object (camelCase properties as required
// by Paddle.js v2). This is the only way to skip Paddle's first form (email/country/
// postcode) and ensure full name, address, and city appear on the generated invoice.
$js = <<<JS
(function(){
  var txnid = {$txnidjs};
  var processUrl = {$processjs};
  var env = {$envjs};
  var customerData = {$customerjs};

  // Strip _ptxn from the current URL so Paddle.js does not auto-open the
  // checkout when Initialize() is called (it watches for _ptxn in the URL).
  try {
    if (window.history && window.history.replaceState) {
      var u = new URL(window.location.href);
      u.searchParams.delete('_ptxn');
      window.history.replaceState({}, '', u.toString());
    }
  } catch (e) { /* non-critical */ }

  try {
    if (env === 'sandbox') {
      Paddle.Environment.set('sandbox');
    }
    Paddle.Initialize({
      token: {$tokenjs},
      eventCallback: function(event) {
        try {
          if (!processUrl) { return; }
          var name = event && (event.name || event.type);
          if (name === 'checkout.completed' || name === 'checkout.success') {
            var tid = (event.data && (event.data.transaction_id ||
              (event.data.transaction && event.data.transaction.id) ||
              event.data.id)) || txnid;
            var u = processUrl + '&transaction_id=' + encodeURIComponent(tid || '');
            window.location.assign(u);
          }
        } catch (e) {
          console.error('Paddle event error:', e);
        }
      }
    });

    // Explicitly open the checkout with the transaction ID and customer data.
    // Passing 'customer' here pre-fills all fields and skips Paddle's first
    // form, ensuring the name and address flow through to the invoice.
    if (txnid) {
      var openParams = { transactionId: txnid };
      if (customerData) {
        var cust = {};
        if (customerData.id) {
          // FIX-CUSTOMER-ID-ADDRESS-CONFLICT (v1.0.31): When customer.id is set,
          // do NOT also pass customer.address as a raw address object.
          // The address was already attached server-side:
          //   (1) POST /customers/{id}/addresses → address_id
          //   (2) POST /transactions with address_id
          // Paddle.Checkout.open() rejects the combination of customer.id +
          // a raw customer.address object and shows "Something went wrong".
          // With customer.id alone, Paddle resolves the pre-linked address
          // from the transaction record — no address override needed here.
          cust.id = customerData.id;
        } else {
          // Fallback: no customer_id from server (customer-create failed).
          // Pass email so Paddle can find/create the customer, and include
          // the address so fields are pre-filled in the overlay.
          if (customerData.email) { cust.email = customerData.email; }
          if (customerData.address && customerData.address.countryCode) {
            cust.address = customerData.address;
          }
        }
        if (customerData.business) { cust.business = customerData.business; }
        if (Object.keys(cust).length > 0) { openParams.customer = cust; }
      }
      Paddle.Checkout.open(openParams);
    }
  } catch (e) {
    console.error('Paddle init error:', e);
    var msg = document.getElementById('paddle-checkout-message');
    if (msg) { msg.textContent = 'Failed to load checkout. Please try again.'; }
  }
})();
JS;

echo '<script>' . "\n" . $js . "\n" . '</script>' . "\n";

echo $OUTPUT->footer();
