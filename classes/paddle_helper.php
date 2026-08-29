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
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_paddle;

/**
 * Thin client for the Paddle Billing API, plus webhook signature verification.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class paddle_helper {
    /** @var int How long to wait for a Paddle API response, in seconds. */
    protected const TIMEOUT = 30;

    /**
     * Currencies Paddle expects as whole units rather than hundredths.
     *
     * @var string[]
     */
    protected const ZERO_DECIMAL_CURRENCIES = [
        'JPY', 'KRW', 'HUF', 'TWD', 'UGX', 'VND', 'XAF', 'XOF',
        'BIF', 'CLP', 'GNF', 'ISK', 'MGA', 'PYG', 'RWF',
    ];

    /** @var string Paddle API key, used server side only. */
    protected $apikey;

    /** @var string The API base URL for the configured environment. */
    protected $baseurl;

    /**
     * Constructor.
     *
     * @param string $apikey The Paddle API key.
     * @param string $environment Either 'live' or 'sandbox'.
     */
    public function __construct(string $apikey, string $environment = 'live') {
        $this->apikey = $apikey;
        $this->baseurl = ($environment === 'sandbox')
            ? 'https://sandbox-api.paddle.com'
            : 'https://api.paddle.com';
    }

    /**
     * Create a Paddle transaction for a one-off payment.
     *
     * Billing details are attached to a Paddle customer record before the
     * transaction is created, because the invoice takes its "bill to" name and
     * address from that record rather than from anything sent inline.
     *
     * @param \stdClass $config The gateway configuration for this payment account.
     * @param \core_payment\local\entities\payable $payable The Moodle payable.
     * @param string $description A human readable description of the purchase.
     * @param float $amount The amount to charge, including any surcharge.
     * @param string $component The Moodle payment component.
     * @param string $paymentarea The Moodle payment area.
     * @param int $itemid The Moodle item id.
     * @param int $userid The Moodle user paying.
     * @param array $customerdata Billing details collected on pay.php.
     * @return array [string $transactionid, string $checkouturl, string $customerid]
     * @throws \moodle_exception If Paddle rejects the request.
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

        // Note: the session is deliberately left open here. pay.php stores the
        // checkout details in $SESSION after this call returns, and closing the
        // session for writing first would silently discard them.
        $currency = $payable->get_currency();
        $items = $this->build_items(
            $config,
            $component,
            $paymentarea,
            $itemid,
            $description,
            $amount,
            $currency
        );

        $checkoutparams = [
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
        ];
        $defaultcheckouturl = new \moodle_url('/payment/gateway/paddle/checkout.php', $checkoutparams);
        $checkouturl = !empty($config->checkouturl)
            ? $config->checkouturl
            : $defaultcheckouturl->out(false);

        $email = trim((string) ($customerdata['email'] ?? ''));
        $fullname = trim((string) ($customerdata['fullname'] ?? ''));
        $addressline = trim((string) ($customerdata['address'] ?? ''));
        $city = trim((string) ($customerdata['city'] ?? ''));
        $postcode = trim((string) ($customerdata['postcode'] ?? ''));
        $companyname = trim((string) ($customerdata['companyname'] ?? ''));
        $country = strtoupper(trim((string) ($customerdata['country'] ?? '')));
        $countrycode = (strlen($country) === 2) ? $country : '';

        $customerid = '';
        $addressid = '';
        $businessid = '';

        // A failure at any of these three steps degrades the invoice but must
        // not stop the payer from checking out, so each is caught separately.
        if ($email !== '') {
            try {
                $customerid = $this->get_or_create_customer($email, $fullname);
            } catch (\Throwable $e) {
                debugging('paygw_paddle: customer create failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if ($customerid !== '' && $countrycode !== '') {
            try {
                $addressid = $this->create_address(
                    $customerid,
                    $addressline,
                    $city,
                    $postcode,
                    $countrycode
                );
            } catch (\Throwable $e) {
                debugging('paygw_paddle: address create failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Only create a business when the payer said they are buying for one.
        // Sending an individual's name as a business name produces a B2B
        // invoice with the wrong layout and the wrong tax treatment.
        if ($customerid !== '' && $companyname !== '') {
            try {
                $businessid = $this->create_business($customerid, $companyname);
            } catch (\Throwable $e) {
                debugging('paygw_paddle: business create failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $payload = [
            'items' => $items,
            'custom_data' => [
                'component' => $component,
                'paymentarea' => $paymentarea,
                'itemid' => (string) $itemid,
                'userid' => (string) $userid,
            ],
            'checkout' => ['url' => $checkouturl],
        ];

        if ($customerid !== '') {
            $payload['customer_id'] = $customerid;
        } else if ($email !== '') {
            $payload['customer'] = ['email' => $email];
        }
        if ($addressid !== '') {
            $payload['address_id'] = $addressid;
        }
        if ($businessid !== '') {
            $payload['business_id'] = $businessid;
        }

        $response = $this->api_call('POST', '/transactions', $payload);

        if (empty($response['data']['id'])) {
            throw new \moodle_exception(
                'apierror',
                'paygw_paddle',
                '',
                null,
                'Paddle returned no transaction id.'
            );
        }

        $txnid = $response['data']['id'];

        $txncheckouturl = $response['data']['checkout']['url'] ?? '';
        if (empty($txncheckouturl)) {
            $separator = (strpos($checkouturl, '?') !== false) ? '&' : '?';
            $txncheckouturl = $checkouturl . $separator . '_ptxn=' . urlencode($txnid);
        }

        $record = new \stdClass();
        $record->component = $component;
        $record->paymentarea = $paymentarea;
        $record->itemid = $itemid;
        $record->paddle_transaction_id = $txnid;
        $record->paddle_customer_id = $response['data']['customer_id'] ?? $customerid;
        $record->userid = $userid;
        $record->amount = $amount;
        $record->currency = $currency;
        $record->tax = 0;
        $record->status = 'pending';
        $record->timecreated = time();
        $record->timemodified = time();
        $DB->insert_record('paygw_paddle_transactions', $record);

        return [$txnid, $txncheckouturl, $customerid];
    }

    /**
     * Work out what to put in the transaction's items array.
     *
     * A course specific price mapping wins, then the account's default catalog
     * price, and failing both an inline price built from the cost Moodle holds.
     *
     * @param \stdClass $config The gateway configuration.
     * @param string $component The Moodle payment component.
     * @param string $paymentarea The Moodle payment area.
     * @param int $itemid The Moodle item id.
     * @param string $description A human readable description.
     * @param float $amount The amount to charge.
     * @param string $currency The ISO 4217 currency code.
     * @return array The items array for the Paddle API.
     * @throws \moodle_exception If neither a price nor a product is configured.
     */
    protected function build_items(
        \stdClass $config,
        string $component,
        string $paymentarea,
        int $itemid,
        string $description,
        float $amount,
        string $currency
    ): array {
        global $DB;

        $priceid = '';

        if ($component === 'enrol_fee' && $paymentarea === 'fee') {
            $enrolinstance = $DB->get_record('enrol', ['id' => $itemid], 'courseid');
            if ($enrolinstance) {
                $pricemap = $DB->get_record(
                    'paygw_paddle_prices',
                    [
                        'courseid' => $enrolinstance->courseid,
                        'active' => 1,
                    ]
                );
                if ($pricemap && !empty($pricemap->paddle_price_id)) {
                    $priceid = $pricemap->paddle_price_id;
                }
            }
        }

        if ($priceid === '') {
            $priceid = (string) ($config->defaultpriceid ?? '');
        }

        if ($priceid !== '') {
            return [['price_id' => $priceid, 'quantity' => 1]];
        }

        $productid = (string) ($config->defaultproductid ?? '');
        if ($productid === '') {
            throw new \moodle_exception('missingpriceid', 'paygw_paddle');
        }

        return [[
            'price' => [
                'description' => $description,
                'product_id' => $productid,
                'unit_price' => [
                    'amount' => (string) self::amount_to_minor_unit($amount, $currency),
                    'currency_code' => $currency,
                ],
                'quantity' => ['minimum' => 1, 'maximum' => 1],
                'tax_mode' => 'account_setting',
            ],
            'quantity' => 1,
        ]];
    }

    /**
     * Find a Paddle customer by email, or create one.
     *
     * Paddle's list endpoint has no exact email filter, so results from its
     * full text search are matched on the email address here. Matching loosely
     * would link a payment to somebody else's customer record.
     *
     * @param string $email The payer's email address.
     * @param string $name The payer's full name, shown on the invoice.
     * @return string The Paddle customer id.
     * @throws \moodle_exception If Paddle rejects the request.
     */
    public function get_or_create_customer(string $email, string $name): string {
        $email = trim($email);
        $name = trim($name);

        if ($email === '') {
            throw new \moodle_exception(
                'apierror',
                'paygw_paddle',
                '',
                null,
                'Cannot create a Paddle customer without an email address.'
            );
        }

        $list = $this->api_call('GET', '/customers?search=' . urlencode($email) . '&per_page=10');

        $existing = null;
        $wanted = \core_text::strtolower($email);
        foreach ($list['data'] ?? [] as $candidate) {
            if (isset($candidate['email'])
                    && \core_text::strtolower(trim($candidate['email'])) === $wanted) {
                $existing = $candidate;
                break;
            }
        }

        if ($existing && !empty($existing['id'])) {
            $customerid = $existing['id'];
            if ($name !== '' && (empty($existing['name']) || $existing['name'] !== $name)) {
                try {
                    $this->api_call('PATCH', '/customers/' . $customerid, ['name' => $name]);
                } catch (\Throwable $e) {
                    debugging(
                        'paygw_paddle: customer name update failed: ' . $e->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
            return $customerid;
        }

        $body = ['email' => $email];
        if ($name !== '') {
            $body['name'] = $name;
        }

        $response = $this->api_call('POST', '/customers', $body);
        if (empty($response['data']['id'])) {
            throw new \moodle_exception(
                'apierror',
                'paygw_paddle',
                '',
                null,
                'Paddle returned no customer id.'
            );
        }

        return $response['data']['id'];
    }

    /**
     * Attach a postal address to a Paddle customer.
     *
     * @param string $customerid The Paddle customer id.
     * @param string $firstline Street address line 1.
     * @param string $city Suburb or city.
     * @param string $postalcode Postcode or ZIP.
     * @param string $countrycode Two letter ISO 3166-1 country code.
     * @return string The Paddle address id.
     * @throws \moodle_exception If Paddle rejects the request.
     */
    public function create_address(
        string $customerid,
        string $firstline,
        string $city,
        string $postalcode,
        string $countrycode
    ): string {
        if (strlen($countrycode) !== 2) {
            throw new \moodle_exception(
                'apierror',
                'paygw_paddle',
                '',
                null,
                'Cannot create a Paddle address without a two letter country code.'
            );
        }

        $body = ['country_code' => strtoupper($countrycode)];
        if ($firstline !== '') {
            $body['first_line'] = $firstline;
        }
        if ($city !== '') {
            $body['city'] = $city;
        }
        if ($postalcode !== '') {
            $body['postal_code'] = $postalcode;
        }

        $response = $this->api_call('POST', '/customers/' . $customerid . '/addresses', $body);
        if (empty($response['data']['id'])) {
            throw new \moodle_exception(
                'apierror',
                'paygw_paddle',
                '',
                null,
                'Paddle returned no address id.'
            );
        }

        return $response['data']['id'];
    }

    /**
     * Attach a business to a Paddle customer, making the transaction B2B.
     *
     * @param string $customerid The Paddle customer id.
     * @param string $name The legal business name.
     * @return string The Paddle business id.
     * @throws \moodle_exception If Paddle rejects the request.
     */
    public function create_business(string $customerid, string $name): string {
        $name = trim($name);
        if ($name === '') {
            throw new \moodle_exception(
                'apierror',
                'paygw_paddle',
                '',
                null,
                'Cannot create a Paddle business without a name.'
            );
        }

        $response = $this->api_call(
            'POST',
            '/customers/' . $customerid . '/businesses',
            ['name' => $name]
        );
        if (empty($response['data']['id'])) {
            throw new \moodle_exception(
                'apierror',
                'paygw_paddle',
                '',
                null,
                'Paddle returned no business id.'
            );
        }

        return $response['data']['id'];
    }

    /**
     * Convert an amount to the minor unit Paddle expects.
     *
     * Most currencies are sent as hundredths; a handful have no minor unit and
     * are sent whole.
     *
     * @param float $amount The amount, for example 9.99.
     * @param string $currency The ISO 4217 currency code.
     * @return int The amount in the currency's smallest unit.
     */
    public static function amount_to_minor_unit(float $amount, string $currency): int {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }

    /**
     * Verify a Paddle webhook signature.
     *
     * Paddle sends a header of the form ts=<timestamp>;h1=<hmac>, where the
     * signed payload is the timestamp, a colon, and the raw request body.
     *
     * @param string $signatureheader The Paddle-Signature header value.
     * @param string $rawbody The raw request body.
     * @param string $secret The webhook signing secret.
     * @param int $maxage How old a signature may be, in seconds.
     * @return bool True when the signature is valid and recent.
     */
    public static function verify_webhook_signature(
        string $signatureheader,
        string $rawbody,
        string $secret,
        int $maxage = 300
    ): bool {
        if ($secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(';', $signatureheader) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $parts[trim($pair[0])] = trim($pair[1]);
            }
        }

        if (empty($parts['ts']) || empty($parts['h1'])) {
            return false;
        }

        if (!ctype_digit((string) $parts['ts'])) {
            return false;
        }

        $timestamp = (int) $parts['ts'];

        // Reject signatures outside the accepted window, so a captured request
        // cannot be replayed later.
        if (abs(time() - $timestamp) > $maxage) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . ':' . $rawbody, $secret);

        return hash_equals($expected, (string) $parts['h1']);
    }

    /**
     * Call the Paddle Billing API.
     *
     * Uses Moodle's curl wrapper so proxy configuration and site security
     * settings apply. The wrapper has no native PATCH, so that verb is set
     * through CURLOPT_CUSTOMREQUEST on a POST.
     *
     * @param string $method One of GET, POST or PATCH.
     * @param string $endpoint The API path, including any query string.
     * @param array|null $data The request body, for POST and PATCH.
     * @return array The decoded response.
     * @throws \moodle_exception On a transport error or a non 2xx response.
     */
    protected function api_call(string $method, string $endpoint, ?array $data = null): array {
        $url = $this->baseurl . $endpoint;

        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $this->apikey,
            'Content-Type: application/json',
        ]);

        $options = ['CURLOPT_TIMEOUT' => self::TIMEOUT];

        switch ($method) {
            case 'GET':
                $response = $curl->get($url, [], $options);
                break;

            case 'PATCH':
                $options['CURLOPT_CUSTOMREQUEST'] = 'PATCH';
                $response = $curl->post($url, json_encode($data), $options);
                break;

            case 'POST':
            default:
                $response = $curl->post($url, json_encode($data), $options);
                break;
        }

        if ($curl->get_errno()) {
            throw new \moodle_exception(
                'apierror',
                'paygw_paddle',
                '',
                null,
                'Transport error calling Paddle: ' . $curl->error
            );
        }

        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        $decoded = json_decode($response, true);

        if ($httpcode < 200 || $httpcode >= 300) {
            $detail = $decoded['error']['detail'] ?? ('HTTP ' . $httpcode);
            throw new \moodle_exception(
                'apierror',
                'paygw_paddle',
                '',
                null,
                "Paddle API {$method} {$endpoint} failed ({$httpcode}): {$detail}"
            );
        }

        return is_array($decoded) ? $decoded : [];
    }
}
