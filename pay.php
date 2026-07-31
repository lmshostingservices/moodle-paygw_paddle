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
 * On GET: displays a pre-checkout billing details form so the student can
 * confirm (or enter) their full name and address before Paddle's checkout
 * opens. Paddle's hosted checkout UI only collects email — name, street
 * address, suburb/city, and postcode must be collected here and passed to
 * the Paddle Billing API so the full address appears on invoices.
 *
 * On POST (valid sesskey): reads the submitted billing fields, creates
 * the Paddle transaction via the Billing API with those details, and
 * redirects the student to the Paddle hosted checkout URL.
 *
 * FIX-PADDLE-ADDRESS (v1.0.23): The street address (first_line) was not
 * appearing on Paddle invoices even though address line 1 was collected
 * in the form. Root cause: postcode was NOT collected by our form, so we
 * relied on Paddle's overlay to collect it. When Paddle's overlay completed,
 * it assembled the final address from only what it collected (postcode +
 * country) and what was already in our JS customer.address object (city +
 * countryCode). The firstLine from customer.address was silently dropped
 * because Paddle's overlay has no street-address field to submit it through.
 * Fix: collect postcode here in our pre-checkout form, pass postal_code to
 * the Paddle /addresses API so the stored address record is complete
 * (first_line + city + postal_code + country_code), and pass postalCode in
 * the JS Checkout.open() customer.address so Paddle's overlay pre-fills all
 * fields from our complete address object without needing to override anything.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_payment\helper;
use paygw_paddle\paddle_helper;

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

require_login();

$component   = required_param('component',   PARAM_ALPHANUMEXT);
$paymentarea = required_param('paymentarea', PARAM_ALPHANUMEXT);
$itemid      = required_param('itemid',      PARAM_INT);

$payable     = helper::get_payable($component, $paymentarea, $itemid);
$cost        = $payable->get_amount();
$currency    = $payable->get_currency();
$description = helper::get_cost_as_string($cost, $currency);
$config      = (object) helper::get_gateway_configuration($component, $paymentarea, $itemid, 'paddle');

// Merge global settings as defaults.
$global = get_config('paygw_paddle');
foreach (['apikey', 'clienttoken', 'webhooksecret', 'defaultproductid', 'defaultpriceid', 'checkouturl', 'environment'] as $k) {
    if (empty($config->$k) && !empty($global->$k)) {
        $config->$k = $global->$k;
    }
}

if (empty($config->apikey) || empty($config->clienttoken) || empty($config->webhooksecret)) {
    throw new moodle_exception('missingconfig', 'paygw_paddle');
}

$env    = $config->environment ?? 'live';
$paddle = new paddle_helper($config->apikey, $env);

// ─── POST: create transaction with user-supplied billing data ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $fullname    = trim(optional_param('paddle_fullname',  '', PARAM_TEXT));
    $companyname = trim(optional_param('paddle_company',   '', PARAM_TEXT));
    $address     = trim(optional_param('paddle_address',   '', PARAM_TEXT));
    $city        = trim(optional_param('paddle_city',      '', PARAM_TEXT));
    $postcode    = trim(optional_param('paddle_postcode',  '', PARAM_TEXT));
    // FIX-COUNTRY-REQUIRED (v1.0.30): prefer form-submitted country (shown when the
    // Moodle profile has no country set) so countryCode is never empty. Without a
    // valid countryCode the address is skipped server-side and checkout.php sends a
    // partial address object (no countryCode) to Paddle.Checkout.open(), which causes
    // Paddle's overlay to show "Something went wrong".
    $formcountry = strtoupper(trim(optional_param('paddle_country', '', PARAM_ALPHA)));
    $country     = ($formcountry !== '' && strlen($formcountry) === 2)
        ? $formcountry
        : strtoupper(trim($USER->country ?? ''));

    $customerdata = [
        'fullname'    => ($fullname    !== '') ? $fullname    : null,
        'companyname' => ($companyname !== '') ? $companyname : null,
        'address'     => ($address     !== '') ? $address     : null,
        'city'        => ($city        !== '') ? $city        : null,
        'postcode'    => ($postcode    !== '') ? $postcode    : null,
        'email'       => $USER->email,
        'country'     => $country,
    ];

    // FIX-PADDLE-INVOICE-NAME (v1.0.22): create_transaction now also returns
    // the Paddle customer_id (ctm_...) so checkout.php can pass it to
    // Paddle.Checkout.open() as customer.id, ensuring the pre-created
    // customer's name appears on the invoice.
    [$txnid, $checkouturl, $customerid] = $paddle->create_transaction(
        $config, $payable, $description, $cost,
        $component, $paymentarea, $itemid, $USER->id,
        $customerdata
    );

    // Stash for checkout.php. Pass customer_id (preferred) plus complete
    // address so Paddle.Checkout.open() has the full address object including
    // postalCode — this prevents Paddle from needing to collect postcode in
    // its overlay, which was the mechanism that dropped first_line.
    $SESSION->paddle_customer = (object)[
        'txnid'        => $txnid,
        'customerId'   => $customerid ?? '',
        'email'        => $USER->email ?? '',
        'firstLine'    => $address,
        'city'         => $city,
        'postalCode'   => $postcode,
        'countryCode'  => !empty($USER->country) ? strtoupper(trim($USER->country)) : '',
        // Only forward business when buyer typed a real company; otherwise
        // we leave it empty so Paddle issues an individual (non-B2B) invoice.
        'businessName' => $companyname,
    ];

    redirect(new moodle_url($checkouturl));
}

// ─── GET: show pre-checkout billing details form ──────────────────────────────
$PAGE->set_url(new moodle_url('/payment/gateway/paddle/pay.php', [
    'component'   => $component,
    'paymentarea' => $paymentarea,
    'itemid'      => $itemid,
]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('billingdetails', 'paygw_paddle'));
$PAGE->set_heading(get_string('billingdetails', 'paygw_paddle'));

// Pre-fill from Moodle user profile.
$defaultname     = trim(($USER->firstname ?? '') . ' ' . ($USER->lastname ?? ''));
$defaultaddress  = $USER->address ?? '';
$defaultcity     = $USER->city    ?? '';
$defaultpostcode = '';
$defaultcountry  = strtoupper(trim($USER->country ?? ''));

// Format cost for display.
$costdisplay = number_format((float)$cost, 2) . ' ' . strtoupper($currency);

echo $OUTPUT->header();

echo html_writer::start_div('paygw-paddle-billing-wrap',
    ['style' => 'max-width:480px;margin:32px auto;padding:0 16px;font-family:inherit;']);

// Order summary card.
echo html_writer::start_div('', ['style' =>
    'background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;' .
    'padding:16px 20px;margin-bottom:24px;']);
echo html_writer::tag('p',
    get_string('ordertotal', 'paygw_paddle'),
    ['style' => 'margin:0 0 4px 0;font-size:13px;color:#64748b;font-weight:600;' .
                'text-transform:uppercase;letter-spacing:0.05em;']);
echo html_writer::tag('p',
    html_writer::tag('strong', $costdisplay, ['style' => 'font-size:22px;color:#0f172a;']),
    ['style' => 'margin:0;']);
echo html_writer::end_div();

// Heading + description.
echo html_writer::tag('h4',
    get_string('billingdetails', 'paygw_paddle'),
    ['style' => 'margin:0 0 6px 0;font-size:18px;font-weight:700;color:#0f172a;']);
echo html_writer::tag('p',
    get_string('billingdetails_desc', 'paygw_paddle'),
    ['style' => 'margin:0 0 20px 0;font-size:14px;color:#64748b;line-height:1.5;']);

// Form.
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => '',
    'autocomplete' => 'on',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'component',   'value' => $component]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'paymentarea', 'value' => $paymentarea]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'itemid',      'value' => $itemid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',     'value' => sesskey()]);

// ── Full Name (required) ──────────────────────────────────────────────────────
echo html_writer::start_div('', ['style' => 'margin-bottom:16px;']);
echo html_writer::tag('label',
    get_string('fullname', 'paygw_paddle') .
    html_writer::tag('span', ' *', ['style' => 'color:#ef4444;']),
    ['for' => 'paddle_fullname',
     'style' => 'display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;']);
echo html_writer::empty_tag('input', [
    'type'         => 'text',
    'id'           => 'paddle_fullname',
    'name'         => 'paddle_fullname',
    'class'        => 'form-control',
    'value'        => s($defaultname),
    'required'     => 'required',
    'autocomplete' => 'name',
    'placeholder'  => get_string('fullname_placeholder', 'paygw_paddle'),
    'style'        => 'width:100%;box-sizing:border-box;',
]);
echo html_writer::end_div();

// ── Company Name (optional, B2B only) ─────────────────────────────────────────
// Only filled when the buyer is purchasing on behalf of a business. When
// non-empty the plugin creates a Paddle Business linked to the customer and
// the resulting invoice is issued in B2B form (with a tax-id slot). When
// blank, the invoice is issued to the individual.
echo html_writer::start_div('', ['style' => 'margin-bottom:16px;']);
echo html_writer::tag('label',
    get_string('companyname', 'paygw_paddle'),
    ['for' => 'paddle_company',
     'style' => 'display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;']);
echo html_writer::empty_tag('input', [
    'type'         => 'text',
    'id'           => 'paddle_company',
    'name'         => 'paddle_company',
    'class'        => 'form-control',
    'value'        => '',
    'autocomplete' => 'organization',
    'placeholder'  => get_string('companyname_placeholder', 'paygw_paddle'),
    'style'        => 'width:100%;box-sizing:border-box;',
]);
echo html_writer::tag('p',
    get_string('companyname_help', 'paygw_paddle'),
    ['style' => 'margin:6px 0 0 0;font-size:12px;color:#64748b;line-height:1.4;']);
echo html_writer::end_div();

// ── Address Line 1 (required) ─────────────────────────────────────────────────
echo html_writer::start_div('', ['style' => 'margin-bottom:16px;']);
echo html_writer::tag('label',
    get_string('addressline1', 'paygw_paddle') .
    html_writer::tag('span', ' *', ['style' => 'color:#ef4444;']),
    ['for' => 'paddle_address',
     'style' => 'display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;']);
echo html_writer::empty_tag('input', [
    'type'         => 'text',
    'id'           => 'paddle_address',
    'name'         => 'paddle_address',
    'class'        => 'form-control',
    'value'        => s($defaultaddress),
    'required'     => 'required',
    'autocomplete' => 'street-address',
    'placeholder'  => get_string('addressline1_placeholder', 'paygw_paddle'),
    'style'        => 'width:100%;box-sizing:border-box;',
]);
echo html_writer::end_div();

// ── City / Suburb (required) ──────────────────────────────────────────────────
echo html_writer::start_div('', ['style' => 'margin-bottom:16px;']);
echo html_writer::tag('label',
    get_string('city', 'paygw_paddle') .
    html_writer::tag('span', ' *', ['style' => 'color:#ef4444;']),
    ['for' => 'paddle_city',
     'style' => 'display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;']);
echo html_writer::empty_tag('input', [
    'type'         => 'text',
    'id'           => 'paddle_city',
    'name'         => 'paddle_city',
    'class'        => 'form-control',
    'value'        => s($defaultcity),
    'required'     => 'required',
    'autocomplete' => 'address-level2',
    'placeholder'  => get_string('city_placeholder', 'paygw_paddle'),
    'style'        => 'width:100%;box-sizing:border-box;',
]);
echo html_writer::end_div();

// ── Postcode (required) ───────────────────────────────────────────────────────
echo html_writer::start_div('', ['style' => 'margin-bottom:20px;']);
echo html_writer::tag('label',
    get_string('postcode', 'paygw_paddle') .
    html_writer::tag('span', ' *', ['style' => 'color:#ef4444;']),
    ['for' => 'paddle_postcode',
     'style' => 'display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;']);
echo html_writer::empty_tag('input', [
    'type'         => 'text',
    'id'           => 'paddle_postcode',
    'name'         => 'paddle_postcode',
    'class'        => 'form-control',
    'value'        => s($defaultpostcode),
    'required'     => 'required',
    'autocomplete' => 'postal-code',
    'placeholder'  => get_string('postcode_placeholder', 'paygw_paddle'),
    'style'        => 'width:100%;box-sizing:border-box;',
]);
echo html_writer::end_div();

// ── Country ───────────────────────────────────────────────────────────────────
// FIX-COUNTRY-REQUIRED (v1.0.30): if the Moodle user profile has a country set,
// emit it as a hidden field. If blank, show a required <select> so the student
// can supply it — without a valid 2-letter country code the Paddle address API
// call is skipped and Paddle's overlay receives a partial address (no countryCode)
// which causes "Something went wrong" on the checkout screen.
if ($defaultcountry !== '') {
    echo html_writer::empty_tag('input', [
        'type'  => 'hidden',
        'name'  => 'paddle_country',
        'value' => s($defaultcountry),
    ]);
} else {
    // Country not set in profile — ask the student.
    $countryoptions = [
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'GB' => 'United Kingdom',
        'US' => 'United States',
        'CA' => 'Canada',
        'IE' => 'Ireland',
        'ZA' => 'South Africa',
        'IN' => 'India',
        'PH' => 'Philippines',
        'SG' => 'Singapore',
        'MY' => 'Malaysia',
        'ID' => 'Indonesia',
        'TH' => 'Thailand',
        'VN' => 'Vietnam',
        'PK' => 'Pakistan',
        'BD' => 'Bangladesh',
        'LK' => 'Sri Lanka',
        'CN' => 'China',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'AE' => 'United Arab Emirates',
        'SA' => 'Saudi Arabia',
        'PG' => 'Papua New Guinea',
        'FJ' => 'Fiji',
        'WS' => 'Samoa',
        'TO' => 'Tonga',
        'SB' => 'Solomon Islands',
        'VU' => 'Vanuatu',
    ];
    echo html_writer::start_div('', ['style' => 'margin-bottom:20px;']);
    echo html_writer::tag('label',
        get_string('country', 'paygw_paddle') .
        html_writer::tag('span', ' *', ['style' => 'color:#ef4444;']),
        ['for' => 'paddle_country',
         'style' => 'display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;']);
    $selecthtml = '<select id="paddle_country" name="paddle_country" required'
        . ' autocomplete="country" class="form-control"'
        . ' style="width:100%;box-sizing:border-box;">'
        . '<option value="">' . s(get_string('country_placeholder', 'paygw_paddle')) . '</option>';
    foreach ($countryoptions as $code => $label) {
        $selecthtml .= '<option value="' . s($code) . '">' . s($label) . '</option>';
    }
    $selecthtml .= '</select>';
    echo $selecthtml;
    echo html_writer::end_div();
}

// ── Submit button ─────────────────────────────────────────────────────────────
echo html_writer::tag('button',
    get_string('continuetopayment', 'paygw_paddle'),
    ['type'  => 'submit',
     'class' => 'btn btn-primary btn-block w-100',
     'style' => 'font-size:15px;font-weight:600;padding:12px 24px;width:100%;']);

echo html_writer::end_tag('form');
echo html_writer::end_div();

echo $OUTPUT->footer();
