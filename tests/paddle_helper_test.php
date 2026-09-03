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
 * Unit tests for the Paddle API helper.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @author     AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_paddle;

/**
 * Tests for signature verification and currency conversion.
 *
 * These are the two pure functions in the plugin that handle authentication
 * and money, so a regression in either is expensive and silent.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \paygw_paddle\paddle_helper
 */
final class paddle_helper_test extends \advanced_testcase {
    /** @var string A stand-in webhook signing secret. */
    private const SECRET = 'pdl_ntfset_01testsecretvalue';

    /**
     * Build a valid Paddle-Signature header for a body.
     *
     * @param string $body The raw request body.
     * @param string $secret The signing secret.
     * @param int|null $timestamp The timestamp to sign with, or null for now.
     * @return string The header value.
     */
    private function sign(string $body, string $secret = self::SECRET, ?int $timestamp = null): string {
        $timestamp = $timestamp ?? time();
        $hash = hash_hmac('sha256', $timestamp . ':' . $body, $secret);
        return "ts={$timestamp};h1={$hash}";
    }

    /**
     * A correctly signed, recent notification is accepted.
     *
     * @return void
     */
    public function test_valid_signature_is_accepted(): void {
        $body = '{"event_id":"evt_01","event_type":"transaction.completed"}';

        $verified = paddle_helper::verify_webhook_signature($this->sign($body), $body, self::SECRET);

        $this->assertTrue($verified);
    }

    /**
     * A body altered after signing is rejected.
     *
     * @return void
     */
    public function test_tampered_body_is_rejected(): void {
        $body = '{"event_id":"evt_01","event_type":"transaction.completed"}';
        $header = $this->sign($body);
        $tampered = '{"event_id":"evt_01","event_type":"transaction.cancelled"}';

        $verified = paddle_helper::verify_webhook_signature($header, $tampered, self::SECRET);

        $this->assertFalse($verified);
    }

    /**
     * A signature made with a different secret is rejected.
     *
     * @return void
     */
    public function test_wrong_secret_is_rejected(): void {
        $body = '{"event_id":"evt_01"}';

        $verified = paddle_helper::verify_webhook_signature($this->sign($body, 'some_other_secret'), $body, self::SECRET);

        $this->assertFalse($verified);
    }

    /**
     * An empty configured secret never verifies.
     *
     * @return void
     */
    public function test_empty_secret_is_rejected(): void {
        $body = '{"event_id":"evt_01"}';

        $verified = paddle_helper::verify_webhook_signature($this->sign($body, ''), $body, '');

        $this->assertFalse($verified);
    }

    /**
     * A signature older than the replay window is rejected.
     *
     * @return void
     */
    public function test_expired_signature_is_rejected(): void {
        $body = '{"event_id":"evt_01"}';
        $old = time() - 600;

        $verified = paddle_helper::verify_webhook_signature($this->sign($body, self::SECRET, $old), $body, self::SECRET);

        $this->assertFalse($verified);
    }

    /**
     * A signature timestamped in the future is rejected too.
     *
     * @return void
     */
    public function test_future_signature_is_rejected(): void {
        $body = '{"event_id":"evt_01"}';
        $future = time() + 600;

        $verified = paddle_helper::verify_webhook_signature($this->sign($body, self::SECRET, $future), $body, self::SECRET);

        $this->assertFalse($verified);
    }

    /**
     * Headers that are missing parts, malformed, or empty are all rejected.
     *
     * @param string $header The header value to try.
     * @return void
     * @dataProvider malformed_header_provider
     */
    public function test_malformed_headers_are_rejected(string $header): void {
        $verified = paddle_helper::verify_webhook_signature($header, '{"event_id":"evt_01"}', self::SECRET);

        $this->assertFalse($verified);
    }

    /**
     * Malformed Paddle-Signature header values.
     *
     * @return array[]
     */
    public static function malformed_header_provider(): array {
        return [
            'empty' => [''],
            'no separator' => ['nonsense'],
            'timestamp only' => ['ts=' . 1700000000],
            'hash only' => ['h1=abc123'],
            'non numeric timestamp' => ['ts=notanumber;h1=abc123'],
            'empty values' => ['ts=;h1='],
        ];
    }

    /**
     * Two decimal currencies are converted to hundredths.
     *
     * @return void
     */
    public function test_minor_unit_for_two_decimal_currencies(): void {
        $this->assertSame(999, paddle_helper::amount_to_minor_unit(9.99, 'AUD'));
        $this->assertSame(10000, paddle_helper::amount_to_minor_unit(100.00, 'USD'));
        $this->assertSame(1, paddle_helper::amount_to_minor_unit(0.01, 'GBP'));
        $this->assertSame(0, paddle_helper::amount_to_minor_unit(0.0, 'EUR'));
    }

    /**
     * Zero decimal currencies are sent as whole units.
     *
     * @return void
     */
    public function test_minor_unit_for_zero_decimal_currencies(): void {
        $this->assertSame(1500, paddle_helper::amount_to_minor_unit(1500.0, 'JPY'));
        $this->assertSame(25000, paddle_helper::amount_to_minor_unit(25000.0, 'KRW'));
        $this->assertSame(990, paddle_helper::amount_to_minor_unit(990.0, 'CLP'));
    }

    /**
     * The currency code is matched regardless of case.
     *
     * @return void
     */
    public function test_minor_unit_currency_is_case_insensitive(): void {
        $this->assertSame(
            paddle_helper::amount_to_minor_unit(1500.0, 'JPY'),
            paddle_helper::amount_to_minor_unit(1500.0, 'jpy')
        );
    }

    /**
     * Floating point amounts round rather than truncate.
     *
     * Without rounding, 19.99 * 100 evaluates to 1998.9999... and a naive cast
     * would undercharge by a cent.
     *
     * @return void
     */
    public function test_minor_unit_rounds_floating_point_amounts(): void {
        $this->assertSame(1999, paddle_helper::amount_to_minor_unit(19.99, 'AUD'));
        $this->assertSame(2999, paddle_helper::amount_to_minor_unit(29.99, 'AUD'));
        $this->assertSame(7010, paddle_helper::amount_to_minor_unit(70.10, 'AUD'));
    }

    /**
     * Amounts convert back out of the minor unit.
     *
     * @return void
     */
    public function test_amount_from_minor_unit_is_the_inverse(): void {
        $this->assertSame(9.99, paddle_helper::amount_from_minor_unit(999, 'AUD'));
        $this->assertSame(100.0, paddle_helper::amount_from_minor_unit(10000, 'USD'));
        $this->assertSame(0.0, paddle_helper::amount_from_minor_unit(0, 'EUR'));

        // Zero decimal currencies are reported whole, not in hundredths.
        $this->assertSame(1500.0, paddle_helper::amount_from_minor_unit(1500, 'JPY'));
        $this->assertSame(1500.0, paddle_helper::amount_from_minor_unit(1500, 'jpy'));

        // Paddle sends these as strings.
        $this->assertSame(9.99, paddle_helper::amount_from_minor_unit('999', 'AUD'));
    }
}
