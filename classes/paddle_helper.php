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
 * Paddle Billing API helper for paygw_paddle.
 *
 * Handles API calls (create transaction), webhook signature verification,
 * and event parsing for the Paddle Billing API v2.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_paddle;

defined('MOODLE_INTERNAL') || die();

class paddle_helper {

    /** @var string Paddle API key (server-side). */
    private $apikey;

    /** @var string API base URL. */
    private $baseurl;

    /**
     * Constructor.
     *
     * @param string $apikey Paddle API key.
     * @param string $environment 'live' or 'sandbox'.
     */
    public function __construct(string $apikey, string $environment = 'live') {
        $this->apikey = $apikey;
        $this->baseurl = ($environment === 'sandbox')
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';
    }

    /**
     * Create a Paddle transaction for one-time payment.
     *
     * @param \stdClass $config Gateway configuration.
     * @param object $payable The Moodle payable object.
     * @param string $description Human-readable description.
     * @param float $amount Payment amount.
     * @param string $component Payment component.
     * @param string $paymentarea Payment area.
     * @param int $itemid Item ID.
     * @param int $userid Moodle user ID.
     * @return array [paddle_transaction_id, checkout_url]
     * @throws \moodle_exception On API failure.
     */
    public function create_transaction(
        \stdClass $config,
        $payable,
        string $description,
        float $amount,
        string $component,
        string $paymentarea,
        int $itemid,
        int $userid,
        array $customerdata = []
    ): array {
        global $DB;

        $priceid = '';

        // Check course-specific price mapping first.
        if ($component === 'enrol_fee' && $paymentarea === 'fee') {
            $enrolinstance = $DB->get_record('enrol', ['id' => $itemid], 'courseid');
            if ($enrolinstance) {
                $pricemap = $DB->get_record('paygw_paddle_prices', [
                    'courseid' => $enrolinstance->courseid,
                    'active' => 1,
                ]);
                if ($pricemap && !empty($pricemap->paddle_price_id)) {
                    $priceid = $pricemap->paddle_price_id;
                }
            }
        }

        // Fall back to default catalog Price ID from config.
        if (empty($priceid)) {
            $priceid = $config->defaultpriceid ?? '';
        }

        $currency = $payable->get_currency();

        // Build the items array:
        //  - If a catalog Price ID is configured → use it (Paddle controls the exact amount).
        //  - Otherwise if a Product ID is configured → create a dynamic inline price using
        //    the amount from Moodle's payable. This lets admins configure pricing entirely
        //    within Moodle's Enrolment on Payment settings, with only a Product ID needed
        //    globally in the Paddle gateway account.
        if (!empty($priceid)) {
            $items = [['price_id' => $priceid, 'quantity' => 1]];
        } else {
            $productid = $config->defaultproductid ?? '';
            if (empty($productid)) {
                throw new \moodle_exception('missingpriceid', 'paygw_paddle');
            }
            $items = [[
                'price' => [
                    'description' => $description,
                    'product_id' => $productid,
                    'unit_price' => [
                        'amount' => (string)self::amount_to_minor_unit($amount, $currency),
                        'currency_code' => $currency,
                    ],
                    'quantity' => ['minimum' => 1, 'maximum' => 1],
                    'tax_mode' => 'account_setting',
                ],
                'quantity' => 1,
            ]];
        }

        $checkouturl = !empty($config->checkouturl)
            ? $config->checkouturl
            : (new \moodle_url('/payment/gateway/paddle/checkout.php', [
                'component' => $component,
                'paymentarea' => $paymentarea,
                'itemid' => $itemid,
            ]))->out(false);

        // FIX-PADDLE-INVOICE-NAME (v1.0.22): Paddle's `customer` object DOES
        // expose a `name` field on the REST API (Create-Customer schema), and
        // that name is what appears in the "Bill to" block of the generated
        // invoice. The previous implementation put the individual's name into
        // `business.name`, which (a) left customer.name blank in Paddle and
        // (b) silently flipped every transaction to a B2B invoice with the
        // student's name labelled as a "Business name" — wrong layout, wrong
        // tax treatment.
        //
        // Correct pattern (per Paddle docs):
        //   1. POST /customers     → returns customer_id (with name + email)
        //   2. POST /addresses     → returns address_id (linked to customer_id)
        //   3. (optional) POST /businesses → only when buyer entered a real
        //      company name on the pre-checkout form; this then makes it a
        //      proper B2B invoice with the correct tax-id slot
        //   4. POST /transactions  → uses customer_id + address_id (+ business_id
        //      when supplied) so the invoice resolves the same name + address
        //      every time, even on retries.
        global $USER;
        $prefillUser = ($userid > 0 && !empty($USER->id) && $USER->id == $userid)
            ? $USER
            : $DB->get_record('user', ['id' => $userid], 'firstname,lastname,email,address,city,country');

        $email = $customerdata['email'] ?? $prefillUser->email ?? '';
        $fullname = $customerdata['fullname']
            ?? trim(($prefillUser->firstname ?? '') . ' ' . ($prefillUser->lastname ?? ''));
        $addressline = $customerdata['address'] ?? $prefillUser->address ?? '';
        $city        = $customerdata['city']     ?? $prefillUser->city    ?? '';
        $postcode    = $customerdata['postcode'] ?? '';
        $country     = $customerdata['country']  ?? $prefillUser->country ?? '';
        $countryCode = (strlen(trim($country)) === 2) ? strtoupper(trim($country)) : '';
        $companyname = !empty($customerdata['companyname']) ? trim($customerdata['companyname']) : '';

        $customerid = '';
        $addressid = '';
        $businessid = '';

        // ── 1. Customer (with the actual full name) ─────────────────────────
        if (!empty($email)) {
            try {
                $customerid = $this->get_or_create_customer($email, $fullname);
            } catch (\Throwable $e) {
                // Don't break checkout if customer-create races with an existing
                // record we couldn't read; the inline fallback below still works.
                debugging('paygw_paddle: customer create failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // ── 2. Address (attached to customer) ───────────────────────────────
        if (!empty($customerid) && !empty($countryCode)) {
            try {
                $addressid = $this->create_address($customerid, $addressline, $city, $postcode, $countryCode);
            } catch (\Throwable $e) {
                debugging('paygw_paddle: address create failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // ── 3. Business (B2B, ONLY when buyer typed a real company name) ────
        if (!empty($customerid) && $companyname !== '') {
            try {
                $businessid = $this->create_business($customerid, $companyname);
            } catch (\Throwable $e) {
                debugging('paygw_paddle: business create failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // ── 4. Transaction ──────────────────────────────────────────────────
        $payload = [
            'items' => $items,
            'custom_data' => [
                'component'   => $component,
                'paymentarea' => $paymentarea,
                'itemid'      => (string)$itemid,
                'userid'      => (string)$userid,
            ],
            'checkout' => [
                'url' => $checkouturl,
            ],
        ];

        if (!empty($customerid)) {
            $payload['customer_id'] = $customerid;
        } else if (!empty($email)) {
            // Fall-back inline customer (no name field on this path — Paddle's
            // Create-Customer route is preferred and is hit above; this branch
            // only runs if that POST failed).
            $payload['customer'] = ['email' => $email];
        }
        if (!empty($addressid)) {
            $payload['address_id'] = $addressid;
        }
        if (!empty($businessid)) {
            $payload['business_id'] = $businessid;
        }

        $response = $this->api_post('/transactions', $payload);

        if (empty($response['data']['id'])) {
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                'Paddle API did not return a transaction ID. Response: ' . json_encode($response));
        }

        $txnid = $response['data']['id'];

        // Build checkout URL: Paddle appends ?_ptxn=txn_... to the checkout.url.
        $txncheckouturl = $response['data']['checkout']['url'] ?? '';
        if (empty($txncheckouturl)) {
            $txncheckouturl = $checkouturl . (strpos($checkouturl, '?') !== false ? '&' : '?') . '_ptxn=' . urlencode($txnid);
        }

        // Store pending transaction.
        $record = new \stdClass();
        $record->component = $component;
        $record->paymentarea = $paymentarea;
        $record->itemid = $itemid;
        $record->paddle_transaction_id = $txnid;
        $record->paddle_customer_id = $response['data']['customer_id'] ?? $customerid;
        $record->userid = $userid;
        $record->amount = $amount;
        $record->currency = $payable->get_currency();
        $record->tax = 0;
        $record->status = 'pending';
        $record->timecreated = time();
        $record->timemodified = time();
        $DB->insert_record('paygw_paddle_transactions', $record);

        return [$txnid, $txncheckouturl, $customerid];
    }

    /**
     * Find a Paddle customer by email or create a new one. If the existing
     * customer's name is blank or differs from $name, PATCH it so the
     * invoice "Bill to" block always reflects the latest pre-checkout form.
     *
     * @param string $email Customer email (the unique key in Paddle).
     * @param string $name Customer's full name (becomes Bill-to name).
     * @return string Paddle customer_id ("ctm_...")
     * @throws \moodle_exception
     */
    public function get_or_create_customer(string $email, string $name): string {
        $email = trim($email);
        $name = trim($name);
        if ($email === '') {
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                'Cannot create a Paddle customer without an email.');
        }

        // FIX-PADDLE-CUSTOMER-EMAIL-LOOKUP (v1.0.24): The previous implementation used
        // GET /customers?email[]=... which is NOT a valid Paddle API filter parameter.
        // Paddle silently ignored it and returned ALL customers (first page, 1 per page),
        // so data[0] was always the most-recently-created customer regardless of email.
        // This caused: (a) the wrong customer's name to be PATCHed, (b) every new
        // transaction to be linked to that wrong ctm_ ID, and (c) names appearing on
        // the wrong customer in the Paddle dashboard. The correct Paddle API parameter
        // is 'search' which does a full-text search across customer fields. We then do
        // an exact lowercase email match in the returned results so a partial-match false
        // positive can never clobber or reuse the wrong customer record.
        $list = $this->api_get('/customers?search=' . urlencode($email) . '&per_page=10');
        $existing = null;
        foreach ($list['data'] ?? [] as $candidate) {
            if (isset($candidate['email']) &&
                strtolower(trim($candidate['email'])) === strtolower($email)) {
                $existing = $candidate;
                break;
            }
        }
        if ($existing && !empty($existing['id'])) {
            $cid = $existing['id'];
            // Sync name if the buyer typed a different one this time.
            if ($name !== '' && (empty($existing['name']) || $existing['name'] !== $name)) {
                try {
                    $this->api_patch('/customers/' . $cid, ['name' => $name]);
                } catch (\Throwable $e) {
                    debugging('paygw_paddle: customer name patch failed: ' . $e->getMessage(),
                        DEBUG_DEVELOPER);
                }
            }
            return $cid;
        }

        $body = ['email' => $email];
        if ($name !== '') {
            $body['name'] = $name;
        }
        $resp = $this->api_post('/customers', $body);
        if (empty($resp['data']['id'])) {
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                'Paddle did not return a customer_id. Response: ' . json_encode($resp));
        }
        return $resp['data']['id'];
    }

    /**
     * Create a new address linked to a customer.
     *
     * Paddle requires `country_code` (ISO 3166-1 alpha-2). Other fields are
     * optional but must all be supplied when available so the complete address
     * (first_line + city + postal_code + country_code) appears on the invoice.
     *
     * FIX-PADDLE-ADDRESS (v1.0.23): added $postalCode parameter. Previously
     * postal_code was not sent to the Paddle addresses API, so Paddle's overlay
     * had to collect it separately. When the overlay submitted, it only carried
     * the fields it collected (postcode + country) — first_line was dropped.
     * Passing postal_code here gives Paddle a complete address upfront so the
     * overlay pre-fills from our record without creating a new incomplete one.
     *
     * @param string $customerid  Paddle customer_id ("ctm_...").
     * @param string $firstLine   Street address line 1 (optional).
     * @param string $city        City / suburb (optional).
     * @param string $postalCode  Postcode / ZIP (optional).
     * @param string $countryCode 2-letter ISO 3166-1 country code (required).
     * @return string Paddle address_id ("add_...")
     * @throws \moodle_exception
     */
    public function create_address(string $customerid, string $firstLine, string $city, string $postalCode, string $countryCode): string {
        if ($countryCode === '' || strlen($countryCode) !== 2) {
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                'Cannot create a Paddle address without a 2-letter country_code.');
        }
        $body = ['country_code' => strtoupper($countryCode)];
        if ($firstLine  !== '') { $body['first_line']  = $firstLine; }
        if ($city       !== '') { $body['city']         = $city; }
        if ($postalCode !== '') { $body['postal_code']  = $postalCode; }

        $resp = $this->api_post('/customers/' . $customerid . '/addresses', $body);
        if (empty($resp['data']['id'])) {
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                'Paddle did not return an address_id. Response: ' . json_encode($resp));
        }
        return $resp['data']['id'];
    }

    /**
     * Create a Business linked to a customer (turns the transaction B2B).
     * Should ONLY be called when the buyer actually entered a company name
     * on the pre-checkout form — otherwise the invoice will incorrectly
     * label an individual's name as a "Business name".
     *
     * @param string $customerid Paddle customer_id.
     * @param string $name Legal business name.
     * @return string Paddle business_id ("biz_...")
     * @throws \moodle_exception
     */
    public function create_business(string $customerid, string $name): string {
        $name = trim($name);
        if ($name === '') {
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                'Cannot create a Paddle business without a name.');
        }
        $resp = $this->api_post('/customers/' . $customerid . '/businesses', ['name' => $name]);
        if (empty($resp['data']['id'])) {
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                'Paddle did not return a business_id. Response: ' . json_encode($resp));
        }
        return $resp['data']['id'];
    }

    /**
     * Convert a Moodle payment amount (float) to Paddle's minor unit (integer string).
     *
     * Most currencies use 2 decimal places (×100). A small set of zero-decimal
     * currencies (e.g. JPY, KRW) are passed as whole units.
     *
     * @param float $amount The payment amount from Moodle's payable (e.g. 9.99).
     * @param string $currency ISO 4217 currency code (e.g. 'AUD').
     * @return int Amount in the currency's lowest denomination.
     */
    public static function amount_to_minor_unit(float $amount, string $currency): int {
        $zerodecimal = ['JPY', 'KRW', 'HUF', 'TWD', 'UGX', 'VND', 'XAF', 'XOF', 'BIF', 'CLP', 'GNF', 'ISK', 'MGA', 'PYG', 'RWF'];
        if (in_array(strtoupper($currency), $zerodecimal)) {
            return (int)round($amount);
        }
        return (int)round($amount * 100);
    }

    /**
     * Verify Paddle webhook signature (Paddle-Signature header).
     *
     * Paddle signs webhooks with: ts=<timestamp>;h1=<hmac_sha256>
     * The signed payload is: ts + ":" + rawBody
     *
     * @param string $signatureheader The Paddle-Signature header value.
     * @param string $rawbody The raw request body.
     * @param string $secret The webhook signing secret.
     * @param int $maxage Maximum age in seconds (default 300 = 5 minutes).
     * @return bool True if signature is valid.
     */
    public static function verify_webhook_signature(
        string $signatureheader,
        string $rawbody,
        string $secret,
        int $maxage = 300
    ): bool {
        // Parse ts and h1 from header.
        $parts = [];
        foreach (explode(';', $signatureheader) as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }

        if (empty($parts['ts']) || empty($parts['h1'])) {
            return false;
        }

        $ts = (int)$parts['ts'];
        $h1 = $parts['h1'];

        // Reject if timestamp is too old (replay protection).
        if (abs(time() - $ts) > $maxage) {
            return false;
        }

        // Compute expected signature: HMAC-SHA256(secret, ts + ":" + rawBody).
        $signedpayload = $ts . ':' . $rawbody;
        $expected = hash_hmac('sha256', $signedpayload, $secret);

        return hash_equals($expected, $h1);
    }

    /**
     * Make a POST request to the Paddle Billing API.
     *
     * @param string $endpoint API endpoint path (e.g. '/transactions').
     * @param array $data Request body data.
     * @return array Decoded JSON response.
     * @throws \moodle_exception On HTTP error.
     */
    private function api_post(string $endpoint, array $data): array {
        $url = $this->baseurl . $endpoint;
        $jsonbody = json_encode($data);

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $this->apikey,
            'Content-Type: application/json',
        ]);

        $response = $curl->post($url, $jsonbody, [
            'CURLOPT_TIMEOUT' => 30,
        ]);

        $httpcode = $curl->get_info()['http_code'] ?? 0;
        $error = $curl->get_errno() ? $curl->error : '';

        if ($error) {
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                'cURL error: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpcode < 200 || $httpcode >= 300) {
            $errmsg = $decoded['error']['detail'] ?? $response;
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                "Paddle API error ({$httpcode}): {$errmsg}");
        }

        return $decoded ?: [];
    }

    /**
     * Make a GET request to the Paddle Billing API.
     *
     * @param string $endpoint API endpoint path with query string.
     * @return array Decoded JSON response.
     * @throws \moodle_exception
     */
    private function api_get(string $endpoint): array {
        $url = $this->baseurl . $endpoint;
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $this->apikey,
            'Content-Type: application/json',
        ]);
        $response = $curl->get($url, [], ['CURLOPT_TIMEOUT' => 30]);
        $httpcode = $curl->get_info()['http_code'] ?? 0;
        $error = $curl->get_errno() ? $curl->error : '';
        if ($error) {
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                'cURL error: ' . $error);
        }
        $decoded = json_decode($response, true);
        if ($httpcode < 200 || $httpcode >= 300) {
            $errmsg = $decoded['error']['detail'] ?? $response;
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                "Paddle API GET error ({$httpcode}): {$errmsg}");
        }
        return $decoded ?: [];
    }

    /**
     * Make a PATCH request to the Paddle Billing API.
     *
     * Moodle's curl wrapper does not expose PATCH directly, so we drop down
     * to CURLOPT_CUSTOMREQUEST.
     *
     * @param string $endpoint API endpoint path.
     * @param array $data Request body data.
     * @return array Decoded JSON response.
     * @throws \moodle_exception
     */
    private function api_patch(string $endpoint, array $data): array {
        $url = $this->baseurl . $endpoint;
        $jsonbody = json_encode($data);

        \core\session\manager::write_close();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonbody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apikey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                'cURL error: ' . $error);
        }

        $decoded = json_decode($response, true);
        if ($httpcode < 200 || $httpcode >= 300) {
            $errmsg = $decoded['error']['detail'] ?? $response;
            throw new \moodle_exception('apierror', 'paygw_paddle', '', null,
                "Paddle API PATCH error ({$httpcode}): {$errmsg}");
        }
        return $decoded ?: [];
    }
}
