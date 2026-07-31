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
 * Upgrade steps for the Paddle payment gateway plugin.
 *
 * @package    paygw_paddle
 * @copyright  2026 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_paygw_paddle_upgrade($oldversion) {

    // v1.0.14: DOCS: Common Price ID mistakes callout added to the documentation page.
    // Explains three common misconfigurations: (1) entering the Price ID in a global settings
    // page instead of the per-account gateway form, (2) confusing pro_... (Product ID) with
    // pri_... (Price ID), (3) a course-level price mapping silently overriding the default.
    // No DB schema changes. version.php → 2026033014.
    if ($oldversion < 2026033014) {
        upgrade_plugin_savepoint(true, 2026033014, 'paygw', 'paddle');
    }

    // v1.0.15: FIX: admin_externalpage_setup() removed from admin/pricemap.php and admin/reports.php.
    // Both pages called admin_externalpage_setup() with page IDs ('paygw_paddle_pricemap',
    // 'paygw_paddle_reports') that were never registered in the admin tree — settings.php correctly
    // returns $settings = null for paygw plugins. Moodle threw an error when it could not resolve
    // the unregistered page name, causing both URLs to fail. Fixed by replacing
    // admin_externalpage_setup() with manual page setup: require_login(), require_capability(),
    // $PAGE->set_url/context/pagelayout/title/heading. Direct URL access now works correctly.
    // No DB schema changes. version.php → 2026033015.
    if ($oldversion < 2026033015) {
        upgrade_plugin_savepoint(true, 2026033015, 'paygw', 'paddle');
    }

    // v1.0.16: AMD SYNC FIX — amd/build/gateways_modal.min.js was stale (MD5
    //   f8146dba835e79a06820b1efb1f75319) while amd/src/gateways_modal.js and
    //   amd/build/gateways_modal.js had matching MD5 c4e8afba0f6e477d5bc95d3216f075b2.
    //   Root cause: a previous release updated src and build/.js but omitted the .min.js copy.
    //   Moodle in production mode loads amd/build/MODULENAME.min.js, so production sites were
    //   serving an older version of the payment gateway modal JS. Fix: amd/build/gateways_modal.min.js
    //   resynced to src. src=build=min triple-match MD5: c4e8afba0f6e477d5bc95d3216f075b2.
    //   No DB schema changes. No PHP changes. version.php → 2026040416.
    if ($oldversion < 2026040416) {
        upgrade_plugin_savepoint(true, 2026040416, 'paygw', 'paddle');
    }

    // v1.0.17: BUG FIX — checkout.php AMD conflict + S3 KeyTooLongError.
    //   Root cause: checkout.php used $PAGE->requires->js_init_code($js) to inject the
    //   Paddle init code. js_init_code wraps code in require([],function(){...}) — Moodle's
    //   AMD system. Inside the wrapper, a loadScript() call dynamically appended paddle.js
    //   to the <head>. When paddle.js loaded it called AMD's define() internally. RequireJS
    //   was already active and threw "Mismatched anonymous define() module", preventing
    //   paddle.js from setting the Paddle global → "Paddle is not defined" → checkout never
    //   opened. Separately, js_init_code feeds the JS blob through Moodle's JS caching
    //   pipeline; URL-encoding the ~1500-char blob generates a key >1024 chars, causing
    //   an S3 KeyTooLongError (size 3661, max 1024) returned as XML to the browser.
    //   Fix: removed js_init_code() and loadScript() entirely. Paddle.js is now loaded as
    //   a plain synchronous <script src="...paddle.js"> tag echoed directly into the HTML
    //   body. The init <script> block immediately follows — Paddle global is guaranteed
    //   defined. No AMD involvement, no JS pipeline, no long keys. No DB schema changes.
    //   version.php → 2026041617.
    if ($oldversion < 2026041617) {
        upgrade_plugin_savepoint(true, 2026041617, 'paygw', 'paddle');
    }

    // v1.0.18: BUG FIX — "Paddle is not defined" persists after v1.0.17 due to remaining
    //   RequireJS UMD intercept. Paddle.js ships with a UMD wrapper: if window.define is a
    //   function, it calls define() rather than setting window.Paddle. Moodle's require.min.js
    //   is already active on the page (loaded by $OUTPUT->header()), so even a plain
    //   synchronous <script src="...paddle.js"> triggers the "Mismatched anonymous define()
    //   module" error — RequireJS intercepts the call, throws, and Paddle.js never reaches
    //   the window.Paddle = Paddle assignment. Fix: null out window.define and window.require
    //   in an inline <script> immediately before the Paddle.js <script> tag; restore both
    //   in an inline <script> immediately after. Paddle's UMD wrapper then sees no AMD
    //   loader and falls through to the plain global assignment. No DB schema changes.
    //   PHP only (checkout.php). version.php → 2026041700618.
    if ($oldversion < 2026041700618) {
        upgrade_plugin_savepoint(true, 2026041700618, 'paygw', 'paddle');
    }

    // v1.0.19: FEAT-PADDLE-CUSTOMER-PREFILL — pass Moodle user profile data to Paddle
    //   transaction API so checkout is pre-filled and invoices include complete customer
    //   details. Fields added: customer.email, address.first_line (from $USER->address),
    //   address.city, address.country_code (from $USER->country), business.name
    //   (from firstname + lastname). All fields are conditional on non-empty values.
    //   No DB schema changes. PHP only: classes/paddle_helper.php.
    //   version.php → 2026042900619.
    if ($oldversion < 2026042900619) {
        upgrade_plugin_savepoint(true, 2026042900619, 'paygw', 'paddle');
    }

    // v1.0.20: FEAT-PADDLE-BILLING-FORM — Moodle-side pre-checkout billing details form
    //   added to pay.php. Paddle's hosted checkout only collects email, country, and
    //   postcode; students can now confirm or enter full name, address line 1, and city
    //   before Paddle opens. Fields are pre-filled from the Moodle user profile.
    //   On POST (sesskey-verified), submitted values are passed to create_transaction()
    //   via a new optional $customerdata array (business.name, address.first_line,
    //   address.city). New lang strings added. No DB schema changes.
    //   PHP: pay.php, classes/paddle_helper.php, lang/en/paygw_paddle.php.
    //   version.php → 2026042900620.
    if ($oldversion < 2026042900620) {
        upgrade_plugin_savepoint(true, 2026042900620, 'paygw', 'paddle');
    }

    // v1.0.22: FIX-PADDLE-INVOICE-NAME — Buyer name now appears on Paddle invoices.
    //   Switched from sending the individual's full name in business.name (which
    //   produced a B2B invoice with the person labelled as a company and never
    //   populated customer.name) to the documented Paddle pattern: POST /customers
    //   with name+email → POST /addresses → POST /transactions referencing
    //   customer_id + address_id. checkout.php now passes customer.id to
    //   Paddle.Checkout.open() so the overlay resolves the pre-created customer
    //   record (with name) and the invoice "Bill to" block renders the buyer's
    //   actual name. Optional Company Name field added to the pre-checkout form;
    //   Paddle Business is created (and forwarded as business_id) only when the
    //   buyer explicitly typed a company name. New api_get() and api_patch()
    //   helpers added (Moodle's curl wrapper has no native PATCH).
    //   PHP: classes/paddle_helper.php, pay.php, checkout.php,
    //   lang/en/paygw_paddle.php. No DB schema changes.
    //   version.php → 2026050100622.
    if ($oldversion < 2026050100622) {
        upgrade_plugin_savepoint(true, 2026050100622, 'paygw', 'paddle');
    }

    // FIX-PADDLE-CUSTOMER-EMAIL-LOOKUP (v1.0.23 + v1.0.24): v1.0.23 bumped
    // version.php numeric without a savepoint — added here retroactively.
    // v1.0.24 fixes get_or_create_customer() using invalid email[] filter:
    // Paddle silently ignored it and returned the most-recently-created
    // customer regardless of email, causing cross-customer name/ID mixups.
    // Fix: use search= parameter + exact case-insensitive email match in
    // returned results. No DB schema changes.
    // version.php → 2026050600624.
    if ($oldversion < 2026050600624) {
        upgrade_plugin_savepoint(true, 2026050600624, 'paygw', 'paddle');
    }

    // FIX-PADDLE-REQUIRED-FIELDS (v1.0.25): Address line 1, city/suburb, and
    // postcode fields on the pre-checkout billing form are now required — they
    // were previously optional, allowing students to skip them and omit their
    // address from the generated invoice. Red asterisks added to labels.
    // PHP only: pay.php. No DB schema changes.
    // version.php → 2026050700625.
    if ($oldversion < 2026050700625) {
        upgrade_plugin_savepoint(true, 2026050700625, 'paygw', 'paddle');
    }

    // v1.0.27: FIX-CURL-BATCH — paddle_helper.php switched from raw curl_init() to Moodle
    //   \curl wrapper + write_close() for GET/POST calls (PATCH retains raw curl).
    //   No DB schema changes.
    if ($oldversion < 2026051200627) {
        upgrade_plugin_savepoint(true, 2026051200627, 'paygw', 'paddle');
    }

    // v1.0.29: SAVEPOINT-BUMP — no-op marker for clean upgrade path. No DB schema changes.
    if ($oldversion < 2026060400629) {
        upgrade_plugin_savepoint(true, 2026060400629, 'paygw', 'paddle');
    }

    // v1.0.30: FIX-COUNTRY-REQUIRED — three-part fix for "Something went wrong" in Paddle
    //   checkout overlay for students whose Moodle profile has no country set.
    //   (1) pay.php: reads paddle_country from POST (new field shown when profile country
    //       is blank); always passes a non-empty countryCode into create_transaction().
    //   (2) checkout.php: guards customer.address in Paddle.Checkout.open() — if countryCode
    //       is missing, the address object is removed entirely so Paddle collects it in its
    //       overlay rather than receiving a partial (no-countryCode) address that causes the
    //       "Something went wrong" overlay error.
    //   (3) lang/en/paygw_paddle.php: added 'country' and 'country_placeholder' strings.
    //   No DB schema changes.
    if ($oldversion < 2026072100630) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['pay.php', 'checkout.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026072100630, 'paygw', 'paddle');
    }

    // v1.0.31 — FIX-CUSTOMER-ID-ADDRESS-CONFLICT: checkout.php was passing both
    // customer.id AND a raw customer.address object to Paddle.Checkout.open().
    // Paddle rejects this combination and shows "Something went wrong". Fix: when
    // customer.id is set, omit customer.address entirely — the address is already
    // attached server-side via address_id on the transaction.
    // No DB schema changes.
    if ($oldversion < 2026072200631) {
        if (function_exists('opcache_invalidate')) {
            $_pluginDir = realpath(__DIR__ . '/..');
            foreach (['checkout.php', 'version.php', 'db/upgrade.php'] as $_f) {
                $_full = $_pluginDir . '/' . $_f;
                if (file_exists($_full)) {
                    opcache_invalidate($_full, true);
                }
            }
        } elseif (function_exists('opcache_reset')) {
            opcache_reset();
        }
        upgrade_plugin_savepoint(true, 2026072200631, 'paygw', 'paddle');
    }

    return true;
}